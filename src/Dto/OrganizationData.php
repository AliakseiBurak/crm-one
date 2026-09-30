<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Организация, разобранная из одной записи CSV (design D3).
 *
 * `annualPlan` здесь отсутствует намеренно: столбец «Составление плана на год»
 * в 58 случаях из выгрузки содержит не план, а адрес сайта, поэтому URL и
 * домен уходят в `website`, а всё прочее отбрасывается. Импорт annualPlan не
 * заполняет вовсе.
 *
 * `city` тоже нет: формат источника не объявляет колонки города, импортированные
 * организации получают `city = null`, и в пакете для проверки поля нет.
 */
final readonly class OrganizationData
{
    /**
     * @param ContactData[] $contacts
     * @param CallData[]    $calls
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $coursesAttended = null,
        public ?string $website = null,
        public array $contacts = [],
        public array $calls = [],
    ) {}
}
