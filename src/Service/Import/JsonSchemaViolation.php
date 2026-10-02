<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Нарушение контракта ответа: одна проблема одной организации.
 *
 * Нарушение — это всегда «организация №N, поле X, почему», потому что отчёт
 * показывается администратору, который правит ответ: без номера и названия поля
 * он искал бы ошибку в сотне организаций (design D3).
 */
final readonly class JsonSchemaViolation
{
    public function __construct(
        public int $organizationIndex,
        public string $field,
        public string $message,
    ) {}
}
