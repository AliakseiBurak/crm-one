<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\RecipientStatus;
use App\Repository\CampaignRecipientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ручной адресат standalone-рассылки (design campaign-entity, решение 5).
 * Добавлять организацию адресатом менеджеру разрешено только в пределах
 * области доступа (ADR-0007, отказ 403), администратору — любую (ADR-0008).
 *
 * contact_id — опциональный: если указан, рассылка идёт на email контакта;
 * если null — на организацию в целом.
 *
 * Per-letter статусы (ADR-0010): pending → sending → {delivered|bounced|failed},
 * + opened через tracking-pixel. Поля status, sentAt, errorMessage,
 * trackingToken, retryCount, retryAt реализуют outbox-паттерн.
 */
#[ORM\Entity(repositoryClass: CampaignRecipientRepository::class)]
#[ORM\Table(name: 'campaign_recipient')]
#[ORM\UniqueConstraint(name: 'UNIQ_CAMPAIGN_ORG', columns: ['campaign_id', 'organization_id'])]
class CampaignRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'recipients')]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Campaign $campaign;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Organization $organization;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public private(set) ?Contact $contact = null;

    #[ORM\Column(name: 'replacement_count', type: 'integer', options: ['default' => 0])]
    public private(set) int $replacementCount = 0;

    #[ORM\Column(name: 'status', type: 'string', enumType: RecipientStatus::class, options: ['default' => 'pending'])]
    public private(set) RecipientStatus $status = RecipientStatus::Pending;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    public private(set) ?string $errorMessage = null;

    #[ORM\Column(name: 'tracking_token', length: 64, unique: true, nullable: true)]
    public private(set) ?string $trackingToken = null;

    #[ORM\Column(name: 'retry_count', type: 'integer', options: ['default' => 0])]
    public private(set) int $retryCount = 0;

    #[ORM\Column(name: 'retry_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $retryAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(Campaign $campaign, Organization $organization, ?Contact $contact = null, int $replacementCount = 0)
    {
        $this->campaign = $campaign;
        $this->organization = $organization;
        $this->contact = $contact;
        $this->replacementCount = $replacementCount;
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->trackingToken = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $campaign->addRecipient($this);
    }

    public function setStatus(RecipientStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function markSending(): self
    {
        $this->status = RecipientStatus::Sending;

        return $this;
    }

    public function markDelivered(): self
    {
        $this->status = RecipientStatus::Delivered;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = null;

        return $this;
    }

    public function markBounced(string $errorMessage): self
    {
        $this->status = RecipientStatus::Bounced;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function markFailed(string $errorMessage, bool $transient = true): self
    {
        $this->status = RecipientStatus::Failed;
        $this->errorMessage = $errorMessage;

        if ($transient) {
            ++$this->retryCount;
            if ($this->retryCount < 3) {
                /** @noinspection PhpUnhandledExceptionInspection */
                $backoff = 2 ** $this->retryCount + random_int(0, 5);
                $this->retryAt = new \DateTimeImmutable("+{$backoff} seconds");
            } else {
                $this->retryAt = null;
            }
        } else {
            $this->retryAt = null;
        }

        return $this;
    }

    public function markOpened(): self
    {
        $this->status = RecipientStatus::Opened;

        return $this;
    }

    public function isRetriable(): bool
    {
        return RecipientStatus::Failed === $this->status
            && $this->retryCount < 3
            && null !== $this->retryAt
            && $this->retryAt <= new \DateTimeImmutable();
    }

    public function resetForRetry(): self
    {
        $this->status = RecipientStatus::Pending;
        $this->errorMessage = null;
        $this->retryCount = 0;
        $this->retryAt = null;

        return $this;
    }
}
