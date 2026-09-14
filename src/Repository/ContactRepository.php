<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Все контакты выбранных организаций одним запросом (без N+1).
     *
     * @param int[] $organizationIds
     *
     * @return Contact[]
     */
    public function findByOrganizations(array $organizationIds): array
    {
        if ([] === $organizationIds) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->join('c.organization', 'o')
            ->where('o.id IN (:organizationIds)')
            ->setParameter('organizationIds', $organizationIds)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
