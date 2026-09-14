<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';

    public function roles(): array
    {
        return match ($this) {
            self::Admin => ['ROLE_USER', 'ROLE_MANAGER', 'ROLE_ADMIN'],
            self::Manager => ['ROLE_USER', 'ROLE_MANAGER'],
        };
    }
}
