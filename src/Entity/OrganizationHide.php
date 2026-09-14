<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationHideRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationHideRepository::class)]
#[ORM\Table(name: 'organization_hide')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORG_HIDE_PAIR', columns: ['organization_id', 'manager_id'])]
class OrganizationHide
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public private(set) string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'manager_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $manager;

    #[ORM\Column(name: 'hidden_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $hiddenAt;

    public function __construct(Organization $organization, User $manager)
    {
        $this->id = self::uuidV4();
        $this->organization = $organization;
        $this->manager = $manager;
        $this->hiddenAt = new \DateTimeImmutable();
    }

    /**
     * UUID v4 без внешних зависимостей: symfony/uid и ramsey/uuid в проекте
     * отсутствуют (spec organization-hiding: id — UUID).
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
