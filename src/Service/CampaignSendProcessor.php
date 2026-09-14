<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CampaignRecipientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

class CampaignSendProcessor
{
    public const string LOCK_RESOURCE = 'app.campaign.send';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignRecipientRepository $recipientRepository,
        private readonly MailingService $mailingService,
        private readonly LockFactory $lockFactory,
        #[Autowire(param: 'mailing.batch_size')]
        private readonly int $batchSize,
    ) {}

    /**
     * Обработать очередной батч получателей.
     *
     * @return int|null число обработанных получателей либо null, если lock не взят
     */
    public function process(?int $limit = null): ?int
    {
        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $batchSize = $limit ?? $this->batchSize;
            $ids = $this->recipientRepository->findPendingIdsForSend($batchSize);
            $remaining = $batchSize - \count($ids);

            if ($remaining > 0) {
                $ids = array_merge(
                    $ids,
                    $this->recipientRepository->findRetryIdsForSend($remaining),
                );
            }

            $processed = 0;
            foreach ($ids as $id) {
                $recipient = $this->recipientRepository->find($id);
                if (null === $recipient) {
                    continue;
                }

                $this->mailingService->processRecipient($recipient);
                ++$processed;
                $this->em->clear();
            }

            return $processed;
        } finally {
            $lock->release();
        }
    }
}
