<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationHide>
 */
class OrganizationHideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationHide::class);
    }

    /**
     * Все записи скрытия организации (spec organization-hiding: реестр
     * «Показать всем»).
     *
     * @return OrganizationHide[]
     */
    public function findForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('h.hiddenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все организации, скрытые от менеджера.
     *
     * @return OrganizationHide[]
     */
    public function findForManager(User $manager): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.manager = :manager')
            ->setParameter('manager', $manager)
            ->orderBy('h.hiddenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrganizationAndManager(Organization $organization, User $manager): ?OrganizationHide
    {
        return $this->createQueryBuilder('h')
            ->where('h.organization = :organization')
            ->andWhere('h.manager = :manager')
            ->setParameter('organization', $organization)
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
