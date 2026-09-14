<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Launched = 'launched';
    case Failed = 'failed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Ready => 'Готова',
            self::Launched => 'Запущена',
            self::Failed => 'Ошибка',
            self::Archived => 'В архиве',
        };
    }
}
