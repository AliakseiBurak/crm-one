<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    public private(set) string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    public private(set) string $passwordHash;

    #[ORM\Column(type: 'string', enumType: UserRole::class)]
    public private(set) UserRole $role;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $surname = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: GroupAssignment::class)]
    public private(set) Collection $groupAssignments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->groupAssignments = new ArrayCollection();
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setSurname(?string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    public function setPassword(string $passwordHash): self
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function setRole(UserRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return $this->role->roles();
    }

    public function eraseCredentials(): void {}
}
