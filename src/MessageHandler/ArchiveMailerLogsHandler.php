<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ArchiveMailerLogs;
use App\Service\MailerLogArchiveService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ArchiveMailerLogsHandler
{
    public function __construct(
        private MailerLogArchiveService $archiveService,
    ) {}

    public function __invoke(ArchiveMailerLogs $message): void
    {
        $this->archiveService->archive();
    }
}
