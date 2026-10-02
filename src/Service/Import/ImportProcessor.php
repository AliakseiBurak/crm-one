<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\CallData;
use App\Dto\ContactData;
use App\Dto\ImportChunk;
use App\Dto\ImportRow;
use App\Dto\ImportRowCall;
use App\Dto\ImportRowContact;
use App\Dto\OrganizationData;
use App\Entity\Call;
use App\Entity\Contact;
use App\Entity\ImportRun;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\OrganizationRepository;
use App\Service\Import\Exception\ImportRowConflict;
use App\Service\Import\Exception\ImportRowStopped;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Построчная обработка прогона импорта (design D4, D7, D9, D10).
 *
 * Пакет не более 20 организаций — это только порция для проверки, а не граница
 * транзакции: каждая строка сохраняется собственной транзакцией. Откат всего
 * пакета выбросил бы строки, сохранившиеся без проблем.
 *
 * Позиция пакета **вычисляется** из прогресса прогона, а не передаётся
 * вызывающим кодом: строки `processedRows + 1 … min(processedRows + 20,
 * totalRows)`. Из этого бесплатно следует идемпотентность формы — повторно
 * отправленная форма сохраняет ровно то, что ещё не сохранено, потому что всё,
 * что не выше счётчика, пропускается.
 */
final class ImportProcessor
{
    /**
     * Размер пакета: столько организаций администратор проверяет за раз.
     */
    public const int CHUNK_SIZE = 20;

    private const int MAX_ORGANIZATION_NAME = 255;

    private const int MAX_ORGANIZATION_FIELD = 255;

    private const int MAX_ORGANIZATION_UNP = 32;

    private const int MAX_CONTACT_PHONE = 32;

