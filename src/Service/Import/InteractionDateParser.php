<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Базовая грамматика дат записей взаимодействий (design D6a).
 *
 * Правило одно: группа в скобках читается как дата **только когда день, месяц и
 * год однозначны**. Всё прочее — `(отдел кадров)`, `(09.04)`, `(09.04.202)`,
 * `(09.04.20255)` — остаётся текстом в заметках звонка. Год не
 * восстанавливается, не усекается и не переносится из предыдущей записи:
 * видимая, редактируемая заметка лучше незаметно выдуманной даты, которая
 * потом оказывается в истории звонков как настоящая.
 *
 * Грамматика применяется только к колонкам, порождающим звонки —
 * «Взаимодействия» и «Следующий контакт». Прочие колонки (в том числе
 * «Контакты» с его ~1 000 группами в скобках) на даты не сканируются.
 */
final class InteractionDateParser
{
    /**
     * Плейсхолдеры «Следующего контакта»: планированный звонок не создаётся.
     *
     * @var string[]
     */
    public const array PLACEHOLDERS = ['-', '_', 'не актуально', 'нет'];

    /**
     * Базовая грамматика целиком: D.M.YYYY / DD.MM.YYYY / DD.MM.YY /
     * DD/MM/YYYY / DD,MM.YYYY, с необязательным хвостом `_` или `.`,
     * который игнорируется.
     */
    private const string DATE_PATTERN = '/^(\d{1,2})[.,\/](\d{1,2})[.,\/](\d{4}|\d{2})(?!\d)[_.]?$/';

    /**
     * Та же грамматика, но в начале текста: так читается начало группы,
     * продолжающейся после даты (интервал дат). Четыре цифры проверяются
     * первыми, а `(?!\d)` запрещает укоротить год: без него `(09.04.202)` и
     * `(09.04.20255)` прочитались бы как 09.04.2020.
     */
    private const string LEADING_DATE_PATTERN = '/^(\d{1,2})[.,\/](\d{1,2})[.,\/](\d{4}|\d{2})(?!\d)[_.]?/';

    /**
     * Является ли текст датой по базовой грамматике.
     */
    public function isDate(string $text): bool
    {
        return null !== $this->parseDate($text);
    }

    /**
     * Дата из текста группы. Возвращает null, если день, месяц или год не
     * однозначны — в том числе если года нет вовсе или он нечитаем.
     */
    public function parseDate(string $text): ?\DateTimeImmutable
    {
        $text = trim($text);
        if (1 !== preg_match(self::DATE_PATTERN, $text, $m)) {
            return null;
        }

        return $this->toDate($m[1], $m[2], $m[3]);
    }

    /**
     * Дата, с которой начинается текст группы, — даже если группа продолжается
     * дальше (интервал `(26.09.2025-06.10.2025- 13.10.2025)`).
     */
    public function parseLeadingDate(string $text): ?\DateTimeImmutable
    {
        $text = ltrim($text);
        if (1 !== preg_match(self::LEADING_DATE_PATTERN, $text, $m)) {
            return null;
        }

        return $this->toDate($m[1], $m[2], $m[3]);
    }

    /**
     * Разбирает ячейку «Следующий контакт»: плейсхолдеры и пустое значение
     * планированного звонка не создают, прочая нечитаемая дата — тоже: значение
     * остаётся в проверке пакета для правки на месте.
     */
    public function parseNextContact(string $cell): ?\DateTimeImmutable
    {
        return $this->isPlaceholder($cell) ? null : $this->parseDate($cell);
    }

    /**
     * Значение «Следующего контакта» — пустое либо объявленный форматом
     * плейсхолдер: планированного звонка не создаёт.
     */
    public function isPlaceholder(string $cell): bool
    {
        $cell = trim($cell);

        return '' === $cell || \in_array(mb_strtolower($cell), self::PLACEHOLDERS, true);
    }

    /**
     * Записи взаимодействий из многострочной ячейки «Взаимодействия».
     *
     * Токен — группа в скобках, начинающаяся с даты по базовой грамматике.
     * Текст до первого токена образует запись без даты; заметки записи идут до
     * следующего токена. Группа, являющаяся датой целиком, из заметок убирается;
     * группа, продолжающаяся после даты, сохраняется целиком — она и есть «весь
     * оставшийся текст», так что исходная запись видна в заметке целиком.
     * Группа, датой не являющаяся, остаётся частью окружающего текста и своего
     * звонка не датирует.
     *
     * @return InteractionEntry[]
     */
    public function parseEntries(string $cell): array
    {
        $groups = $this->findGroups($cell);
        if ([] === $groups) {
            $text = trim($cell);

            return '' === $text ? [] : [new InteractionEntry(null, $text)];
        }

        $entries = [];
        $current = null;
        $cursor = 0;

        foreach ($groups as $group) {
            $leading = substr($cell, $cursor, $group['offset'] - $cursor);
            $cursor = $group['offset'] + \strlen($group['text']);
            $date = $this->parseLeadingDate($group['content']);

            if (null === $date) {
                // Не дата: текст остаётся в окружающих заметках и своего
                // звонка не создаёт.
                $this->appendTo(
                    $entries,
                    $current,
                    $leading . $group['text'],
                );
                continue;
            }

            $this->appendTo($entries, $current, $leading);

            $content = trim($group['content']);
            $isWholeDate = $content === rtrim($content, '_.') && $this->isDate($content);
            $current = new InteractionEntry($date, $isWholeDate ? '' : $group['text']);
            $entries[] = $current;
        }

        $this->appendTo($entries, $current, substr($cell, $cursor));

        return $entries;
    }

    /**
     * Звонок совершён в 12:00 дня даты: полдень вместо полуночи, чтобы дата не
     * «уезжала» на предыдущие сутки при отображении в других часовых поясах.
     */
    public function atNoon(int $year, int $month, int $day): \DateTimeImmutable
    {
        $date = new \DateTimeImmutable();
        $date = $date->setDate($year, $month, $day);

        return $date->setTime(12, 0);
    }

    /**
     * Текст достаётся в текущую запись, а если её нет и текст непуст — создаёт
     * запись без даты и продолжает её.
     *
     * @param InteractionEntry[]  $entries
     * @param InteractionEntry|null $current
     */
    private function appendTo(array &$entries, ?InteractionEntry &$current, string $text): void
    {
        if ('' === trim($text)) {
            return;
        }
        if (null === $current) {
            $current = new InteractionEntry(null, $text);
            $entries[] = $current;

            return;
        }
        $current->appendText($text);
    }

    /**
     * @return list<array{text: string, content: string, offset: int}>
     */
    private function findGroups(string $cell): array
    {
        $matched = preg_match_all('/\(([^()]*)\)/u', $cell, $matches, PREG_OFFSET_CAPTURE);
        if (false === $matched || 0 === $matched) {
            return [];
        }

        $groups = [];
        foreach ($matches[0] as $index => [$text, $offset]) {
            $groups[] = [
                'text' => $text,
                'content' => $matches[1][$index][0],
                'offset' => $offset,
            ];
        }

        return $groups;
    }

    private function toDate(string $day, string $month, string $year): ?\DateTimeImmutable
    {
        $yearValue = 2 === \strlen($year) ? 2000 + (int) $year : (int) $year;
        if (!checkdate((int) $month, (int) $day, $yearValue)) {
            return null;
        }

        return $this->atNoon($yearValue, (int) $month, (int) $day);
    }
}
