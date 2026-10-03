<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\MailerLogFile;
use App\Service\MailerLogFileLister;
use PHPUnit\Framework\TestCase;

/**
 * Перечень файлов журнала отправки (change email-send-logging, D8, 5.1–5.3):
 * сортировка от новых к старым с архивом года последним, размеры, отсутствие
 * каталога, игнорирование посторонних файлов (`test-mailer-*.log`) и
 * разрешение файла по периоду.
 */
final class MailerLogFileListerTest extends TestCase
{
    private string $logDir;

    private MailerLogFileLister $lister;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/mailer-lister-test-' . bin2hex(random_bytes(8));
        $this->lister = new MailerLogFileLister($this->logDir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->logDir)) {
            return;
        }

        foreach ((array) scandir($this->logDir) as $entry) {
            if ('.' === $entry || '..' === $entry || !\is_string($entry)) {
                continue;
            }

            $path = $this->logDir . '/' . $entry;
            is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->logDir);
    }

    public function testEmptyDirectoryYieldsNoFiles(): void
    {
        self::assertTrue(mkdir($this->logDir, 0777, true));

        self::assertSame([], $this->lister->files());
    }

    public function testMissingDirectoryYieldsEmptyListInsteadOfException(): void
    {
        self::assertDirectoryDoesNotExist($this->logDir);

        self::assertSame([], $this->lister->files());
    }

    public function testSortsFromNewestPeriodToOldest(): void
    {
        $this->touch('mailer-2026-03.log');
        $this->touch('mailer-2026-11.log');
        $this->touch('mailer-2025-12.log');
        $this->touch('mailer-2027-01.log');

        self::assertSame(
            ['2027-01', '2026-11', '2026-03', '2025-12'],
            array_map(static fn(MailerLogFile $f): string => $f->period, $this->lister->files()),
        );
    }

    public function testYearArchiveGoesAfterMonthsOfSameYear(): void
    {
        $this->touch('mailer-2026.zip');
        $this->touch('mailer-2026-12.log');
        $this->touch('mailer-2026-11.log');
        $this->touch('mailer-2025-12.log');
        $this->touch('mailer-2025.zip');

        self::assertSame(
            ['2026-12', '2026-11', '2026', '2025-12', '2025'],
            array_map(static fn(MailerLogFile $f): string => $f->period, $this->lister->files()),
        );
    }

    public function testPartiallyArchivedYearShowsBothArchiveAndMonths(): void
    {
        $this->touch('mailer-2026.zip');
        $this->touch('mailer-2026-05.log');

        $files = $this->lister->files();

        self::assertCount(2, $files);
        self::assertSame('2026-05', $files[0]->period);
        self::assertTrue($files[0]->isMonth());
        self::assertFalse($files[1]->isMonth());
        self::assertSame('mailer-2026.zip', $files[1]->filename);
    }

    public function testReportsFileSizeIncludingZeroOfEmptyFile(): void
    {
        $this->touch('mailer-2026-01.log', '');
        $this->touch('mailer-2026-02.log', str_repeat('x', 2048));

        $byPeriod = [];
        foreach ($this->lister->files() as $file) {
            $byPeriod[$file->period] = $file;
        }

        self::assertSame(0, $byPeriod['2026-01']->size);
        self::assertSame('0 Б', $byPeriod['2026-01']->formattedSize());
        self::assertSame(2048, $byPeriod['2026-02']->size);
        self::assertSame('2.0 КБ', $byPeriod['2026-02']->formattedSize());
    }

    public function testIgnoresForeignFilesIncludingTestMailerLogs(): void
    {
        $this->touch('mailer-2026-01.log');
        $this->touch('test-mailer-2026-02.log');
        $this->touch('dev.log');
        $this->touch('mailer-2026.log');
        $this->touch('mailer-2026-03.zip');

        self::assertSame(['2026-01'], array_map(
            static fn(MailerLogFile $f): string => $f->period,
            $this->lister->files(),
        ));
    }

    public function testIgnoresDirectoriesNamedLikeLogFiles(): void
    {
        $this->touch('mailer-2026-01.log');
        // Каталог с именем файла журнала — не файл: строка такого периода была
        // бы в перечне, но ни скачать, ни удалить её нельзя.
        self::assertTrue(mkdir($this->logDir . '/mailer-2026-02.log', 0777, true));
        self::assertTrue(mkdir($this->logDir . '/mailer-2025.zip', 0777, true));

        self::assertSame(['2026-01'], array_map(
            static fn(MailerLogFile $f): string => $f->period,
            $this->lister->files(),
        ));
    }

    public function testResolvesMonthPeriodToExistingLogFile(): void
    {
        $this->touch('mailer-2026-03.log');

        self::assertSame(
            $this->logDir . '/mailer-2026-03.log',
            $this->lister->resolveMonth('2026-03'),
        );
    }

    public function testResolvesYearPeriodToExistingArchive(): void
    {
        $this->touch('mailer-2026.zip');

        self::assertSame(
            $this->logDir . '/mailer-2026.zip',
            $this->lister->resolveYear('2026'),
        );
    }

    public function testRejectsNonNumericPeriods(): void
    {
        self::assertNull($this->lister->resolveMonth('2026-3'));
        self::assertNull($this->lister->resolveMonth('202603'));
        self::assertNull($this->lister->resolveMonth('../mailer'));
        self::assertNull($this->lister->resolveMonth('2026-03.log'));
        self::assertNull($this->lister->resolveYear('2026-03'));
        self::assertNull($this->lister->resolveYear('..'));
        self::assertNull($this->lister->resolveYear('mailer-2026.zip'));
    }

    public function testResolvedPathsIgnoreExistingForeignFiles(): void
    {
        $this->touch('test-mailer-2026-02.log');
        $this->touch('mailer-2026.zip.bak');

        self::assertSame($this->logDir . '/mailer-2026-02.log', $this->lister->resolveMonth('2026-02'));
        self::assertSame($this->logDir . '/mailer-2026.zip', $this->lister->resolveYear('2026'));
    }

    private function touch(string $name, string $contents = 'x'): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        file_put_contents($this->logDir . '/' . $name, $contents);
    }
}
