<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\ArchiveMailerLogs;
use App\MessageHandler\ArchiveMailerLogsHandler;
use App\Service\MailerLogArchiveService;
use PHPUnit\Framework\TestCase;

/**
 * Обработчик задания архивирования журнала отправки (change
 * email-send-logging, 4.4): работа целиком делегируется сервису. Сервис
 * объявлен final, поэтому делегирование проверяется по результату, а не
 * заглушкой.
 */
final class ArchiveMailerLogsHandlerTest extends TestCase
{
    public function testDelegatesToArchiveService(): void
    {
        $logDir = sys_get_temp_dir() . '/mailer-archive-handler-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($logDir, 0777, true));
        $oldYear = (int) date('Y') - 3;
        $monthFile = $logDir . "/mailer-{$oldYear}-01.log";
        file_put_contents($monthFile, "delivered {$oldYear}-01\n");

        try {
            (new ArchiveMailerLogsHandler(new MailerLogArchiveService($logDir)))(new ArchiveMailerLogs());

            self::assertFileExists($logDir . "/mailer-{$oldYear}.zip");
            self::assertFileDoesNotExist($monthFile);
        } finally {
            foreach ((array) scandir($logDir) as $entry) {
                if ('.' !== $entry && '..' !== $entry && \is_string($entry)) {
                    @unlink($logDir . '/' . $entry);
                }
            }
            @rmdir($logDir);
        }
    }
}
