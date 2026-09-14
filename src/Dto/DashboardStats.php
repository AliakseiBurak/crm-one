<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class DashboardStats
{
    public function __construct(
        public int $calledToday,
        public int $calledWeek,
        public int $calledMonth,
        public int $waitingToday,
        public int $waitingWeek,
        public int $waitingMonth,
    ) {}
}
