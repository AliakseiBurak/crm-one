<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ImportRun;

/**
 * Записи прогона в его собственном формате (design D6).
 *
 * Единая точка, где прогон раскрывается в «строку N»: `ImportProcessor` берёт
 * пакет отсюда, а `ImportController` — из отчёта о замене файла. Раньше обе
 * точки знали про `CsvParser` напрямую, и прогон из JSON нельзя было ни открыть,
 * ни заменить.
 *
 * Ключ массива — номер строки от нуля, то есть тот самый, по которому
 * `processedRows` считает границы пакета и строку продолжения.
 */
final class ImportRunReader
{
    public function __construct(
        private readonly CsvParser $csv,
        private readonly JsonImportParser $json,
        private readonly ImportJsonSchema $schema,
    ) {}

    /**
     * Строки прогона по номеру от нуля.
     *
     * @return iterable<int, mixed>
     */
    public function records(string $sourceFormat, string $path): iterable
    {
        if ($this->isJson($sourceFormat)) {
            foreach ($this->json->organizations($this->decode($path)) as $index => $organization) {
                yield $index => $organization;
            }

            return;
        }

        yield from $this->csv->records($path);
    }

    /**
     * Число строк прогона — `totalRows`, который считается тем же способом, каким
     * строки потом читаются.
     */
    public function count(string $sourceFormat, string $path): int
    {
        $count = 0;
        foreach ($this->records($sourceFormat, $path) as $_) {
            ++$count;
        }

        return $count;
    }

    /**
     * Сравнение двух строк по правилам их формата.
     *
     * Для CSV строка — это запись, и она сравнивается как текст: так строка
     * определена в формате источника. Для JSON строка — объект, и она
     * сравнивается по полям, поэтому перестановка ключей и другая запись
     * payload не выглядят изменением данных. Текстовое сравнение двух
     * JSON-ответов сообщало бы, что изменилось всё, когда не изменилось ничего.
     */
    public function equals(string $sourceFormat, mixed $current, mixed $candidate): bool
    {
        return $this->signature($sourceFormat, $current) === $this->signature($sourceFormat, $candidate);
    }

    /**
     * Название организации в строке — для строки продолжения в отчёте о замене.
     */
    public function organizationName(string $sourceFormat, mixed $row): string
    {
        if ($this->isJson($sourceFormat)) {
            $name = $this->json->rowSignature($row)['name'] ?? null;

            return \is_string($name) ? $name : '';
        }

        return \is_array($row) ? CsvParser::organizationName($row) : '';
    }

    private function signature(string $sourceFormat, mixed $row): string
    {
        if ($this->isJson($sourceFormat)) {
            return (string) json_encode(
                $this->json->rowSignature($row),
                JSON_UNESCAPED_UNICODE,
            );
        }

        return \is_array($row) ? CsvParser::recordText($row) : '';
    }

    private function decode(string $path): mixed
    {
        $contents = @file_get_contents($path);

        return false === $contents ? null : $this->schema->decode($contents);
    }

    private function isJson(string $sourceFormat): bool
    {
        return ImportRun::SOURCE_FORMAT_JSON === $sourceFormat;
    }
}
