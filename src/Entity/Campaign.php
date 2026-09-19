<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\CampaignStatus;
use App\Repository\CampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaign')]
class Campaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название обязательно для заполнения')]
    #[Assert\Length(max: 255, maxMessage: 'Название не должно превышать {{ limit }} символов')]
    public private(set) string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Тема письма обязательна для заполнения')]
    #[Assert\Length(max: 255, maxMessage: 'Тема письма не должна превышать {{ limit }} символов')]
    public private(set) string $subject = '';

    #[ORM\Column(name: 'preview_text', length: 255, nullable: true)]
    public private(set) ?string $previewText = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Текст письма обязателен для заполнения')]
    public private(set) string $body = '';

    #[ORM\Column(type: 'string', enumType: CampaignStatus::class, options: ['default' => 'draft'])]
    public private(set) CampaignStatus $status = CampaignStatus::Draft;

    #[ORM\Column(name: 'launched_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $launchedAt = null;

    #[ORM\Column(name: 'failed_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'failure_reason', type: 'text', nullable: true)]
    public private(set) ?string $failureReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    /**
     * Вложения хранятся на самой кампании (design campaign-entity,
     * решение 2). Orphan removal + FK ON DELETE CASCADE гарантируют
     * удаление строк вложений вместе с кампанией; файлы в storage
     * удаляет сервисный слой (CampaignAttachmentStorage).
     */
    #[ORM\OneToMany(targetEntity: CampaignAttachment::class, mappedBy: 'campaign', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $attachments;

    /**
     * Ручные адресаты standalone-рассылки; уникальность пары
     * (campaign_id, organization_id) гарантирует схема БД.
     */
    #[ORM\OneToMany(targetEntity: CampaignRecipient::class, mappedBy: 'campaign', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $recipients;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
        $this->recipients = new ArrayCollection();
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function setPreviewText(?string $previewText): self
    {
        $this->previewText = $previewText;

        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function setStatus(CampaignStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function addAttachment(CampaignAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function removeAttachment(CampaignAttachment $attachment): self
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    public function addRecipient(CampaignRecipient $recipient): self
    {
        if (!$this->recipients->contains($recipient)) {
            $this->recipients->add($recipient);
        }

        return $this;
    }

    public function removeRecipient(CampaignRecipient $recipient): self
    {
        $this->recipients->removeElement($recipient);

        return $this;
    }

    /**
     * Запуск рассылки: фиксируется момент первого запуска и статус launched.
     * Фактическая отправка — будущий сервис MailingService (ADR-0010).
     */
    public function launch(): self
    {
        $this->launchedAt ??= new \DateTimeImmutable();
        $this->failureReason = null;
        $this->status = CampaignStatus::Launched;

        return $this;
    }

    public function isLaunched(): bool
    {
        return CampaignStatus::Launched === $this->status;
    }

    /**
     * Пометить рассылку как неудачную: статус failed, зафиксировать время.
     * Вызывается сервисом MailingService при ошибке отправки; недоступен
     * пользователю через форму.
     */
    public function fail(?string $reason = null): self
    {
        $this->failedAt ??= new \DateTimeImmutable();
        $this->failureReason = $reason;
        $this->status = CampaignStatus::Failed;

        return $this;
    }

    public function clearFailureReason(): self
    {
        $this->failureReason = null;

        return $this;
    }

    /**
     * Подстановка токенов {{greeting}}, {{contact_name}}, {{organization_name}}
     * в тему, превью и текст: приветствие «Уважаемый(ая) Имя» при контакте,
     * иначе «Уважаемые сотрудники Название организации»; {{contact_name}} при
     * отсутствии контакта подставляется названием организации. isMain в
     * токенах не участвует (только в маршрутизации TO/CC MailingService).
     */
    public function renderSubject(?Contact $contact, Organization $organization): string
    {
        return $this->fillTokens($this->subject, $contact, $organization);
    }

    public function renderPreviewText(?Contact $contact, Organization $organization): ?string
    {
        if (null === $this->previewText) {
            return null;
        }

        return $this->fillTokens($this->previewText, $contact, $organization);
    }

    public function renderBody(?Contact $contact, Organization $organization, string $unsubscribeUrl = ''): string
    {
        return $this->fillTokens($this->body, $contact, $organization, $unsubscribeUrl);
    }

    private function fillTokens(string $template, ?Contact $contact, Organization $organization, string $unsubscribeUrl = ''): string
    {
        if (null !== $contact) {
            $greeting = 'Уважаемый(ая) ' . $contact->name;
        } else {
            $greeting = 'Уважаемые сотрудники ' . $organization->name;
        }

        return str_replace(
            ['{{contact_name}}', '{{organization_name}}', '{{greeting}}', '{{unsubscribe_url}}'],
            [$contact?->name ?? $organization->name, $organization->name, $greeting, $unsubscribeUrl],
            $template,
        );
    }

    /**
     * Клонирование кампании: копируются тема, превью, текст, вложения
     * (метаданные, файлы в storage общие); статус — draft, launchedAt — null.
     * При $withRecipients = true копируются адресаты;
     * при $withContacts = true сохраняются контакты адресатов.
     */
    public static function cloneFrom(self $source, bool $withRecipients, bool $withContacts = false): self
    {
        $clone = new self()
            ->setName($source->name . ' (копия)')
            ->setSubject($source->subject)
            ->setPreviewText($source->previewText)
            ->setBody($source->body);

        foreach ($source->attachments as $attachment) {
            // CampaignAttachment constructor auto-adds to $clone->attachments
            new CampaignAttachment($clone, $attachment->filename, $attachment->storageKey)
                ->setMimeType($attachment->mimeType)
                ->setSize($attachment->size);
        }

        if ($withRecipients) {
            foreach ($source->recipients as $recipient) {
                // CampaignRecipient constructor auto-adds to $clone->recipients
                new CampaignRecipient($clone, $recipient->organization, $withContacts ? $recipient->contact : null);
            }
        }

        return $clone;
    }
}
