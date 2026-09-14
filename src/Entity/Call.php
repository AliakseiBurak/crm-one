<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CallRepository::class)]
#[ORM\Table(name: '`call`')]
class Call
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Организация обязательна для выбора')]
    public private(set) Organization $organization;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Contact $contact = null;

    #[ORM\Column(name: 'scheduled_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'made_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $madeAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'made_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $madeBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $notes = null;

    #[ORM\Column(name: 'is_deal', type: 'boolean', options: ['default' => false])]
    public private(set) bool $isDeal = false;

    #[ORM\Column(name: 'is_no_answer', type: 'boolean', options: ['default' => false])]
    public private(set) bool $isNoAnswer = false;

    #[ORM\OneToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'next_call_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Call $nextCall = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Campaign $campaign = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setOrganization(Organization $organization): self
    {
        $this->organization = $organization;

        return $this;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;

        return $this;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function setMadeAt(?\DateTimeImmutable $madeAt): self
    {
        $this->madeAt = $madeAt;

        return $this;
    }

    public function setMadeBy(?User $madeBy): self
    {
        $this->madeBy = $madeBy;

        return $this;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function setIsDeal(bool $isDeal): self
    {
        $this->isDeal = $isDeal;

        return $this;
    }

    public function setIsNoAnswer(bool $isNoAnswer): self
    {
        $this->isNoAnswer = $isNoAnswer;

        return $this;
    }

    public function setNextCall(?self $nextCall): self
    {
        $this->nextCall = $nextCall;

        return $this;
    }

    public function setCampaign(?Campaign $campaign): self
    {
        $this->campaign = $campaign;

        return $this;
    }
}
