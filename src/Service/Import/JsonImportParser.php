<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\CallData;
use App\Dto\ContactData;
use App\Dto\OrganizationData;

/**
 * Разбор ответа языковой модели в те же DTO, что и CSV-путь (design D1).
 *
 * Здесь нет ни одной эвристики. CSV-путь вынужден догадываться — год из
 * соседней записи, текст без даты в заметку, — потому что его источник
 * эвристический. Здесь источник структурирован, а контракт объявлен заранее, и
 * всё, что ему не удовлетворяет, отвергнуто проверкой до этого разбора. Поэтому
 * дата читается `parseDate()`, а не `parseEntries()`: продолжения заметок и
 * восстановления года на этом пути не существует (design D2).
 *
 * Пустое необязательное поле — это отсутствие данных, а не пустая строка:
 * пустая строка в базу не пишется, иначе «город не указан» и «город — пробел»
 * стали бы неразличимы.
 */
final class JsonImportParser
{
    public function __construct(
        private readonly InteractionDateParser $dates,
    ) {}

    /**
     * Все организации ответа, по порядку массива.
     *
     * Индекс в массиве — это номер организации в отчёте о нарушениях, поэтому
     * он и здесь остаётся позицией, а не ключом.
     *
     * @return OrganizationData[]
     */
    public function organizations(mixed $payload): array
    {
        if (!\is_object($payload) || !isset($payload->organizations) || !\is_array($payload->organizations)) {
            return [];
        }

        $organizations = [];
        foreach ($payload->organizations as $entry) {
            if (\is_object($entry)) {
                $organizations[] = $this->organization($entry);
            }
        }

        return $organizations;
    }

    /**
     * Названия организаций по порядку — столько их в ответе, и это `totalRows`
     * прогона.
     *
     * @return string[]
     */
    public function organizationNames(mixed $payload): array
    {
        return array_map(
            static fn(OrganizationData $organization): string => $organization->name,
            $this->organizations($payload),
        );
    }

    /**
     * Строки организации, готовые к сравнению при замене файла (design D6).
     *
     * Сравнение идёт по значениям полей, а не по тексту payload: перестановка
     * ключей и другая запись ответа не должны выглядеть как изменение данных.
     *
     * @return array<string, string|null|string[]>
     */
    public function rowSignature(mixed $organization): array
    {
        // Не объект — значит строки нет: строка за пределами `processedRows`
        // сравнивается с пустым значением и считается изменившейся, а не роняет
        // отчёт о замене (design D6).
        if (!\is_object($organization)) {
            return [];
        }

        return [
            'name' => $this->text($organization->name ?? null),
            'industry' => $this->text($organization->industry ?? null),
            'city' => $this->text($organization->city ?? null),
            'unp' => $this->text($organization->unp ?? null),
            'annualPlan' => $this->text($organization->annualPlan ?? null),
            'website' => $this->text($organization->website ?? null),
            'description' => $this->text($organization->description ?? null),
            'coursesAttended' => $this->text($organization->coursesAttended ?? null),
            'contacts' => $this->contactsSignature($organization),
            'calls' => $this->callsSignature($organization, 'calls'),
            'nextCall' => $this->callsSignature($organization, 'nextCall'),
        ];
    }

