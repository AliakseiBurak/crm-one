<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateUserRequest
{
    #[Assert\NotBlank(message: 'Email обязателен для заполнения')]
    #[Assert\Email(message: 'Некорректный формат email')]
    public ?string $email = null;

    public ?string $name = null;

    public ?string $surname = null;

    #[Assert\NotBlank(message: 'Роль обязательна для заполнения')]
    #[Assert\Choice(choices: ['admin', 'manager'], message: 'Допустимые роли: admin, manager')]
    public ?string $role = null;
}
