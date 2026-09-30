<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\CallData;
use App\Dto\ContactData;
use App\Dto\OrganizationData;

/**
 * Разбор одной записи CSV в DTO организации, контактов и звонков (design D3).
 *
 * Разбор эвристический: ячейка «Контакты» не структурирована, имена, должности,
 * телефоны и почты смешаны свободно. Надёжного автоматического разбора не
 * существует, и страховкой служит проверка пакета — пользователь правит любой
 * разобранный результат до утверждения.
 *
 * Столбец «Текущее состояние» не читается вовсе. Он обязателен в заголовке,
 * чтобы объявленный формат оставался стабильным, но его содержимое не имеет
 * собственного поля и дублирует журнал взаимодействий, который уже переносится
 * в звонки.
 */
final class CsvRowMapper
{
    private const string COURSE_FRAGMENT_LABEL = 'Актуальный курс: ';

    /**
     * Маркер телефона в ячейке «Контакты»: «тел:», «тел.», «тел » и без знака
     * препинания. Захватывает разделители вокруг себя, поэтому куски получаются
     * без запятых на стыках.
     */
    private const string PHONE_MARKER_PATTERN = '/[,;\s]*\bтел[\s.]*[:.]?\s*/u';

    public function __construct(
        private readonly InteractionDateParser $dates,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * @param array<string, string> $record
     */
    public function map(array $record): OrganizationData
    {
        $coursesAttended = trim(CsvParser::cell($record, 'Учились у нас'));

        return new OrganizationData(
            name: $this->truncate(CsvParser::cell($record, 'Компания'), 255),
            description: $this->description($record),
            coursesAttended: '' === $coursesAttended ? null : $this->truncate($coursesAttended, 255),
            website: $this->website(CsvParser::cell($record, 'Составление плана на год')),
            contacts: $this->contacts(CsvParser::cell($record, 'Контакты')),
            calls: $this->calls($record),
        );
    }

    /**
     * «Актуальный курс» не имеет собственного поля, поэтому переносится в
     * описание помеченным фрагментом, а исходный текст не теряется.
     *
     * @param array<string, string> $record
     */
    private function description(array $record): ?string
    {
        $course = CsvParser::cell($record, 'Актуальный курс');
        if ('' === $course) {
            return null;
        }

        return self::COURSE_FRAGMENT_LABEL . $course;
    }

    /**
     * Адрес сайта или домен из столбца «Составление плана на год» уходит в
     * `website`; утверждение о составлении плана — отбрасывается.
     */
    private function website(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value || 1 !== preg_match('#^(https?://)?[\p{L}\p{N}]([\p{L}\p{N}.-]*\.[\p{L}]{2,})(/[^\s]*)?$#u', $value)) {
            return null;
        }

        return $this->truncate($value, 255);
    }

    /**
     * @param array<string, string> $record
     *
     * @return CallData[]
     */
    private function calls(array $record): array
    {
        $calls = [];
        foreach ($this->dates->parseEntries(CsvParser::cell($record, 'Взаимодействия')) as $entry) {
            $calls[] = new CallData(madeAt: $entry->date, notes: $entry->notes());
        }

        $planned = $this->dates->parseNextContact(CsvParser::cell($record, 'Следующий контакт'));
        if (null !== $planned) {
            $purpose = CsvParser::cell($record, 'Для чего звонок?');
            $calls[] = new CallData(
                scheduledAt: $planned,
                notes: '' === $purpose ? null : $purpose,
            );
        }

        return $calls;
    }

    /**
     * Эвристика контактов: телефоны и почты вынимаются регулярными выражениями,
     * оставшийся текст становится именем и должностью.
     *
     * Границы контактов ищутся по маркеру телефона, а не по виду имени:
     * «Иван Петров, тел: …» и «Иван, тел: …» отличаются только фамилией, и
     * объявлять новый контакт по «Имя Фамилия» значило бы разрезать первое же
     * имя пополам. Маркер «тел» зато однозначен.
     *
     * @return ContactData[]
     */
    private function contacts(string $cell): array
    {
        $cell = trim($cell);
        if ('' === $cell) {
            return [];
        }

        $contacts = [];
        foreach ($this->splitBySeparators($cell) as $chunk) {
            foreach ($this->splitByPhoneMarker($chunk) as [$text, $phone, $email]) {
                $contacts[] = $this->contact($text, $phone, $email);
            }
        }

        return $contacts;
    }

