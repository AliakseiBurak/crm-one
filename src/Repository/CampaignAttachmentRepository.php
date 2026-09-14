<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CampaignAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampaignAttachment>
 */
class CampaignAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignAttachment::class);
    }
}
