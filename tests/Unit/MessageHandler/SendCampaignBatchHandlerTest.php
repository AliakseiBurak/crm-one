<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SendCampaignBatch;
use App\MessageHandler\SendCampaignBatchHandler;
use App\Service\CampaignSendProcessor;
use PHPUnit\Framework\TestCase;

final class SendCampaignBatchHandlerTest extends TestCase
{
    public function testDelegatesToProcessorWithoutLimit(): void
    {
        $processor = $this->createMock(CampaignSendProcessor::class);
        $processor->expects(self::once())->method('process')->with(null);

        new SendCampaignBatchHandler($processor)(new SendCampaignBatch());
    }
}
