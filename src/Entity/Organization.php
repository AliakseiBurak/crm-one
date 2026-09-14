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

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Отрасль обязательна для заполнения')]
    #[Assert\Length(max: 255, maxMessage: 'Отрасль не должна превышать {{ limit }} символов')]
    public private(set) string $industry;

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

    public function setIndustry(string $industry): self
    {
        $this->industry = $industry;

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
