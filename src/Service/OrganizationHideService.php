<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сервис управления скрытием организаций (change organization-hiding,
 * ADR-0012): идемпотентные операции с записями organization_hide.
 * Уникальность пары организация-менеджер гарантирует БД; сервис
 * предварительно проверяет пару, чтобы не ловить исключения констрейнта.
 */
final class OrganizationHideService
{
    public function __construct(
        private readonly OrganizationHideRepository $hides,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Проверяет целевые менеджеры: все IDs должны быть менеджерами (role=manager),
     * иначе возвращает список email-ов не-менеджеров. Пустой массив — скрыть
     * от всех текущих менеджеров.
     *
     * @param int[] $managerIds
     *
     * @return string[] список email-ов не-менеджеров (пусто = OK)
     */
    public function validateHideTargets(array $managerIds): array
    {
        if ([] === $managerIds) {
            return [];
        }

        $managerIdsByEmail = [];
        foreach ($this->users->findManagers() as $manager) {
            $managerIdsByEmail[(int) $manager->id] = $manager->email;
        }

        $invalid = [];
        foreach ($managerIds as $id) {
            if (!isset($managerIdsByEmail[$id])) {
                $invalid[] = (string) $id;
            }
        }

        return $invalid;
    }

    /**
     * Проверяет, какие из указанных менеджеров уже скрыты от организации.
     *
     * @param User[] $managers
     *
     * @return User[] дублирующиеся
     */
    public function findDuplicateTargets(Organization $organization, array $managers): array
    {
        $duplicates = [];
        foreach ($managers as $manager) {
            if (null !== $this->hides->findOneByOrganizationAndManager($organization, $manager)) {
                $duplicates[] = $manager;
            }
        }

        return $duplicates;
    }

    /**
     * Скрывает организацию от списка менеджеров; существующие пары
     * пропускаются.
     *
     * @param User[] $managers
     */
    public function hide(Organization $organization, array $managers): int
    {
        $created = 0;
        foreach ($managers as $manager) {
            if (null !== $this->hides->findOneByOrganizationAndManager($organization, $manager)) {
                continue;
            }

            $this->em->persist(new OrganizationHide($organization, $manager));
            ++$created;
        }
        $this->em->flush();

        return $created;
    }

    /**
     * Скрывает организацию от всех текущих менеджеров (role=manager): по
     * одной записи на менеджера; будущие менеджеры не затрагиваются
     * (default-open). Существующие пары пропускаются.
     */
    public function hideFromAllManagers(Organization $organization): int
    {
        return $this->hide($organization, $this->users->findManagers());
    }

    /**
     * Возвращает видимость организации конкретному менеджеру.
     */
    public function unhide(Organization $organization, User $manager): void
    {
        $hide = $this->hides->findOneByOrganizationAndManager($organization, $manager);
        if (null !== $hide) {
            $this->em->remove($hide);
            $this->em->flush();
        }
    }
}
