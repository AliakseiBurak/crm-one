<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Organization;
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
     * Все контакты выбранных организаций одним запросом (без N+1),
     * по возрастанию ID (порядок добавления).
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
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Контакты одной организации по возрастанию ID (порядок добавления).
     *
     * @return Contact[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.organization = :organization')
            ->setParameter('organization', $organization->id)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Эффективный главный контакт среди переданного списка: isMain = true;
     * при отсутствии такого контакта или при нескольких — контакт с
     * минимальным ID. Позиция в списке не учитывается.
     *
     * @param Contact[] $contacts
     */
    public function findEffectiveMainAmong(array $contacts): ?Contact
    {
        $mains = array_values(array_filter($contacts, static fn(Contact $contact): bool => $contact->isMain));
        $candidates = [] !== $mains ? $mains : $contacts;
        $main = null;
        foreach ($candidates as $contact) {
            if (null === $main || $this->idOf($contact) < $this->idOf($main)) {
                $main = $contact;
            }
        }

        return $main;
    }

    /**
     * Контакты организации с isMain = true, по возрастанию ID (нормализация
     * аномалии «несколько основных»).
     *
     * @return Contact[]
     */
    public function findIsMainContacts(Organization $organization): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.organization = :organization')
            ->andWhere('c.isMain = true')
            ->setParameter('organization', $organization->id)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Сброс isMain у всех контактов организации, кроме exclude. Если у exclude
     * ещё нет ID (новый контакт до flush) — сбрасываются все существующие.
     */
    public function resetIsMainForOrganization(Organization $organization, Contact $exclude): void
    {
        $qb = $this->createQueryBuilder('c')
            ->update()
            ->set('c.isMain', ':isMain')
            ->where('c.organization = :organization')
            ->setParameter('isMain', false)
            ->setParameter('organization', $organization->id);

        if (null !== $exclude->id) {
            $qb->andWhere('c.id != :excludeId')
                ->setParameter('excludeId', $exclude->id);
        }

        $qb->getQuery()->execute();
    }

    private function idOf(Contact $contact): int
    {
        return $contact->id ?? PHP_INT_MAX;
    }
}