    public function __construct(
        private readonly ImportRunReader $reader,
        private readonly CsvRowMapper $mapper,
        private readonly InteractionDateParser $dates,
        private readonly ImportFileStorage $storage,
        private readonly OrganizationRepository $organizations,
        private readonly ContactRepository $contacts,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Строки пакета, вычисленные из прогресса самого прогона (design D2).
     *
     * Строки без данных организации и контактов не показываются: они
     * пропускаются сразу при открытии страницы пакета, а не после нажатия
     * «Импортировать», и о каждой сообщается уведомлением. При этом из
     * `$chunk->rows` они не убираются — счётчик прогресса обязан перешагнуть
     * каждую строку файла по порядку, иначе импорт встанет на первой же из них.
     * Показывается только `visibleRows()`.
     *
     * Отрасль, город, УНП и годовой план сюда не попадают для прогона из
     * выгрузки: формат источника их не объявляет, показывать нечего. Для
     * прогона из ответа JSON они приходят из ответа и показываются — см. design
     * D7 изменения add-organizations-json-import.
     */
    public function processChunk(ImportRun $run): ImportChunk
    {
        $first = $run->processedRows;
        $last = min($first + self::CHUNK_SIZE, $run->totalRows);

        $rows = [];
        foreach ($this->reader->records($run->sourceFormat, $this->storage->path($run->storageKey)) as $index => $record) {
            if ($index < $first) {
                continue;
            }
            if ($index >= $last) {
                break;
            }
            // Нумерация строк в файле — с единицы: `processedRows + 1` это
            // первая необработанная строка.
            $rows[] = $this->rowNumbered($record, $index + 1);
        }

        $skipped = [];
        foreach ($rows as $row) {
            if ($row->isEmptyRecord()) {
                $skipped[] = $row->rowNumber;
            }
        }

        return new ImportChunk($rows, $skipped);
    }

    /**
     * Сохраняет строки пакета, начиная с `processedRows + 1`.
     *
     * Строка, уже учтённая счётчиком, молча пропускается: повторная отправка
     * той же формы не создаёт ни одной организации и не пропускает ни одной
     * строки источника. Строка без названия останавливает обработку так же,
     * как отказ базы: она не сохраняется, счётчик не двигается, а следующие
     * строки не сохраняются, пока название не заполнено.
     *
     * Исключение — запись, в которой нет вообще ничего: ни названия, ни
     * контактов, ни звонков. Исправлять её нечем, а нерешённая строка была бы
     * первой в каждом пакете и заблокировала импорт навсегда, поэтому она
     * пропускается, а `processedRows` продвигается. Пропуски возвращаются
     * отдельным списком, чтобы контроллер показал их отдельным уведомлением.
     *
     * @param ImportRow[]        $rows
     * @param array<int, string> $resolutions выбор на остановившейся строке:
     *                                        'merge' или 'create'
     *
     * @throws ImportRowConflict остановившаяся строка уже существует в БД
     * @throws ImportRowStopped  остановившаяся строка несохранима
     */
    public function persistRows(ImportRun $run, array $rows, array $resolutions = []): PersistResult
    {
        $saved = 0;
        $skipped = [];

        foreach ($rows as $row) {
            if ($row->rowNumber <= $run->processedRows) {
                // Строка уже сохранена этим же прогоном: повторно её не вставляем.
                continue;
            }

            if ($row->isEmptyRecord()) {
                $this->em->wrapInTransaction(static fn() => $run->markRowProcessed());
                $skipped[] = $row->rowNumber;

                continue;
            }

            $this->persistRow($run, $row, $resolutions[$row->rowNumber] ?? null);
            ++$saved;
        }

        return new PersistResult($saved, $skipped);
    }

    /**
     * Одна строка — одна транзакция (design D7).
     */
    private function persistRow(ImportRun $run, ImportRow $row, ?string $resolution): void
    {
        $this->assertSavable($row);

        $organization = match ($resolution) {
            // Слияние дополняет существующую карточку её контактами и
            // звонками, не трогая её полей.
            'merge' => $this->conflictedOrganization($row),
            // Создать новую: совпадение названий допускается.
            'create' => null,
            // Выбора нет.
            default => null,
        };

        if (null === $resolution) {
            // Проверка на вставке, а не при показе пакета, — так ловится и
            // ранее существовавшая организация, и дубликат внутри того же
            // файла, то есть строка, уже вставленная этим прогоном.
            $organization = $this->assertNoConflict($row);
        }

        $before = $run->processedRows;
        $creator = $run->createdBy;

        try {
            $this->em->wrapInTransaction(function () use ($run, $row, $organization, $creator): void {
                $target = $organization;
                if (null === $target) {
                    $target = $this->newOrganization($row, $creator);
                    // Каскада от контактов и звонков к организации нет, поэтому
                    // новую карточку сохраняем явно.
                    $this->em->persist($target);
                }

                foreach ($this->savableContacts($row) as $contact) {
                    $saved = $this->newContact($target, $contact);
                    $this->em->persist($saved);

                    if ($contact->isMain) {
                        // Основной контакт в организации один: снимаем отметку с
                        // остальных. Импорт пишет контакты мимо ContactController,
                        // поэтому правило вызывается здесь (см. ContactRepository).
                        $this->contacts->resetIsMainForOrganization($target, $saved);
                    }
                }
                foreach ($this->savableCalls($row) as $call) {
                    $this->em->persist($this->newCall($target, $call, $creator));
                }

                $run->markRowProcessed();
            });
        } catch (\Throwable $e) {
            // Откат транзакции не возвращает состояние объекта в памяти, а
            // прогон остаётся тем же объектом в этом же запросе: без возврата
            // счётчика следующая отрисовка пакета сдвинула бы его на строку.
            $run->setProcessedRows($before);

            if ($e instanceof ImportRowStopped) {
                throw $e;
            }

            throw new ImportRowStopped($row->rowNumber, $e->getMessage());
        }
    }

    /**
     * Проверки, которые иначе поймала бы только база. Здесь они дают то же
     * самое — остановку на строке с названным номером — но одинаково на любой
     * СУБД и с понятным администратору текстом.
     */
    private function assertSavable(ImportRow $row): void
    {
        $name = trim($row->name);
        if ('' === $name) {
            throw new ImportRowStopped($row->rowNumber, 'название организации не заполнено');
        }
        if (mb_strlen($name) > self::MAX_ORGANIZATION_NAME) {
            throw new ImportRowStopped(
                $row->rowNumber,
                \sprintf('название длиннее %d символов', self::MAX_ORGANIZATION_NAME),
            );
        }
        foreach ([
            'industry' => 'отрасль',
            'city' => 'город',
            'description' => 'описание',
            'coursesAttended' => '«Учились у нас»',
            'website' => 'сайт',
            'annualPlan' => 'годовой план',
        ] as $field => $label) {
            $value = $row->{$field};
            if (null !== $value && mb_strlen($value) > self::MAX_ORGANIZATION_FIELD) {
                throw new ImportRowStopped(
                    $row->rowNumber,
                    \sprintf('%s длиннее %d символов', $label, self::MAX_ORGANIZATION_FIELD),
                );
            }
        }
        if (null !== $row->unp && mb_strlen($row->unp) > self::MAX_ORGANIZATION_UNP) {
            throw new ImportRowStopped(
                $row->rowNumber,
                \sprintf('УНП длиннее %d символов', self::MAX_ORGANIZATION_UNP),
            );
        }

        foreach ($row->contacts as $index => $contact) {
            if (null !== $contact->phone && mb_strlen($contact->phone) > self::MAX_CONTACT_PHONE) {
                throw new ImportRowStopped(
                    $row->rowNumber,
                    \sprintf('телефон контакта №%d длиннее %d символов', $index + 1, self::MAX_CONTACT_PHONE),
                );
            }
        }
    }

    /**
     * Контакты, ради которых стоит создавать сущности.
     *
     * Контакт без единого заполненного поля не создаётся: в таблице проверки у
     * контакта есть только поля, кнопки удаления нет, поэтому очистить их —
     * единственный способ сказать «это не контакт». Стоп-ошибка здесь означала
     * бы, что исправить строку нечем.
     *
     * Контакт со значениями, но без имени, создаётся: имя единственное
     * обязательное поле, а телефон, почту или должность администратор оставил
     * намеренно, и молча выбрасывать их нельзя — подставляется то же
     * константное «Без имени», что и при разборе источника.
     *
     * Отметка основного контакта заполненным полем не считается: это флаг, а не
     * данные, и контакт только с ним всё равно нечего сохранять.
     *
     * @return ImportRowContact[]
     */
    private function savableContacts(ImportRow $row): array
    {
        $saved = [];
        foreach ($row->contacts as $contact) {
            $filled = '' !== trim($contact->name)
                || null !== $this->nullIfBlank($contact->phone)
                || null !== $this->nullIfBlank($contact->email)
                || null !== $this->nullIfBlank($contact->position)
                || null !== $this->nullIfBlank($contact->notes);

            if (!$filled) {
                continue;
            }

            $saved[] = '' === trim($contact->name)
                ? new ImportRowContact(
                    name: ContactData::ANONYMOUS_NAME,
                    phone: $contact->phone,
                    email: $contact->email,
                    position: $contact->position,
                    notes: $contact->notes,
                    isMain: $contact->isMain,
                )
                : $contact;
        }

        return $saved;
    }

    /**
     * Звонки, ради которых стоит создавать сущности.
     *
     * Звонок без даты, без заметок и без отметки «Планируемый» не создаётся:
     * в таблице проверки у звонка тоже только поля, и очистить их — единственный
     * способ отказаться от звонка. Заметка без даты остаётся: это осмысленная
     * запись, дата в ней может быть нечитаемой, и терять текст незачем.
     *
     * @return ImportRowCall[]
     */
    private function savableCalls(ImportRow $row): array
    {
        $saved = [];
        foreach ($row->calls as $call) {
            $hasDate = null !== $this->dates->parseDate($call->date);
            $hasNotes = null !== $this->nullIfBlank($call->notes);

            if (!$hasDate && !$hasNotes && !$call->planned) {
                continue;
            }

            $saved[] = $call;
        }

        return $saved;
    }

    /**
     * Существующая организация, указанная на остановившейся строке.
     */
    private function conflictedOrganization(ImportRow $row): ?Organization
    {
        if (null === $row->conflictOrganizationId) {
            return null;
        }

        $existing = $this->organizations->find($row->conflictOrganizationId);
        if (!$existing instanceof Organization) {
            // Карточка исчезла между остановкой и утверждением: сохраняем строку
            // как новую организацию, а не падаем на отсутствующем FK.
            return null;
        }

        return $existing;
    }

    /**
     * Поиск по названию средствами БД: сравнение выполняет сама база, поэтому
     * учитываются её правила регистра и сравнения текста. Дополнительной
     * нормализации импорт не применяет.
     *
     * @throws ImportRowConflict название уже занято
     */
    private function assertNoConflict(ImportRow $row): null
    {
        $existing = $this->em->createQuery(
            'SELECT o FROM App\Entity\Organization o WHERE o.name = :name ORDER BY o.id ASC',
        )
            ->setParameter('name', trim($row->name))
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!$existing instanceof Organization) {
            return null;
        }

        $row->markConflict((int) $existing->id, $existing->name);

        throw new ImportRowConflict($row->rowNumber, (int) $existing->id, $existing->name);
    }

