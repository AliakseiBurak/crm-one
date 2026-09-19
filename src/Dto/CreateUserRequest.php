<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateUserRequest
{
    #[Assert\NotBlank(message: 'Логин обязателен для заполнения')]
    #[Assert\Length(min: 5, max: 180, minMessage: 'Логин должен содержать не менее {{ limit }} символов', maxMessage: 'Логин не должен превышать {{ limit }} символов')]
    public ?string $login = null;

    #[Assert\Email(message: 'Некорректный формат email')]
    public ?string $email = null;

    public ?string $name = null;

    public ?string $surname = null;

    #[Assert\NotBlank(message: 'Роль обязательна для заполнения')]
    #[Assert\Choice(choices: ['admin', 'manager'], message: 'Допустимые роли: admin, manager')]
    public ?string $role = null;
}
