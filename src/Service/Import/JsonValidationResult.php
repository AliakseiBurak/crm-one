<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Результат проверки ответа по опубликованной схеме.
 *
 * Пустой результат означает «годен». Список нарушений полный, а не первый
 * найденный: править по одному заход дороже, чем прочитать весь список сразу.
 */
final readonly class JsonValidationResult
{
    /**
     * @param JsonSchemaViolation[] $violations
     */
    public function __construct(
        public array $violations = [],
    ) {}

    public function isValid(): bool
    {
        return [] === $this->violations;
    }

    /**
     * Нарушения одной организации, по возрастанию позиции в массиве.
     *
     * @return JsonSchemaViolation[]
     */
    public function forOrganization(int $index): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(JsonSchemaViolation $violation): bool => $violation->organizationIndex === $index,
        ));
    }

    /**
     * Отчёт для показа администратору: «№N, поле: сообщение» — по строке на
     * нарушение, в порядке появления.
     */
    public function report(): string
    {
        return implode("\n", array_map(
            static fn(JsonSchemaViolation $violation): string => \sprintf(
                '№%d, поле «%s»: %s',
                $violation->organizationIndex + 1,
                $violation->field,
                $violation->message,
            ),
            $this->violations,
        ));
    }
}
