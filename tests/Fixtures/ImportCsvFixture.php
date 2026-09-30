<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Service\Import\CsvParser;

/**
 * Генератор тестовых CSV-фикстур импорта.
 *
 * Фикстуры пишутся кодом, а не вручную, по трём причинам: каждая строка
 * получает ровно 10 полей (9 объявленных колонок плюс хвостовое пустое, как в
 * реальной выгрузке), ячейки с переводами строк и запятыми экранируются по
 * правилам RFC 4180, и обратный слэш перед закрывающей кавычкой попадает в
 * файл байт в байт — а именно его обработка отличает корректный парсер от
 * сломанного (design D6/D6a).
 */
final class ImportCsvFixture
{
    /** @var array<int, string> строки данных (заголовок добавляется отдельно) */
    private array $rows = [];

    public function __construct()
    {
        $this->row(...array_fill(0, 10, ''));
    }

    /**
     * Добавляет строку данных. Значения автоматически экранируются.
     */
    public function row(string ...$values): self
    {
        $values += array_fill(0, 10, '');
        $values = \array_slice($values, 0, 10);
        $this->rows[] = implode(',', array_map(self::quote(...), $values));

        return $this;
    }

    /**
     * Полностью пустая строка: разбор её пропускает, в totalRows она не входит.
     */
    public function emptyRow(): self
    {
        $this->rows[] = implode(',', array_fill(0, 10, ''));

        return $this;
    }

    public function content(): string
    {
        return implode(',', array_map(self::quote(...), [...CsvParser::HEADERS, '']))
            . "\r\n"
            . implode("\r\n", $this->rows) . "\r\n";
    }

    public function writeTo(string $path): string
    {
        file_put_contents($path, $this->content());

        return $path;
    }

    private static function quote(string $value): string
    {
        if ('' === $value) {
            return '';
        }
        if (!str_contains($value, ',') && !str_contains($value, '"')
            && !str_contains($value, "\n") && !str_contains($value, "\r")
        ) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
