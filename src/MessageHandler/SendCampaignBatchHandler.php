<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendCampaignBatch;
use App\Service\CampaignSendProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendCampaignBatchHandler
{
    public function __construct(
        private CampaignSendProcessor $processor,
    ) {}

    public function __invoke(SendCampaignBatch $message): void
    {
        $this->processor->process();
    }
}
