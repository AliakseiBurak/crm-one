<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Одна позиция перечня файлов журнала отправки писем (change
 * email-send-logging, D8): период, имя файла и его размер. Содержимое журнала
 * в позиции нет и не читается — перечень работает только с именами файлов.
 *
 * Сортировка от более новых периодов к более старым, причём архив года идёт
 * последним среди позиций своего года: сортировать его по началу года значило
 * бы поставить `mailer-2026.zip` между `2026-12` и `2026-11`, и год читался бы
 * как две независимые группы.
 */
final readonly class MailerLogFile
{
    public const string KIND_MONTH = 'month';
    public const string KIND_YEAR = 'year';

    /**
     * Ключ сортировки архива года — самый ранний месяц года. Перечень
     * упорядочен по убыванию, поэтому такой ключ ставит архив после всех
     * месяцев своего года и перед позициями предыдущих лет.
     */
    private const string YEAR_SORT_SUFFIX = '-00';

    /**
     * @param string $period «YYYY-MM» для месяца, «YYYY» для года
     * @param string $kind    одна из констант KIND_*
     */
    public function __construct(
        public string $period,
        public string $kind,
        public string $filename,
        public int $size,
    ) {}

    public function isMonth(): bool
    {
        return self::KIND_MONTH === $this->kind;
    }

    public function sortKey(): string
    {
        return $this->isMonth() ? $this->period : $this->period . self::YEAR_SORT_SUFFIX;
    }

    /**
     * Человекочитаемый размер файла; пустой файл показывается как `0 Б`.
     */
    public function formattedSize(): string
    {
        if ($this->size < 1024) {
            return $this->size . ' Б';
        }

        $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
        $value = $this->size / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }
}
