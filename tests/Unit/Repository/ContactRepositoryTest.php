<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Contact;
use App\Entity\Organization;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты ContactRepository::findEffectiveMainAmong (change
 * contact-ismain-email-routing): разрешение эффективного главного контакта
 * без БД — isMain-контакт выигрывает, при нескольких или отсутствии флага —
 * минимальный ID.
 */
final class ContactRepositoryTest extends TestCase
{
    private ContactRepository $repository;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $this->repository = new ContactRepository($registry);
    }

    public function testIsMainContactWinsOverSmallerId(): void
    {
        $organization = new Organization()->setName('ООО Ромашка');
        $smallId = $this->contact($organization, 'Алексей Сидоров', 1);
        $main = $this->contact($organization, 'Мария Смирнова', 2);
        $main->setIsMain(true);

        self::assertSame($main, $this->repository->findEffectiveMainAmong([$smallId, $main]));
    }

    public function testSeveralIsMainResolvesToSmallestId(): void
    {
        $organization = new Organization()->setName('ООО Ромашка');
        $first = $this->contact($organization, 'Мария Смирнова', 5);
        $first->setIsMain(true);
        $second = $this->contact($organization, 'Иван Петров', 9);
        $second->setIsMain(true);

        self::assertSame($first, $this->repository->findEffectiveMainAmong([$second, $first]));
    }

    public function testWithoutIsMainResolvesToSmallestId(): void
    {
        $organization = new Organization()->setName('ООО Ромашка');
        $newer = $this->contact($organization, 'Мария Смирнова', 3);
        $older = $this->contact($organization, 'Алексей Сидоров', 1);

        self::assertSame($older, $this->repository->findEffectiveMainAmong([$newer, $older]));
    }

    public function testSwitchingIsMainMovesResolutionToNewContact(): void
    {
        $organization = new Organization()->setName('ООО Ромашка');
        $previous = $this->contact($organization, 'Мария Смирнова', 1);
        $previous->setIsMain(true);
        $next = $this->contact($organization, 'Иван Петров', 2);

        // Смена основного: флаг переносится на другой контакт.
        $previous->setIsMain(false);
        $next->setIsMain(true);

        self::assertSame($next, $this->repository->findEffectiveMainAmong([$previous, $next]));
    }

    public function testEmptyListHasNoEffectiveMain(): void
    {
        self::assertNull($this->repository->findEffectiveMainAmong([]));
    }

    private function contact(Organization $organization, string $name, int $id): Contact
    {
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName($name);
        new \ReflectionProperty($contact, 'id')->setValue($contact, $id);

        return $contact;
    }
}
