<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * Тесты репозитория OrganizationHideRepository (change organization-hiding):
 * выборки по организации, по менеджеру и по паре организация-менеджер.
 */
final class OrganizationHideRepositoryTest extends DatabaseWebTestCase
{
    public function testFindForOrganizationReturnsItsRows(): void
    {
        [$manager, $otherManager, $org, $otherOrg] = $this->seed();

        $first = new OrganizationHide($org, $manager);
        $second = new OrganizationHide($org, $otherManager);
        $foreign = new OrganizationHide($otherOrg, $manager);
        $this->em()->persist($first);
        $this->em()->persist($second);
        $this->em()->persist($foreign);
        $this->em()->flush();

        $rows = $this->repo()->findForOrganization($org);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($org->id, $row->organization->id);
        }
    }

    public function testFindForManagerReturnsOnlyTheirRows(): void
    {
        [$manager, $otherManager, $org, $otherOrg] = $this->seed();

        $this->em()->persist(new OrganizationHide($org, $manager));
        $this->em()->persist(new OrganizationHide($otherOrg, $manager));
        $this->em()->persist(new OrganizationHide($org, $otherManager));
        $this->em()->flush();

        $rows = $this->repo()->findForManager($manager);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($manager->id, $row->manager->id);
        }
    }

    public function testFindOneByOrganizationAndManager(): void
    {
        [$manager, $otherManager, $org, $otherOrg] = $this->seed();

        $row = new OrganizationHide($org, $manager);
        $this->em()->persist($row);
        $this->em()->flush();

        self::assertSame($row->id, $this->repo()->findOneByOrganizationAndManager($org, $manager)->id);
        self::assertNull($this->repo()->findOneByOrganizationAndManager($org, $otherManager));
        self::assertNull($this->repo()->findOneByOrganizationAndManager($otherOrg, $manager));
    }

    public function testHideRecordHasUuidAndTimestamp(): void
    {
        [$manager, $otherManager, $org, $otherOrg] = $this->seed();

        $row = new OrganizationHide($org, $manager);
        $this->em()->persist($row);
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $this->repo()->find($row->id);
        self::assertNotNull($reloaded);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $reloaded->id,
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->hiddenAt);
    }

    /**
     * @return array{0: User, 1: User, 2: Organization, 3: Organization}
     */
    private function seed(): array
    {
        $manager = $this->makeUser('manager1@b2b-crm.loc', UserRole::Manager);
        $otherManager = $this->makeUser('manager2@b2b-crm.loc', UserRole::Manager);
        $org = $this->makeOrganization('ООО Ромашка');
        $otherOrg = $this->makeOrganization('ООО Вектор');
        $this->em()->flush();

        return [$manager, $otherManager, $org, $otherOrg];
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

    private function repo(): OrganizationHideRepository
    {
        return $this->em()->getRepository(OrganizationHide::class);
    }
}