    /**
     * Явные разделители между контактами: точка с запятой, перевод строки,
     * маркер списка.
     *
     * @return string[]
     */
    private function splitBySeparators(string $cell): array
    {
        $parts = preg_split('/\s*[;\n•·]+\s*/u', $cell) ?: [$cell];

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn(string $part): bool => '' !== $part,
        ));
    }

    /**
     * Один фрагмент разрезается по маркерам «тел:». Куски чередуются: текст
     * контакта, затем его реквизиты, снова текст, снова реквизиты.
     *
     * Реквизиты куска начинаются с телефона, а после него через запятую идёт
     * либо следующий контакт («… +7-900-111-11-11, Мария Сидорова»), либо
     * почта того же контакта («… +7-900-111-11-11, ivan@mail.ru»). Различие
     * одно: почта узнаётся по `@`.
     *
     * @return list<array{0: string, 1: string|null, 2: string|null}>
     *         0 — текст, 1 — телефон, 2 — почта
     */
    private function splitByPhoneMarker(string $chunk): array
    {
        $parts = preg_split(self::PHONE_MARKER_PATTERN, $chunk, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$chunk];
        if (1 === \count($parts)) {
            return [[$chunk, $this->extractPhone($chunk), $this->extractEmail($chunk)]];
        }

        $texts = [trim((string) $parts[0])];
        $phones = [null];
        $emails = [null];

        for ($i = 1, $total = \count($parts); $i < $total; ++$i) {
            $piece = trim((string) $parts[$i]);
            $phone = $this->extractPhone($piece);
            if (null !== $phone) {
                $piece = trim(substr($piece, (int) strpos($piece, $phone) + \strlen($phone)));
            }
            $email = null === $phone ? null : $this->extractEmail($piece);

            $last = \count($texts) - 1;
            $phones[$last] = $phone;
            $emails[$last] = $email;

            // Остаток куска, если это не почта, — текст следующего контакта.
            if (null === $email && '' !== $piece) {
                $texts[] = $piece;
                $phones[] = null;
                $emails[] = null;
            }
        }

        $result = [];
        foreach ($texts as $i => $text) {
            if ('' !== $text || null !== $phones[$i] || null !== $emails[$i]) {
                $result[] = [$text, $phones[$i], $emails[$i]];
            }
        }

        return $result;
    }

    /**
     * @param string      $text      текст контакта: имя и, возможно, должность
     * @param string|null $phoneRaw  телефон контакта
     * @param string|null $emailRaw  почта контакта
     */
    private function contact(string $text, ?string $phoneRaw, ?string $emailRaw): ContactData
    {
        $phoneRaw ??= $this->extractPhone($text);
        $emailRaw ??= $this->extractEmail($text);

        // Из текста убираются почта и маркеры телефона; серии разделителей
        // схлопываются, чтобы «Иван Петров, , ,» не распался на пустые части.
        $rest = str_replace([$emailRaw ?? '', (string) $phoneRaw], '', $text);
        $rest = preg_replace('/\bтел[\s.]*[:.]?/iu', ' ', $rest) ?? $rest;
        $rest = preg_replace('/\s*[,;]\s*(?:[,;]\s*)+/u', ', ', $rest) ?? $rest;
        $rest = trim($rest, ",; \t\n\r\0\x0B");

        // Телефон приводится к каноническому виду до обрезки: нормализация
        // работает по цифрам, а обрезка — по длине строки.
        $phone = null === $phoneRaw ? null : $this->truncate($this->phones->normalize($phoneRaw), 32);
        $email = null === $emailRaw ? null : $this->truncate($emailRaw, 255);

        if ('' === $rest) {
            // Фрагмент не выбрасывается: Contact.name NOT NULL, поэтому контакт
            // импортируется под константным именем, а телефон и почта, если они
            // были, к нему и привязаны.
            return new ContactData(
                name: ContactData::ANONYMOUS_NAME,
                phone: $phone,
                email: $email,
            );
        }

        $parts = explode(',', $rest, 2);
        $position = trim($parts[1] ?? '');

        return new ContactData(
            name: $this->truncate(trim($parts[0]), 255),
            phone: $phone,
            email: $email,
            position: '' === $position ? null : $this->truncate($position, 255),
        );
    }

    private function extractPhone(string $text): ?string
    {
        return preg_match('/\+?\d[\d\s()\-]{5,}\d/u', $text, $matches) === 1
            ? trim($matches[0])
            : null;
    }

    private function extractEmail(string $text): ?string
    {
        return preg_match('/[^\s;,]+@[^\s;,]+/u', $text, $matches) === 1
            ? trim($matches[0])
            : null;
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
