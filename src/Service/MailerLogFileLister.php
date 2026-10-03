<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MailerLogFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перечень файлов журнала отправки писем (change email-send-logging, D8).
 *
 * Работает только с именами файлов и их размером: содержимое журнала
 * приложение не читает и не отображает. Имена сопоставляются **привязанными**
 * регулярными выражениями, а не маской `mailer-*.log`, — в том же каталоге
 * лежат `test-mailer-*.log`, и маска выдала бы их за несуществующие периоды.
 *
 * Отсутствующий каталог трактуется как «файлов нет» и даёт пустой список, а не
 * исключение: страница — операторский инструмент, и её поломка из-за
 * конфигурации окружения должна быть видна как отсутствие файлов.
 */
final readonly class MailerLogFileLister
{
    /** Файл месяца: `mailer-YYYY-MM.log`. */
    private const string MONTH_FILE = '/^mailer-(\d{4})-(\d{2})\.log$/';

    /** Архив года: `mailer-YYYY.zip`. */
    private const string YEAR_FILE = '/^mailer-(\d{4})\.zip$/';

    public function __construct(
        #[Autowire(param: 'mailer.log_dir')]
        private string $logDir,
    ) {}

    /**
     * @return list<MailerLogFile> новые периоды сверху, архив года последним
     *                               среди позиций своего года
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->entries() as $entry) {
            $file = $this->describe($entry);
            if (null !== $file) {
                $files[] = $file;
            }
        }

        usort(
            $files,
            static fn(MailerLogFile $a, MailerLogFile $b): int => strcmp($b->sortKey(), $a->sortKey()),
        );

        return $files;
    }

    /**
     * Имя файла журнала по периоду: месяц `^\d{4}-\d{2}$` даёт
     * `mailer-<период>.log`, год `^\d{4}$` даёт `mailer-<год>.zip`. Период
     * проверяется **до** составления имени, поэтому путь из запроса
     * использовать негде, а обход каталога невозможен по построению (D9).
     */
    public function resolveMonth(string $period): ?string
    {
        return 1 === preg_match('/^\d{4}-\d{2}$/', $period)
            ? $this->pathFor('mailer-' . $period . '.log')
            : null;
    }

    public function resolveYear(string $year): ?string
    {
        return 1 === preg_match('/^\d{4}$/', $year)
            ? $this->pathFor('mailer-' . $year . '.zip')
            : null;
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $entries = scandir($this->logDir);
        if (false === $entries) {
            return [];
        }

        return array_values(array_filter($entries, is_string(...)));
    }

    private function describe(string $entry): ?MailerLogFile
    {
        // Каталог с именем файла журнала — не файл журнала: без проверки он
        // дал бы строку перечня, которую нельзя ни скачать, ни удалить.
        $path = $this->pathFor($entry);
        if (!is_file($path)) {
            return null;
        }

        if (1 === preg_match(self::MONTH_FILE, $entry, $matches)) {
            return new MailerLogFile(
                $matches[1] . '-' . $matches[2],
                MailerLogFile::KIND_MONTH,
                $entry,
                $this->sizeOf($entry),
            );
        }

        if (1 === preg_match(self::YEAR_FILE, $entry, $matches)) {
            return new MailerLogFile(
                $matches[1],
                MailerLogFile::KIND_YEAR,
                $entry,
                $this->sizeOf($entry),
            );
        }

        return null;
    }

    private function pathFor(string $filename): string
    {
        return $this->logDir . '/' . $filename;
    }

    private function sizeOf(string $entry): int
    {
        $size = @filesize($this->pathFor($entry));

        return false === $size ? 0 : $size;
    }
}
