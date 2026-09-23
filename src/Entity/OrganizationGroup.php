<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationGroupRepository::class)]
#[ORM\Table(name: 'organization_group')]
class OrganizationGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    public private(set) string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $description = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(
        pattern: '/^#[0-9a-fA-F]{6}$/',
        message: 'Неверный формат цвета (используйте #rrggbb)',
    )]
    public private(set) ?string $color = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'group', targetEntity: GroupAssignment::class, cascade: ['remove'])]
    public private(set) Collection $assignments;

    #[ORM\OneToMany(mappedBy: 'group', targetEntity: OrgGroupMembership::class, cascade: ['remove'])]
    public private(set) Collection $memberships;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->assignments = new ArrayCollection();
        $this->memberships = new ArrayCollection();
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
