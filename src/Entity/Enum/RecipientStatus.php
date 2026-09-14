<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Opened = 'opened';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Sending => 'Отправляется',
            self::Delivered => 'Доставлено',
            self::Bounced => 'Отклонено',
            self::Failed => 'Ошибка',
            self::Opened => 'Открыто',
        };
    }
}
