<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MailerLogArchiveService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Архивирование журнала отправки по годам (change email-send-logging, D5):
 * граничные годы, идемпотентность по составу записей **внутри** архива,
 * дописывание в существующий архив, безопасность сбоя на закрытии и
 * игнорирование посторонних файлов (`test-mailer-*.log`).
 */
final class MailerLogArchiveServiceTest extends TestCase
{
    private string $logDir;

    private MailerLogArchiveService $service;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/mailer-archive-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->logDir, 0777, true));
        $this->service = new MailerLogArchiveService($this->logDir);
    }

    protected function tearDown(): void
    {
        foreach ((array) scandir($this->logDir) as $entry) {
            if ('.' === $entry || '..' === $entry || !\is_string($entry)) {
                continue;
            }

            $path = $this->logDir . '/' . $entry;
            is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->logDir);
    }

    public function testArchivesYearOlderThanTwoYears(): void
    {
        $old = $this->year(-3);
        $this->monthFile($old, '01', "delivered {$old}-01");
        $this->monthFile($old, '02', "bounced {$old}-02");

        self::assertSame(2, $this->service->archive());

        self::assertFileExists($this->zipPath($old));
        self::assertFileDoesNotExist($this->monthPath($old, '01'));
        self::assertFileDoesNotExist($this->monthPath($old, '02'));
        self::assertSame(
            ["delivered {$old}-01", "bounced {$old}-02"],
            $this->entriesOfZip($old),
        );
    }

    public function testArchivesYearExactlyTwoYearsOlder(): void
    {
        $year = $this->year(-2);
        $this->monthFile($year, '12', "delivered {$year}-12");

        self::assertSame(1, $this->service->archive());

        self::assertFileExists($this->zipPath($year));
        self::assertSame(["delivered {$year}-12"], $this->entriesOfZip($year));
    }

    public function testKeepsCurrentAndPreviousYearUncompressed(): void
    {
        $previous = $this->year(-1);
        $current = $this->year(0);
        $this->monthFile($previous, '11', 'delivered previous');
        $this->monthFile($current, '10', 'delivered current');

        self::assertSame(0, $this->service->archive());

        self::assertFileDoesNotExist($this->zipPath($previous));
        self::assertFileDoesNotExist($this->zipPath($current));
        self::assertFileExists($this->monthPath($previous, '11'));
        self::assertFileExists($this->monthPath($current, '10'));
    }

    public function testYearWithoutLogFilesCreatesNoArchive(): void
    {
        self::assertSame(0, $this->service->archive());

        self::assertSame([], $this->zipFiles());
    }

    public function testRepeatedRunKeepsArchivedRecordsWithoutDuplicates(): void
    {
        $year = $this->year(-3);
        $this->monthFile($year, '01', "delivered {$year}-01");

        self::assertSame(1, $this->service->archive());
        $afterFirst = $this->entriesOfZip($year);

        // Повторный запуск в том же году: новых файлов месяцев нет.
        self::assertSame(0, $this->service->archive());

        self::assertSame($afterFirst, $this->entriesOfZip($year));
    }

    public function testNewMonthsAreAddedToExistingArchive(): void
    {
        $year = $this->year(-3);
        $this->monthFile($year, '01', "delivered {$year}-01");
        $this->service->archive();

        $this->monthFile($year, '02', "bounced {$year}-02");
        self::assertSame(1, $this->service->archive());

        // Архив дописан, а не пересоздан: ранее добавленная запись сохранена.
        self::assertSame(
            ["delivered {$year}-01", "bounced {$year}-02"],
            $this->entriesOfZip($year),
        );
        self::assertFileDoesNotExist($this->monthPath($year, '02'));
    }

    public function testIgnoresForeignFilesLikeTestMailerLogs(): void
    {
        $year = $this->year(-3);
        $this->monthFile($year, '01', "delivered {$year}-01");
        $this->writeFile("test-mailer-{$year}-02.log", 'delivered test probe');
        $this->writeFile("mailer-{$year}.zip.bak", 'чужой файл');

        $this->service->archive();

        self::assertFileExists($this->logDir . "/test-mailer-{$year}-02.log");
        self::assertFileExists($this->logDir . "/mailer-{$year}.zip.bak");
        self::assertSame(["delivered {$year}-01"], $this->entriesOfZip($year));
    }

    public function testMissingLogDirectoryIsNotAnError(): void
    {
        $service = new MailerLogArchiveService($this->logDir . '/absent');

        self::assertSame(0, $service->archive());
    }

    /**
     * Не-файлы с именем файла месяца (каталог, висячая ссылка) не архивируются
     * и, главное, **не останавливают** архивирование года: год с обычными
     * файлами сжимается обычным порядком.
     *
     * Проверка `is_file()` введена именно из-за этого наблюдения — до неё
     * каталог на месте файла месяца принимался `addFile()`, ронял `close()` и
     * оставлял весь год несжатым без всякой видимой причины.
     */
    public function testNonFilesNamedLikeMonthFilesAreIgnored(): void
    {
        $year = $this->year(-3);
        $this->monthFile($year, '01', "delivered {$year}-01");
        self::assertTrue(mkdir($this->monthPath($year, '02'), 0777, true));
        symlink($this->logDir . '/absent.log', $this->monthPath($year, '03'));

        self::assertSame(1, $this->service->archive());

        self::assertSame(["delivered {$year}-01"], $this->entriesOfZip($year));
        self::assertSame(["mailer-{$year}-01.log"], $this->zipEntryNames($year));
        self::assertDirectoryExists($this->monthPath($year, '02'));
        self::assertTrue(is_link($this->monthPath($year, '03')), 'Висячая ссылка остаётся на месте');
    }

    /**
     * Сбой при работе с архивом не должен удалять исходные файлы: сначала
     * архив закрывается и только потом `unlink`. Ветка `open()` воспроизводится
     * подменой архива посторонним содержимым.
     *
     * Проверки `addFile()` и `close()` в `archiveYear()` после ввода
     * `is_file()` тестом не воспроизводятся: объект, который проходит
     * `is_file()`, но не добавляется в архив, на обычной файловой системе
     * не создаётся. Оставлены как защита от гонки между сканированием каталога
     * и добавлением записи.
     */
    public function testUnopenableArchiveLeavesSourceLogsInPlace(): void
    {
        $year = $this->year(-3);
        $this->monthFile($year, '01', "delivered {$year}-01");
        // На месте архива — не zip, поэтому open() не удаётся.
        $this->writeFile("mailer-{$year}.zip", 'not a zip file');

        self::assertSame(0, $this->service->archive());

        self::assertFileExists($this->monthPath($year, '01'));
    }

    private function year(int $offset): int
    {
        return (int) date('Y') + $offset;
    }

    private function monthFile(int $year, string $month, string $line): string
    {
        return $this->writeFile("mailer-{$year}-{$month}.log", $line . "\n");
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->logDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function monthPath(int $year, string $month): string
    {
        return $this->logDir . "/mailer-{$year}-{$month}.log";
    }

    private function zipPath(int $year): string
    {
        return $this->logDir . "/mailer-{$year}.zip";
    }

    /**
     * @return list<string>
     */
    private function zipEntryNames(int $year): array
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($this->zipPath($year))) {
            return [];
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
        }
        $zip->close();

        return $names;
    }

    /**
     * @return list<string>
     */
    private function zipFiles(): array
    {
        $found = [];
        foreach ((array) scandir($this->logDir) as $entry) {
            if (\is_string($entry) && str_starts_with($entry, 'mailer-') && str_ends_with($entry, '.zip')) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * Содержимое архива — по строкам, а не по байтам: архиватор дописывает в
     * существующий zip, поэтому байты и время изменения файла меняются законно.
     *
     * @return list<string>
     */
    private function entriesOfZip(int $year): array
    {
        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($this->zipPath($year)));

        $lines = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $contents = (string) $zip->getFromIndex($i);
            foreach (array_filter(explode("\n", trim($contents))) as $line) {
                $lines[] = $line;
            }
        }
        $zip->close();

        return $lines;
    }
}
