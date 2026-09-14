<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampaignRecipient>
 */
class CampaignRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignRecipient::class);
    }

    /**
     * @return list<int>
     */
    public function findPendingIdsForSend(int $limit): array
    {
        return $this->idsForSend($limit, RecipientStatus::Pending);
    }

    /**
     * Failed-получатели, готовые к повторной попытке (retry_at <= NOW, retry_count < 3).
     *
     * @return list<int>
     */
    public function findRetryIdsForSend(int $limit): array
    {
        /** @var list<int> $ids */
        $ids = $this->createQueryBuilder('cr')
            ->select('cr.id')
            ->innerJoin('cr.campaign', 'c')
            ->where('c.status = :launched')
            ->andWhere('cr.status = :failed')
            ->andWhere('cr.retryAt <= :now')
            ->andWhere('cr.retryCount < 3')
            ->setParameter('launched', CampaignStatus::Launched->value)
            ->setParameter('failed', RecipientStatus::Failed->value)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    public function countStillProcessing(int $campaignId): int
    {
        return (int) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('IDENTITY(cr.campaign) = :campaignId')
            ->andWhere(
                'cr.status IN (:open) OR (cr.status = :failed AND cr.retryCount < 3 AND cr.retryAt IS NOT NULL)',
            )
            ->setParameter('campaignId', $campaignId)
            ->setParameter('open', [
                RecipientStatus::Pending->value,
                RecipientStatus::Sending->value,
            ])
            ->setParameter('failed', RecipientStatus::Failed->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDeliveredOrOpened(int $campaignId): int
    {
        return (int) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('IDENTITY(cr.campaign) = :campaignId')
            ->andWhere('cr.status IN (:ok)')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('ok', [
                RecipientStatus::Delivered->value,
                RecipientStatus::Opened->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCampaign(int $campaignId): int
    {
        return (int) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('IDENTITY(cr.campaign) = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNoEmailFailures(int $campaignId): int
    {
        return (int) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('IDENTITY(cr.campaign) = :campaignId')
            ->andWhere('cr.status = :failed')
            ->andWhere('cr.errorMessage LIKE :prefix')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('failed', RecipientStatus::Failed->value)
            ->setParameter('prefix', 'Отсутствует email%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ошибки доставки для контакта: recipients с данным contact, статус failed/bounced,
     * рассылка не archived. Только по контакту (org-wide не включаются).
     *
     * @return CampaignRecipient[]
     */
    public function findErrorRecipientsForContact(Contact $contact): array
    {
        return $this->createQueryBuilder('cr')
            ->innerJoin('cr.campaign', 'c')
            ->where('cr.contact = :contact')
            ->andWhere('cr.status IN (:statuses)')
            ->andWhere('c.status != :archived')
            ->setParameter('contact', $contact)
            ->setParameter('statuses', [
                RecipientStatus::Failed->value,
                RecipientStatus::Bounced->value,
            ])
            ->setParameter('archived', CampaignStatus::Archived->value)
            ->orderBy('cr.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ошибки доставки для организации: все recipients организации (org-wide и с контактом),
     * статус failed/bounced, рассылка не archived.
     *
     * @return CampaignRecipient[]
     */
    public function findErrorRecipientsForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('cr')
            ->innerJoin('cr.campaign', 'c')
            ->where('cr.organization = :organization')
            ->andWhere('cr.status IN (:statuses)')
            ->andWhere('c.status != :archived')
            ->setParameter('organization', $organization)
            ->setParameter('statuses', [
                RecipientStatus::Failed->value,
                RecipientStatus::Bounced->value,
            ])
            ->setParameter('archived', CampaignStatus::Archived->value)
            ->orderBy('cr.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Агрегат статистики по всем рассылкам: delivered (delivered+opened), total
     * и статус для каждой кампании, у которой есть хотя бы один получатель.
     *
     * @return list<array{campaignId: int, delivered: int, total: int, status: string, label: string}>
     */
    public function statsForAllCampaigns(): array
    {
        $rows = $this->createQueryBuilder('cr')
            ->select(
                'IDENTITY(cr.campaign) AS campaignId',
                'c.status AS campaignStatus',
                'COUNT(cr.id) AS total',
                'SUM(CASE WHEN cr.status IN (:delivered) THEN 1 ELSE 0 END) AS delivered',
            )
            ->innerJoin('cr.campaign', 'c')
            ->groupBy('cr.campaign')
            ->addOrderBy('c.id', 'ASC')
            ->setParameter('delivered', [
                RecipientStatus::Delivered->value,
                RecipientStatus::Opened->value,
            ])
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $enum = $row['campaignStatus'] instanceof CampaignStatus
                ? $row['campaignStatus']
                : CampaignStatus::from($row['campaignStatus']);
            $result[] = [
                'campaignId' => (int) $row['campaignId'],
                'delivered' => (int) $row['delivered'],
                'total' => (int) $row['total'],
                'status' => $enum->value,
                'label' => $enum->label(),
            ];
        }

        return $result;
    }

    /**
     * Статусы всех адресатов рассылки с лейблами из RecipientStatus.
     *
     * @return list<array{recipientId: int, status: string, label: string}>
     */
    public function findStatusesForCampaign(int $campaignId): array
    {
        $rows = $this->createQueryBuilder('cr')
            ->select('cr.id AS recipientId', 'cr.status')
            ->where('IDENTITY(cr.campaign) = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->orderBy('cr.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $enum = $row['status'] instanceof RecipientStatus ? $row['status'] : RecipientStatus::from($row['status']);
            $result[] = [
                'recipientId' => (int) $row['recipientId'],
                'status' => $enum->value,
                'label' => $enum->label(),
            ];
        }

        return $result;
    }

    /**
     * Есть ли у контакта bounced в неархивной рассылке (для отметки на дашборде).
     */
    public function hasBouncedForContact(Contact $contact): bool
    {
        return (bool) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->innerJoin('cr.campaign', 'c')
            ->where('cr.contact = :contact')
            ->andWhere('cr.status = :bounced')
            ->andWhere('c.status != :archived')
            ->setParameter('contact', $contact)
            ->setParameter('bounced', RecipientStatus::Bounced->value)
            ->setParameter('archived', CampaignStatus::Archived->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    private function idsForSend(int $limit, RecipientStatus $status): array
    {
        /** @var list<int> $ids */
        $ids = $this->createQueryBuilder('cr')
            ->select('cr.id')
            ->innerJoin('cr.campaign', 'c')
            ->where('c.status = :launched')
            ->andWhere('cr.status = :status')
            ->setParameter('launched', CampaignStatus::Launched->value)
            ->setParameter('status', $status->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