    private function organization(object $entry): OrganizationData
    {
        $calls = [];
        foreach ($this->list($entry, 'calls') as $call) {
            if (\is_object($call)) {
                $calls[] = new CallData(
                    madeAt: $this->date($call->date ?? null),
                    notes: $this->text($call->notes ?? null),
                );
            }
        }

        $nextCall = $entry->nextCall ?? null;
        if (\is_object($nextCall)) {
            $scheduledAt = $this->date($nextCall->date ?? null);
            $purpose = $this->text($nextCall->purpose ?? null);

            // Плановый звонок без даты и без цели не создаётся: это пустой объект,
            // а не намерение. С датой он становится плановым звонком, без даты —
            // записью о намерении в заметке.
            if (null !== $scheduledAt || null !== $purpose) {
                $calls[] = new CallData(
                    scheduledAt: $scheduledAt,
                    notes: $purpose,
                );
            }
        }

        return new OrganizationData(
            name: (string) ($entry->name ?? ''),
            industry: $this->text($entry->industry ?? null),
            city: $this->text($entry->city ?? null),
            unp: $this->text($entry->unp ?? null),
            annualPlan: $this->text($entry->annualPlan ?? null),
            description: $this->text($entry->description ?? null),
            coursesAttended: $this->text($entry->coursesAttended ?? null),
            website: $this->text($entry->website ?? null),
            contacts: $this->contacts($entry),
            calls: $calls,
        );
    }

    /**
     * @return ContactData[]
     */
    private function contacts(mixed $entry): array
    {
        if (!\is_object($entry)) {
            return [];
        }

        $contacts = [];
        foreach ($this->list($entry, 'contacts') as $contact) {
            if (!\is_object($contact)) {
                continue;
            }

            $name = $this->text($contact->name ?? null);
            $phone = $this->text($contact->phone ?? null);
            $email = $this->text($contact->email ?? null);
            $position = $this->text($contact->position ?? null);

            // Имя — единственное обязательное поле контакта, но контакт с одним
            // лишь телефоном или почтой не выбрасывается: это тот же случай, что
            // на CSV-пути, где безымянный контакт создаётся под «Без имени».
            $contacts[] = new ContactData(
                name: $name ?? ContactData::ANONYMOUS_NAME,
                phone: $phone,
                email: $email,
                position: $position,
                // Отметку приносит ответ: в выгрузке CRM основной контакт не
                // различается, а в файле из другого источника различается, и
                // `MailingService::effectiveMainContact()` берёт первый по ID.
                isMain: true === ($contact->isMain ?? null),
            );
        }

        return $contacts;
    }

    /**
     * @return string[]
     */
    private function contactsSignature(mixed $entry): array
    {
        if (!\is_object($entry)) {
            return [];
        }

        $signature = [];
        foreach ($this->contacts($entry) as $contact) {
            $signature[] = implode("\x1F", [
                $contact->name,
                (string) $contact->phone,
                (string) $contact->email,
                (string) $contact->position,
                // Отметка основного — данные, а не флаг формы: её смена в
                // обработанной части префикса должна попасть в отчёт о замене.
                $contact->isMain ? '1' : '0',
            ]);
        }

        return $signature;
    }

    /**
     * @return string[]
     */
    private function callsSignature(mixed $entry, string $field): array
    {
        if (!\is_object($entry)) {
            return [];
        }

        $signature = [];
        foreach ($this->list($entry, $field) as $call) {
            if (!\is_object($call)) {
                continue;
            }

            $signature[] = implode("\x1F", [
                $this->date($call->date ?? null)?->format('d.m.Y') ?? '',
                (string) $this->text($call->notes ?? $call->purpose ?? null),
            ]);
        }

        return $signature;
    }

    /**
     * @return mixed[]
     */
    private function list(mixed $entry, string $field): array
    {
        $value = \is_object($entry) ? ($entry->{$field} ?? null) : null;

        return \is_array($value) ? array_values($value) : [];
    }

    /**
     * Дата из поля ответа — дословно, той же грамматикой, что и на вкладке CSV.
     *
     * `null` здесь означает «даты нет», а не «дату не удалось прочитать»: второе
     * до сюда не доходит, потому что `pattern` в схеме отвергает дату, которую
     * разобрать нельзя. Поэтому год не подставляется и дата не достраивается.
     */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->text(\is_scalar($value) ? (string) $value : null);

        return null === $value ? null : $this->dates->parseDate($value);
    }

    private function text(mixed $value): ?string
    {
        $value = null === $value ? null : trim((string) $value);

        return '' === $value ? null : $value;
    }
}
