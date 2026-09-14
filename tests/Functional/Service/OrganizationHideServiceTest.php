<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Service\OrganizationHideService;
use App\Tests\DatabaseWebTestCase;

/**
 * Тесты OrganizationHideService (change organization-hiding): идемпотентность
 * hide, скрытие от всех текущих менеджеров, возврат видимости, валидация
 * целевых менеджеров и поиск дубликатов.
 */
final class OrganizationHideServiceTest extends DatabaseWebTestCase
{
    public function testHideCreatesRowsAndReturnsCreatedCount(): void
    {
        [$org, $manager1, $manager2] = $this->seed();

        $created = $this->service()->hide($org, [$manager1, $manager2]);

        self::assertSame(2, $created);
        self::assertNotNull($this->repo()->findOneByOrganizationAndManager($org, $manager1));
        self::assertNotNull($this->repo()->findOneByOrganizationAndManager($org, $manager2));
    }

    public function testHideSkipsExistingPairs(): void
    {
        [$org, $manager1, $manager2] = $this->seed();

        self::assertSame(1, $this->service()->hide($org, [$manager1]));
        // Повторный вызов: существующая пара пропускается (идемпотентность).
        self::assertSame(0, $this->service()->hide($org, [$manager1]));
        // Смешанный список: новая пара создаётся, существующая — нет.
        self::assertSame(1, $this->service()->hide($org, [$manager1, $manager2]));
        self::assertCount(2, $this->repo()->findForOrganization($org));
    }

    public function testHideFromAllManagersCreatesRowPerCurrentManager(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $org = $this->makeOrganization('ООО Ромашка');
        $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $created = $this->service()->hideFromAllManagers($org);

        self::assertSame(2, $created);
        // Администратору записи не создаются: скрытие не ограничивает
        // администратора (spec access-control).
        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $admin));
        self::assertCount(2, $this->repo()->findForOrganization($org));
    }

    public function testHideFromAllManagersExcludesAlreadyHiddenPairs(): void
    {
        $org = $this->makeOrganization('ООО Ромашка');
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $this->service()->hide($org, [$manager1]);

        self::assertSame(1, $this->service()->hideFromAllManagers($org));
        self::assertCount(2, $this->repo()->findForOrganization($org));
    }

    public function testUnhideRemovesSingleRow(): void
    {
        [$org, $manager1, $manager2] = $this->seed();
        $this->service()->hide($org, [$manager1, $manager2]);

        $this->service()->unhide($org, $manager1);

        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $manager1));
        self::assertNotNull($this->repo()->findOneByOrganizationAndManager($org, $manager2));
    }

    public function testUnhideWithoutRowIsNoop(): void
    {
        [$org, $manager1] = $this->seed();

        $this->service()->unhide($org, $manager1);

        self::assertCount(0, $this->repo()->findForOrganization($org));
    }

    public function testValidateHideTargetsReturnsEmptyForValidManagerIds(): void
    {
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        $invalid = $this->service()->validateHideTargets([$manager1->id, $manager2->id]);

        self::assertSame([], $invalid);
    }

    public function testValidateHideTargetsReturnsInvalidIds(): void
    {
        $admin = $this->makeUser('admin@b2b-crm.loc', UserRole::Admin);
        $this->em()->flush();

        $invalid = $this->service()->validateHideTargets([$admin->id]);

        self::assertSame([(string) $admin->id], $invalid);
    }

    public function testValidateHideTargetsReturnsEmptyForEmptyArray(): void
    {
        $invalid = $this->service()->validateHideTargets([]);

        self::assertSame([], $invalid);
    }

    public function testFindDuplicateTargetsReturnsAlreadyHiddenManagers(): void
    {
        [$org, $manager1, $manager2] = $this->seed();
        $this->service()->hide($org, [$manager1]);

        $duplicates = $this->service()->findDuplicateTargets($org, [$manager1, $manager2]);

        self::assertCount(1, $duplicates);
        self::assertSame($manager1->id, $duplicates[0]->id);
    }

    public function testFindDuplicateTargetsReturnsEmptyWhenNoDuplicates(): void
    {
        [$org, $manager1, $manager2] = $this->seed();

        $duplicates = $this->service()->findDuplicateTargets($org, [$manager1, $manager2]);

        self::assertSame([], $duplicates);
    }

    /**
     * @return array{0: Organization, 1: User, 2: User}
     */
    private function seed(): array
    {
        $org = $this->makeOrganization('ООО Ромашка');
        $manager1 = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $this->em()->flush();

        return [$org, $manager1, $manager2];
    }

    private function makeUser(string $email, UserRole $role): User
    {
        $user = (new User())
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

    private function service(): OrganizationHideService
    {
        return new OrganizationHideService(
            $this->repo(),
            $this->em()->getRepository(User::class),
            $this->em(),
        );
    }

    private function repo(): OrganizationHideRepository
    {
        return $this->em()->getRepository(OrganizationHide::class);
    }
}