    private function newOrganization(ImportRow $row, ?User $creator): Organization
    {
        $organization = new Organization();
        $organization
            ->setName(trim($row->name))
            ->setIndustry($this->nullIfBlank($row->industry))
            ->setCity($this->nullIfBlank($row->city))
            ->setUnp($this->nullIfBlank($row->unp))
            ->setAnnualPlan($this->nullIfBlank($row->annualPlan))
            ->setDescription($this->nullIfBlank($row->description))
            ->setCoursesAttended($this->nullIfBlank($row->coursesAttended))
            ->setWebsite($this->nullIfBlank($row->website))
            ->setCreatedBy($creator)
            ->setIsActive(true)
            ->setIsOptedOut(false);

        // `industry`, `city`, `unp` и `annualPlan` приходят только из
        // JSON-ответа (change add-organizations-json-import); у прогонов из
        // выгрузки они остаются null. Группы импорт не назначает — это область
        // администратора (ADR-0011).

        return $organization;
    }

    private function newContact(Organization $organization, ImportRowContact $data): Contact
    {
        $contact = new Contact();
        $contact
            ->setOrganization($organization)
            ->setName(trim($data->name))
            ->setPhone($this->nullIfBlank($data->phone))
            ->setEmail($this->nullIfBlank($data->email))
            ->setPosition($this->nullIfBlank($data->position))
            ->setNotes($this->nullIfBlank($data->notes))
            // Отметку основного контакта снимает `resetIsMainForOrganization()`
            // у вызывающего кода: она одна на организацию, а колонки источника
            // её не различают, поэтому из файла она всегда false. Если её не
            // отметил никто, `MailingService::effectiveMainContact()` по-прежнему
            // берёт контакт с минимальным ID.
            ->setIsMain($data->isMain);

        return $contact;
    }

