<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'org_group_membership')]
class OrgGroupMembership
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'groupMemberships')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Organization $organization;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OrganizationGroup::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) OrganizationGroup $group;

    #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $addedAt;

    public function __construct(Organization $organization, OrganizationGroup $group)
    {
        $this->organization = $organization;
        $this->group = $group;
        $this->addedAt = new \DateTimeImmutable();
    }
}
