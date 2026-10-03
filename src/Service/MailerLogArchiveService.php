<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * Архивирование журнала отправки писем по годам (change email-send-logging,
 * ADR-0016, design D5): файлы `mailer-YYYY-MM.log` годов, отстающих от
 * текущего на два года и более, сливаются в `mailer-YYYY.zip`, после чего
 * исходные файлы удаляются.
 *
 * Ключевая асимметрия: архивирование не удаляет то, что не удалось сжать —
 * сначала закрытие архива с проверкой результата, и только потом `unlink`.
 * Существующий архив года дописывается, а не пересоздаётся, поэтому
 * архивирование идемпотентно по составу записей внутри архива.
 */
final readonly class MailerLogArchiveService
{
    /**
     * Привязанное регулярное выражение, а не маска `mailer-*.log`: в том же
     * каталоге лежат `test-mailer-*.log`, и маска унесла бы их в архив года.
     */
    private const string MONTH_FILE = '/^mailer-(\d{4})-(\d{2})\.log$/';

    /** Год архивируется, когда он не новее «текущий минус два». */
    private const int ARCHIVE_AFTER_YEARS = 2;

    public function __construct(
        #[Autowire(param: 'mailer.log_dir')]
        private string $logDir,
    ) {}

    /**
     * Архивирует все подходящие годы. Возвращает число удалённых файлов
     * месяцев: сбой на закрытии архива оставляет `.log` на месте, и следующий
     * запуск повторяет попытку.
     */
    public function archive(): int
    {
        $lastArchivedYear = (int) date('Y') - self::ARCHIVE_AFTER_YEARS;

        $archived = 0;
        foreach ($this->groupByYear() as $year => $files) {
            if ($year > $lastArchivedYear) {
                continue;
            }

            $archived += $this->archiveYear((string) $year, $files);
        }

        return $archived;
    }

    /**
     * @return array<int, list<string>>
     */
    private function groupByYear(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $byYear = [];
        foreach ((array) scandir($this->logDir) as $entry) {
            if (!\is_string($entry) || 1 !== preg_match(self::MONTH_FILE, $entry, $matches)) {
                continue;
            }

            $path = $this->logDir . '/' . $entry;
            // Каталог с именем файла журнала — не файл журнала. Без проверки он
            // не попал бы в архив, но сорвал бы закрытие архива и остановил
            // архивирование года целиком, а год с обычными файлами при этом
            // тихо остался бы несжатым.
            if (!is_file($path)) {
                continue;
            }

            $byYear[(int) $matches[1]][] = $path;
        }

        ksort($byYear);

        return $byYear;
    }

    /**
     * @param list<string> $files
     */
    private function archiveYear(string $year, array $files): int
    {
        $zip = new ZipArchive();
        // Режим «только чтение» для существующего архива не подходит: новые
        // месяцы года должны в него дописываться.
        $opened = $zip->open($this->logDir . '/mailer-' . $year . '.zip', ZipArchive::CREATE);
        if (true !== $opened) {
            return 0;
        }

        $added = true;
        foreach ($files as $file) {
            // Сбой ввода-вывода — обрабатываемый исход, а не ошибка PHP:
            // сообщение выдаётся в журнал приложения этой веткой, поэтому
            // предупреждение самого ZipArchive подавляется.
            if (!@$zip->addFile($file, basename($file))) {
                $added = false;
                break;
            }
        }

        // close() возвращает true даже после неудачного addFile(), поэтому
        // неудачное добавление — тоже повод ничего не удалять: архив без
        // этой записи не восстановит удалённый месяц (D13).
        if (!$added || !@$zip->close()) {
            return 0;
        }

        $removed = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
