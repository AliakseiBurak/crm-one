<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampaignAttachmentRepository::class)]
#[ORM\Table(name: 'campaign_attachment')]
class CampaignAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Campaign $campaign;

    /**
     * Оригинальное имя загруженного файла (попадёт в письмо как имя вложения).
     */
    #[ORM\Column(length: 255)]
    public private(set) string $filename;

    /**
     * Ключ файла в хранилище (var/storage/campaign-attachments/<storageKey>);
     * в БД — только метаданные (design campaign-entity, решение 2).
     */
    #[ORM\Column(name: 'storage_key', length: 128)]
    public private(set) string $storageKey;

    #[ORM\Column(name: 'mime_type', length: 255, nullable: true)]
    public private(set) ?string $mimeType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public private(set) ?int $size = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(Campaign $campaign, string $filename, string $storageKey)
    {
        $this->campaign = $campaign;
        $this->filename = $filename;
        $this->storageKey = $storageKey;
        $this->createdAt = new \DateTimeImmutable();
        $campaign->addAttachment($this);
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;

        return $this;
    }
}
