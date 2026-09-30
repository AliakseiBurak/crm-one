<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\Import\Exception\CsvFormatException;
use App\Service\Import\Exception\CsvHeaderMismatch;

/**
 * Чтение CSV-файла импорта организаций.
 *
 * Файл читается как RFC 4180 (design D6). Ключевой момент: `fgetcsv()`
 * вызывается с пустым `$escape`. Обратный слэш в CSV — обычный символ, а не
 * экранирующий; с экранированием по умолчанию (`\`) последовательность `\"`
 * внутри кавычек «съедает» закрывающую кавычку, и ячейка продолжает
 * поглощать текст до следующей настоящей кавычки — на реальной выгрузке это
 * 421 запись вместо 398, причём 23 обрывка строк предлагаются к импорту как
 * организации с именами вроде `(05.11.2025) Направила КП для плана все`.
 * Тестовые фикстуры обязаны содержать такую последовательность (design D6a),
 * иначе набор тестов проходит и при сломанном парсере.
 *
 * Файл не загружается в память целиком: записи читаются построчно генератором.
 */
final class CsvParser
{
    /**
     * Объявленный формат источника (спека organizations-import, «Формат
     * CSV-файла»). Порядок значим для описания формата; проверка же сравнивает
     * наборы колонок, а разбор идёт по именам, поэтому перестановка колонок в
     * файле не ломает импорт и не молча меняет раскладку данных.
     *
     * @var string[]
     */
    public const array HEADERS = [
        'Компания',
        'Актуальный курс',
        'Взаимодействия',
        'Контакты',
        'Следующий контакт',
        'Для чего звонок?',
        'Текущее состояние',
        'Учились у нас',
        'Составление плана на год',
    ];

    private const string BOM = "\u{FEFF}";

    /**
     * Проверяет заголовок и наличие данных. Бросает исключение до того, как
     * что-либо записано, — прогон и его текущий файл остаются нетронутыми.
     *
     * @throws CsvHeaderMismatch ожидаемых колонок не хватает либо есть лишние
     * @throws CsvFormatException  файл пуст или не содержит ни одной записи
     */
    public function assertUsable(string $path): void
    {
        $this->assertHeader($path);
        if (0 === $this->countRecords($path)) {
            throw new CsvFormatException(
                'В файле нет ни одной строки данных — импортировать нечего.',
            );
        }
    }

    /**
     * Непустые колонки заголовка файла: ячейки обрезаны от пробелов, хвостовые
     * пустые поля отброшены, ведущий BOM снят.
     *
     * @return string[]
     */
    public function readHeader(string $path): array
    {
        $handle = $this->open($path);
        try {
            $fields = $this->readLine($handle);
        } finally {
            fclose($handle);
        }

        if (null === $fields) {
            throw new CsvFormatException('Файл пуст: нет строки заголовков.');
        }

        return $this->normalizeHeader($fields);
    }

    /**
     * Проверяет заголовок против объявленного формата.
     *
     * @throws CsvHeaderMismatch
     */
    public function assertHeader(string $path): void
    {
        $actual = $this->readHeader($path);
        $missing = array_values(array_diff(self::HEADERS, $actual));
        $unexpected = array_values(array_diff($actual, self::HEADERS));
        if ([] !== $missing || [] !== $unexpected) {
            throw new CsvHeaderMismatch(self::HEADERS, $actual);
        }
    }

    /**
     * Поток непустых записей источника, индексированный с нуля.
     *
     * Нумерация непустых записей — это и есть «номер строки в файле» во всём
     * импорте: `totalRows`, `processedRows`, границы пакета и строка
     * продолжения считаются в ней.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function records(string $path): \Generator
    {
        $handle = $this->open($path);
        try {
            $header = $this->readLine($handle);
            if (null === $header) {
                return;
            }
            $columns = $this->normalizeHeader($header);

            $index = 0;
            while (null !== $fields = $this->readLine($handle)) {
                if ($this->isEmptyRecord($fields)) {
                    continue;
                }
                yield $index => $this->mapColumns($fields, $columns);
                ++$index;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Число непустых записей источника (без заголовка).
     */
    public function countRecords(string $path): int
    {
        $count = 0;
        foreach ($this->records($path) as $_) {
            ++$count;
        }

        return $count;
    }

    /**
     * Содержимое ячейки колонки: ячейки сопоставляются по имени колонки, а не
     * по позиции, поэтому лишние поля строки игнорируются, а недостающие
     * считаются пустыми.
     *
     * @param array<string, string> $record
     */
    public static function cell(array $record, string $column): string
    {
        return trim($record[$column] ?? '');
    }

    /**
     * Текст записи целиком — так строка сравнивается при замене файла
     * (design D8): «строка сравнивается так, как её определяет формат», а для
     * CSV строка — это запись, сравниваемая как текст.
     *
     * @param array<string, string> $record
     */
    public static function recordText(array $record): string
    {
        return implode("\x1F", array_map(
            static fn(string $value): string => trim($value),
            array_values($record),
        ));
    }

    /**
     * Название организации в записи — единственное, что нужно прогону для
     * отчёта о замене файла (design D8).
     *
     * @param array<string, string> $record
     */
    public static function organizationName(array $record): string
    {
        return self::cell($record, 'Компания');
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new CsvFormatException(\sprintf('Не удалось прочитать файл импорта "%s".', $path));
        }

        return $handle;
    }

    /**
     * Одна строка CSV с отключённой обработкой обратного слэша.
     *
     * @param resource $handle
     *
     * @return string[]|null null на конце файла
     */
    private function readLine($handle): ?array
    {
        $fields = fgetcsv($handle, 0, ',', '"', '');
        if (false === $fields) {
            return null;
        }

        return array_map(static fn(?string $field): string => $field ?? '', $fields);
    }

    /**
     * @param string[] $fields
     *
     * @return string[]
     */
    private function normalizeHeader(array $fields): array
    {
        $headers = [];
        foreach ($fields as $index => $field) {
            $field = trim(0 === $index ? trim($field, self::BOM) : $field);
            if ('' === $field) {
                continue;
            }
            $headers[] = $field;
        }

        return $headers;
    }

    /**
     * @param string[] $fields
     */
    private function isEmptyRecord(array $fields): bool
    {
        foreach ($fields as $field) {
            if ('' !== trim($field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ячейки записи раскладываются по именам колонок. Колонки объявленного
     * формата, которых в строке нет, читаются как пустые: assertHeader()
     * отсекает такой файл раньше, но маппинг не должен падать на неполной
     * строке.
     *
     * @param string[] $fields
     * @param string[] $columns непустые заголовки файла
     *
     * @return array<string, string>
     */
    private function mapColumns(array $fields, array $columns): array
    {
        $byColumn = [];
        foreach ($columns as $position => $column) {
            $byColumn[$column] = $fields[$position] ?? '';
        }

        // Раскладка записи всегда следует объявленному формату, а не порядку
        // колонок конкретного файла: сравнение строк при замене файла
        // (design D8) тогда не зависит от того, как записан файл.
        $record = [];
        foreach (self::HEADERS as $column) {
            $record[$column] = $byColumn[$column] ?? '';
        }

        return $record;
    }
}
