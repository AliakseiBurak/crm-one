<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название обязательно для заполнения')]
    #[Assert\Length(max: 255, maxMessage: 'Название не должно превышать {{ limit }} символов')]
    public private(set) string $name;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Отрасль не должна превышать {{ limit }} символов')]
    public private(set) ?string $industry = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Годовой план не должен превышать {{ limit }} символов')]
    public private(set) ?string $annualPlan = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $description = null;

    #[ORM\Column(name: 'courses_attended', length: 255, nullable: true)]
    public private(set) ?string $coursesAttended = null;

    #[ORM\Column(length: 32, nullable: true)]
    public private(set) ?string $unp = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    public private(set) bool $isActive = true;

    #[ORM\Column(name: 'is_opted_out', options: ['default' => false])]
    public private(set) bool $isOptedOut = false;

    #[ORM\Column(name: 'opt_out_reason', type: 'text', nullable: true)]
    public private(set) ?string $optOutReason = null;

    #[ORM\Column(name: 'opted_out_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $optedOutAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Contact::class)]
    public private(set) Collection $contacts;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: OrgGroupMembership::class)]
    public private(set) Collection $groupMemberships;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->contacts = new ArrayCollection();
        $this->groupMemberships = new ArrayCollection();
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setIndustry(?string $industry): self
    {
        $this->industry = $industry;

        return $this;
    }

    public function setAnnualPlan(?string $annualPlan): self
    {
        $this->annualPlan = $annualPlan;

        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function setCoursesAttended(?string $coursesAttended): self
    {
        $this->coursesAttended = $coursesAttended;

        return $this;
    }

    public function setUnp(?string $unp): self
    {
        $this->unp = $unp;

        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function setIsOptedOut(bool $isOptedOut): self
    {
        if (!$isOptedOut && $this->isOptedOut) {
            $this->optOutReason = null;
            $this->optedOutAt = null;
        }

        if ($isOptedOut && !$this->isOptedOut) {
            $this->optedOutAt = new \DateTimeImmutable();
        }

        $this->isOptedOut = $isOptedOut;

        return $this;
    }

    public function setOptOutReason(?string $optOutReason): self
    {
        $this->optOutReason = $optOutReason;

        return $this;
    }

    public function setOptedOutAt(?\DateTimeImmutable $optedOutAt): self
    {
        $this->optedOutAt = $optedOutAt;

        return $this;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
