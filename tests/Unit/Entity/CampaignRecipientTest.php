<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

final class CampaignRecipientTest extends TestCase
{
    public function testNewRecipientIsPendingWithTrackingToken(): void
    {
        $recipient = $this->recipient();

        self::assertSame(RecipientStatus::Pending, $recipient->status);
        self::assertNotNull($recipient->trackingToken);
        self::assertSame(64, \strlen((string) $recipient->trackingToken));
        self::assertSame(0, $recipient->retryCount);
        self::assertNull($recipient->retryAt);
        self::assertNull($recipient->errorMessage);
    }

    public function testMarkDeliveredClearsErrorAndSetsSentAt(): void
    {
        $recipient = $this->recipient();
        $recipient->markFailed('временно', true);
        $recipient->markDelivered();

        self::assertSame(RecipientStatus::Delivered, $recipient->status);
        self::assertNotNull($recipient->sentAt);
        self::assertNull($recipient->errorMessage);
    }

    public function testMarkBouncedIsPermanent(): void
    {
        $recipient = $this->recipient();
        $recipient->markBounced('550');

        self::assertSame(RecipientStatus::Bounced, $recipient->status);
        self::assertSame('550', $recipient->errorMessage);
        self::assertNotNull($recipient->sentAt);
        self::assertFalse($recipient->isRetriable());
    }

    public function testTransientFailureSchedulesRetryUntilThirdAttempt(): void
    {
        $recipient = $this->recipient();

        $recipient->markFailed('timeout', true);
        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(1, $recipient->retryCount);
        self::assertNotNull($recipient->retryAt);
        self::assertFalse($recipient->isRetriable());

        $recipient->markFailed('timeout', true);
        self::assertSame(2, $recipient->retryCount);
        self::assertNotNull($recipient->retryAt);

        $recipient->markFailed('timeout', true);
        self::assertSame(3, $recipient->retryCount);
        self::assertNull($recipient->retryAt);
        self::assertFalse($recipient->isRetriable());
    }

    public function testPermanentFailureDoesNotIncrementRetry(): void
    {
        $recipient = $this->recipient();
        $recipient->markFailed('Отсутствует email-адрес организации/контакта', false);

        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(0, $recipient->retryCount);
        self::assertNull($recipient->retryAt);
        self::assertFalse($recipient->isRetriable());
    }

    public function testIsRetriableWhenRetryAtHasPassed(): void
    {
        $recipient = $this->recipient();
        $recipient->markFailed('timeout', true);

        $retryAt = new \ReflectionProperty($recipient, 'retryAt');
        $retryAt->setValue($recipient, new \DateTimeImmutable('-1 second'));

        self::assertTrue($recipient->isRetriable());
    }

    public function testMarkOpened(): void
    {
        $recipient = $this->recipient();
        $recipient->markDelivered();
        $recipient->markOpened();

        self::assertSame(RecipientStatus::Opened, $recipient->status);
    }

    private function recipient(): CampaignRecipient
    {
        $campaign = new Campaign()->setName('Акция');
        $org = new Organization()->setName('ООО Ромашка');

        return new CampaignRecipient($campaign, $org);
    }
}
