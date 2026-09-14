<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Organization;
use App\Repository\CampaignRecipientRepository;
use App\Service\CampaignSendProcessor;
use App\Service\MailingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class CampaignSendProcessorTest extends TestCase
{
    public function testReturnsNullWhenLockIsBusy(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');

        $mailing = $this->createMock(MailingService::class);
        $mailing->expects(self::never())->method('processRecipient');

        $processed = $this->processor(
            $lock,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(CampaignRecipientRepository::class),
            $mailing,
        )->process();

        self::assertNull($processed);
    }

    public function testLimitOverridesBatchSizeAndProcessesPendingThenRetries(): void
    {
        $pending = $this->recipient();
        $retry = $this->recipient();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear');

        $repo = $this->createMock(CampaignRecipientRepository::class);
        $repo->method('findPendingIdsForSend')->with(2)->willReturn([11]);
        $repo->method('findRetryIdsForSend')->with(1)->willReturn([22]);
        $repo->method('find')->willReturnCallback(static fn(mixed $id): ?CampaignRecipient => match ($id) {
            11 => $pending,
            22 => $retry,
            default => null,
        });

        $mailing = $this->createMock(MailingService::class);
        $mailing->expects(self::exactly(2))->method('processRecipient')->willReturnCallback(
            static function (CampaignRecipient $recipient) use ($pending, $retry): void {
                self::assertContains($recipient, [$pending, $retry]);
            },
        );

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');

        $processed = $this->processor($lock, $em, $repo, $mailing, 10)->process(2);

        self::assertSame(2, $processed);
    }

    public function testDoesNotFetchRetriesWhenPendingFillsTheBatch(): void
    {
        $first = $this->recipient();
        $second = $this->recipient();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear');

        $repo = $this->createMock(CampaignRecipientRepository::class);
        $repo->method('findPendingIdsForSend')->with(2)->willReturn([1, 2]);
        $repo->expects(self::never())->method('findRetryIdsForSend');
        $repo->method('find')->willReturnCallback(static fn(mixed $id): ?CampaignRecipient => match ($id) {
            1 => $first,
            2 => $second,
            default => null,
        });

        $mailing = $this->createMock(MailingService::class);
        $mailing->expects(self::exactly(2))->method('processRecipient');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        self::assertSame(2, $this->processor($lock, $em, $repo, $mailing)->process(2));
    }

    public function testSkipsMissingRecipients(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('clear');

        $repo = $this->createMock(CampaignRecipientRepository::class);
        $repo->method('findPendingIdsForSend')->with(10)->willReturn([99]);
        $repo->method('findRetryIdsForSend')->willReturn([]);
        $repo->method('find')->with(99)->willReturn(null);

        $mailing = $this->createMock(MailingService::class);
        $mailing->expects(self::never())->method('processRecipient');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        self::assertSame(0, $this->processor($lock, $em, $repo, $mailing)->process());
    }

    private function processor(
        SharedLockInterface $lock,
        EntityManagerInterface $em,
        CampaignRecipientRepository $repo,
        MailingService $mailing,
        int $batchSize = 10,
    ): CampaignSendProcessor {
        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        return new CampaignSendProcessor($em, $repo, $mailing, $factory, $batchSize);
    }

    private function recipient(): CampaignRecipient
    {
        return new CampaignRecipient(
            new Campaign()->setName('Акция'),
            new Organization()->setName('ООО Ромашка'),
        );
    }
}