    private function newCall(Organization $organization, ImportRowCall $data, ?User $creator): Call
    {
        $date = $this->dates->parseDate($data->date);

        $call = new Call();
        $call->setOrganization($organization);
        $call->setNotes($this->nullIfBlank($data->notes));

        if ($data->planned) {
            // Плановый звонок: scheduledAt задан, madeAt и madeBy пусты — его
            // никто не совершал. Дата в прошлом сохраняется как есть.
            $call->setScheduledAt($date);
        } else {
            $call->setMadeAt($date);
            $call->setMadeBy($creator);
        }

        // Отметки результата (isDeal, isRefusal, isNoAnswer) при импорте не
        // выставляются: в источнике их нет.

        return $call;
    }

    /**
     * Строка источника в DTO.
     *
     * Формат строки объявляет прогон, а не способ, которым сюда попали: JSON-прогон
     * уже несёт готовый `OrganizationData` из `JsonImportParser`, CSV-прогон —
     * запись, которую раскладывает `CsvRowMapper` (design D6).
     *
     * @param mixed $record
     */
    private function rowNumbered(mixed $record, int $rowNumber): ImportRow
    {
        $data = $record instanceof OrganizationData
            ? $record
            : $this->mapper->map(\is_array($record) ? $record : []);

        return $this->rowFrom($data, $rowNumber);
    }

    private function rowFrom(OrganizationData $data, int $rowNumber): ImportRow
    {
        $row = new ImportRow(
            rowNumber: $rowNumber,
            name: $data->name,
            industry: $data->industry,
            city: $data->city,
            unp: $data->unp,
            annualPlan: $data->annualPlan,
            description: $data->description,
            coursesAttended: $data->coursesAttended,
            website: $data->website,
        );

        foreach ($data->contacts as $contact) {
            $row->contacts[] = new ImportRowContact(
                name: $contact->name,
                phone: $contact->phone,
                email: $contact->email,
                position: $contact->position,
                notes: $contact->notes,
                // Отметка приходит из ответа JSON и показывается в форме
                // пакета отмеченной: на CSV-пути в выгрузке её нет, и там она
                // всегда false.
                isMain: $contact->isMain,
            );
        }
        foreach ($data->calls as $call) {
            $row->calls[] = $this->callRow($call);
        }

        return $row;
    }

    private function callRow(CallData $call): ImportRowCall
    {
        return new ImportRowCall(
            date: $call->scheduledAt?->format('d.m.Y') ?? $call->madeAt?->format('d.m.Y') ?? '',
            notes: $call->notes,
            planned: null !== $call->scheduledAt,
        );
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
