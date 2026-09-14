<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'group_assignment')]
class GroupAssignment
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'groupAssignments')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OrganizationGroup::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) OrganizationGroup $group;

    #[ORM\Column(name: 'assigned_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $assignedAt;

    public function __construct(User $user, OrganizationGroup $group)
    {
        $this->user = $user;
        $this->group = $group;
        $this->assignedAt = new \DateTimeImmutable();
        $user->groupAssignments->add($this);
        $group->assignments->add($this);
    }
}
