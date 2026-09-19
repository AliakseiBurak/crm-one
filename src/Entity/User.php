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
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Логин обязателен для заполнения')]
    #[Assert\Length(min: 5, max: 180, minMessage: 'Логин должен содержать не менее {{ limit }} символов', maxMessage: 'Логин не должен превышать {{ limit }} символов')]
    public private(set) string $login;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    public private(set) ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $surname = null;

    #[ORM\Column(name: 'password_hash', length: 255)]
    public private(set) string $passwordHash;

    #[ORM\Column(type: 'string', enumType: UserRole::class)]
    public private(set) UserRole $role;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: GroupAssignment::class)]
    public private(set) Collection $groupAssignments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->groupAssignments = new ArrayCollection();
    }

    public function setLogin(string $login): self
    {
        $this->login = $login;

        return $this;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function setEmail(?string $email): self
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
        return $this->login;
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
