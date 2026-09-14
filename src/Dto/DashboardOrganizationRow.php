<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Organization;

final readonly class DashboardOrganizationRow
{
    public function __construct(
        public Organization $organization,
        public ?\DateTimeImmutable $lastMadeAt,
        public ?\DateTimeImmutable $nextScheduledAt,
        public ?string $lastCallNote,
        public ?\DateTimeImmutable $lastCallDate,
        public ?int $lastCallContactId,
    ) {}
}
