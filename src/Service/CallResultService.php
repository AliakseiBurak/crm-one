<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Call;
use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Organization;
use App\Repository\CampaignRecipientRepository;
use App\Repository\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Побочные эффекты действий результата звонка на CampaignRecipient
 * (change call-result).
 */
final class CallResultService
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignRecipientRepository $recipients,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Кампании для действия «рассылка»: все статусы кроме archived.
     *
     * @return Campaign[]
     */
    public function findMailableCampaigns(): array
    {
        return $this->campaigns->createQueryBuilder('c')
            ->where('c.status != :archived')
            ->setParameter('archived', CampaignStatus::Archived)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findMailableCampaign(int $id): ?Campaign
    {
        $campaign = $this->campaigns->find($id);
        if (null === $campaign) {
            return null;
        }

        if (CampaignStatus::Archived === $campaign->status) {
            return null;
        }

        return $campaign;
    }

    /**
     * Создать или заменить адресата. Возвращает true, если нужен flash о resend.
     */
    public function upsertRecipient(Campaign $campaign, Organization $organization, ?Contact $contact): bool
    {
        $hasEmail = false;
        foreach ($organization->contacts as $c) {
            if (null !== $c->email && '' !== $c->email) {
                $hasEmail = true;
                break;
            }
        }

        if (!$hasEmail) {
            return false;
        }

        $existing = $this->recipients->findOneBy([
            'campaign' => $campaign,
            'organization' => $organization,
        ]);

        if (null === $existing) {
            $this->em->persist(new CampaignRecipient($campaign, $organization, $contact));

            return false;
        }

        $shouldResend = null !== $campaign->launchedAt;
        $replacementCount = $shouldResend ? $existing->replacementCount + 1 : $existing->replacementCount;

        // Flush after remove so UNIQUE(campaign_id, organization_id) frees
        // the slot before the replacement row is inserted (SQLite/MySQL).
        $this->em->remove($existing);
        $this->em->flush();
        $this->em->persist(new CampaignRecipient($campaign, $organization, $contact, $replacementCount));

        return $shouldResend;
    }

    public function createNextCall(Call $call, \DateTimeImmutable $scheduledAt): Call
    {
        $nextCall = new Call()
            ->setOrganization($call->organization)
            ->setScheduledAt($scheduledAt);

        if (null !== $call->contact) {
            $nextCall->setContact($call->contact);
        }

        $this->em->persist($nextCall);
        $call->setNextCall($nextCall);

        return $nextCall;
    }
}
