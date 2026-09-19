<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * Тесты гейтвея доступа OrganizationRepository::findAccessibleIds()
 * (change organization-hiding): default-open с deny-list, администратор
 * обходит скрытие, скрытие действует только на выбранного менеджера.
 */
final class OrganizationRepositoryAccessTest extends DatabaseWebTestCase
{
    public function testAdminBypassesHides(): void
    {
        [$admin, $manager, $org] = $this->seed();

        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();

        self::assertNull($this->repo()->findAccessibleIds($admin));
    }

    public function testGuestGetsUnlimitedAccess(): void
    {
        $this->seed();

        self::assertNull($this->repo()->findAccessibleIds(null));
    }

    public function testManagerSeesAllOrganizationsByDefault(): void
    {
        [$admin, $manager, $org, $otherOrg] = $this->seed(true);

        $ids = $this->repo()->findAccessibleIds($manager);

        self::assertNotNull($ids);
        self::assertSame(
            [$org->id, $otherOrg->id],
            $this->sorted($ids),
        );
    }

    public function testHiddenOrganizationIsExcludedForManager(): void
    {
        [$admin, $manager, $org, $otherOrg] = $this->seed(true);

        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();

        $ids = $this->repo()->findAccessibleIds($manager);

        self::assertNotNull($ids);
        self::assertSame([$otherOrg->id], $ids);
    }

    public function testHideDoesNotAffectOtherManagers(): void
    {
        [$admin, $manager, $otherManager, $org, $otherOrg] = $this->seedTwoManagers();

        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();

        $ids = $this->repo()->findAccessibleIds($otherManager);

        self::assertNotNull($ids);
        self::assertSame(
            [$org->id, $otherOrg->id],
            $this->sorted($ids),
        );
    }

    public function testAdminStillSeesHiddenOrganization(): void
    {
        [$admin, $manager, $org, $otherOrg] = $this->seed(true);

        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->flush();

        self::assertNull($this->repo()->findAccessibleIds($admin));

        $accessible = $this->repo()->findAccessibleOrganizations($admin);

        self::assertSame(
            [$org->id, $otherOrg->id],
            $this->sorted(array_map(static fn(Organization $o): int => $o->id, $accessible)),
        );
    }

    /**
     * @return array{0: User, 1: User, 2: Organization, 3: Organization}
     */
    private function seed(bool $withOtherOrg = false): array
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $otherOrg = $withOtherOrg ? $this->makeOrganization('ООО Вектор') : $org;

        $this->em()->flush();

        return [$admin, $manager, $org, $otherOrg];
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Organization, 4: Organization}
     */
    private function seedTwoManagers(): array
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $manager = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $otherManager = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $otherOrg = $this->makeOrganization('ООО Вектор');

        $this->em()->flush();

        return [$admin, $manager, $otherManager, $org, $otherOrg];
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = (new User())
            ->setLogin($login)
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);

        return $user;
    }

    private function makeOrganization(string $name): Organization
    {
        $organization = (new Organization())
            ->setName($name)
            ->setIndustry('IT');
        $this->em()->persist($organization);

        return $organization;
    }

    /**
     * @param int[] $ids
     *
     * @return int[]
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    private function repo(): OrganizationRepository
    {
        return $this->em()->getRepository(Organization::class);
    }
}
