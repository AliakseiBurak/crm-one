<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\CsvParser;
use App\Service\Import\Exception\CsvFormatException;
use App\Service\Import\Exception\CsvHeaderMismatch;
use App\Tests\Fixtures\ImportCsvFixture;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase
{
    private CsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CsvParser();
    }

    public function testReadsDeclaredHeaderWithTrailingEmptyFieldAndTrailingSpace(): void
    {
        $header = $this->parser->readHeader($this->fixture('valid.csv'));

        self::assertSame(CsvParser::HEADERS, $header);
    }

    public function testHeaderIsComparedAfterTrimmingAndIgnoringEmptyTrailingFields(): void
    {
        $content = '" Компания ","Актуальный курс",Взаимодействия,Контакты,"Следующий контакт","Для чего звонок?",'
            . '"Текущее состояние","Учились у нас","Составление плана на год",,'
            . "\nНафтан,,,,,,,,\n";
        $path = $this->tempFile($content);

        $this->parser->assertHeader($path);
        self::assertSame(CsvParser::HEADERS, $this->parser->readHeader($path));
    }

    public function testHeaderMismatchReportsExpectedAndActualHeaders(): void
    {
        $path = $this->fixture('missing-column.csv');

        try {
            $this->parser->assertHeader($path);
            self::fail('Ожидалось CsvHeaderMismatch.');
        } catch (CsvHeaderMismatch $e) {
            self::assertSame(CsvParser::HEADERS, $e->expectedHeaders);
            self::assertNotContains('Взаимодействия', $e->actualHeaders);
            self::assertStringContainsString('«Взаимодействия»', $e->getMessage());
            foreach (CsvParser::HEADERS as $header) {
                self::assertStringContainsString('«' . $header . '»', $e->getMessage());
            }
        }
    }

    public function testUnexpectedNonEmptyColumnIsRejected(): void
    {
        $path = $this->tempFile(
            implode(',', [...CsvParser::HEADERS, 'Лишняя колонка', '']) . "\nНафтан,,,,,,,,,,\n",
        );

        $this->expectException(CsvHeaderMismatch::class);
        $this->expectExceptionMessage('«Лишняя колонка»');

        $this->parser->assertHeader($path);
    }

    public function testFileWithoutDataRecordsIsRejected(): void
    {
        $path = $this->tempFile(implode(',', [...CsvParser::HEADERS, '']) . "\n");

        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('нет ни одной строки данных');

        $this->parser->assertUsable($path);
    }

    public function testFullyEmptyRecordsAreSkippedAndNotCounted(): void
    {
        $records = iterator_to_array($this->parser->records($this->fixture('valid.csv')));

        // Заголовок и две полностью пустые строки в число записей не входят.
        self::assertCount(5, $records);
        self::assertSame([0, 1, 2, 3, 4], array_keys($records));
    }

    public function testRecordsAreMappedByColumnNameNotByPosition(): void
    {
        $records = iterator_to_array($this->parser->records($this->fixture('valid.csv')));

        self::assertSame('Нафтан', CsvParser::cell($records[0], 'Компания'));
        self::assertSame('Курс по переговорам', CsvParser::cell($records[0], 'Учились у нас'));
        self::assertSame('https://armis.by/', CsvParser::cell($records[0], 'Составление плана на год'));
    }

    public function testShortRecordIsPaddedWithEmptyCells(): void
    {
        $records = iterator_to_array($this->parser->records($this->fixture('valid.csv')));

        // «Белсвязьстрой №4 Могилев»,,, — три поля, остальные колонки пусты.
        self::assertSame('Белсвязьстрой №4 Могилев', CsvParser::cell($records[1], 'Компания'));
        self::assertSame('', CsvParser::cell($records[1], 'Взаимодействия'));
        self::assertSame('', CsvParser::cell($records[1], 'Составление плана на год'));
    }

    public function testQuotedCellsKeepLineBreaksAndDoubledQuotes(): void
    {
        $path = (new ImportCsvFixture())
            ->row('Нафтан, ООО', '', "(25.08.2026) Первая строка\n(17.10.2025) \"Вторая строка\"")
            ->content();
        $path = $this->tempFile($path);

        $records = iterator_to_array($this->parser->records($path));

        self::assertSame('Нафтан, ООО', CsvParser::cell($records[0], 'Компания'));
        self::assertSame(
            "(25.08.2026) Первая строка\n(17.10.2025) \"Вторая строка\"",
            CsvParser::cell($records[0], 'Взаимодействия'),
        );
    }

    /**
     * Design D6/D6a: обратный слэш — обычный символ. Экранирование по
     * умолчанию съедает закрывающую кавычку и склеивает записи, поэтому
     * фикстура обязана содержать `\` прямо перед закрывающей кавычкой, а тест
     * — точный счётчик записей.
     */
    public function testBackslashBeforeClosingQuoteDoesNotSwallowFollowingRecords(): void
    {
        $path = $this->fixture('backslash.csv');

        $records = iterator_to_array($this->parser->records($path));

        self::assertCount(5, $records, 'Обратный слэш перед закрывающей кавычкой не должен склеивать записи.');
        self::assertSame(
            ['Обратный слэш, КП можно\\', 'ВтороеКольцо, КП можно\\', 'ТретьеКольцо', 'ЧетвёртоеКольцо', 'ПятоеКольцо'],
            array_map(CsvParser::organizationName(...), $records),
        );
    }

    /**
     * Запись раскладывается по объявленным девяти колонкам, а сырые поля
     * файла остаются десятью — включая хвостовое пустое.
     */
    public function testBackslashFixtureKeepsTheDeclaredColumnCount(): void
    {
        $path = $this->fixture('backslash.csv');

        $records = iterator_to_array($this->parser->records($path));

        self::assertCount(\count(CsvParser::HEADERS), $records[0]);
        self::assertSame(['Компания'], array_keys(array_diff_key($records[0], array_flip(
            array_diff(CsvParser::HEADERS, ['Компания']),
        ))));
        // 10 полей у каждой непустой строки, заголовок включая.
        self::assertSame([10, 10, 10, 10, 10, 10], $this->rawFieldCounts($path));
    }

    /**
     * @return int[] число сырых полей каждой непустой записи, включая заголовок
     */
    private function rawFieldCounts(string $path): array
    {
        $handle = fopen($path, 'r');
        $counts = [];
        while (false !== $fields = fgetcsv($handle, 0, ',', '"', '')) {
            if ([] === array_filter($fields, static fn(?string $f): bool => '' !== trim((string) $f))) {
                continue;
            }
            $counts[] = \count($fields);
        }
        fclose($handle);

        return $counts;
    }

    public function testCountRecordsMatchesTheRecordStream(): void
    {
        foreach (['valid.csv', 'backslash.csv', 'interactions.csv', 'next-contact.csv'] as $name) {
            $path = $this->fixture($name);

            self::assertSame(
                iterator_count($this->parser->records($path)),
                $this->parser->countRecords($path),
                $name,
            );
        }
    }

    public function testUsableFilePassesEveryCheck(): void
    {
        $this->expectNotToPerformAssertions();

        $this->parser->assertUsable($this->fixture('valid.csv'));
    }

    public function testRecordTextIsIndependentOfFileColumnOrder(): void
    {
        $a = $this->tempFile(implode(',', [...CsvParser::HEADERS, '']) . "\nНафтан,Курс,,,,,,,,\n");
        $b = $this->tempFile(
            implode(',', array_reverse([...CsvParser::HEADERS, ''])) . "\n,,,,,,,Курс,Нафтан\n",
        );

        $recordA = iterator_to_array($this->parser->records($a))[0];
        $recordB = iterator_to_array($this->parser->records($b))[0];

        self::assertSame(
            CsvParser::recordText($recordA),
            CsvParser::recordText($recordB),
        );
    }

    private function fixture(string $name): string
    {
        return \dirname(__DIR__, 2) . '/fixtures/import/' . $name;
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
