<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Организация, разобранная из одной записи CSV (design D3).
 *
 * `annualPlan` на CSV-пути не заполняется: столбец «Составление плана на год»
 * в 58 случаях из выгрузки содержит не план, а адрес сайта, поэтому URL и
 * домен уходят в `website`, а всё прочее отбрасывается. Это свойство колонки
 * выгрузки, а не запрет поля: в ответе JSON `annualPlan` значит ровно то, что
 * написано, и сохраняется (design D7).
 *
 * `industry`, `city`, `unp` и `annualPlan` заполняются только на JSON-пути
 * (change add-organizations-json-import): там их приносит ответ — модель или
 * файл из другого источника, — и все они показываются в пакете для проверки,
 * чтобы значение можно было увидеть и исправить. CSV-разбор их не заполняет,
 * поэтому у прогонов из выгрузки они null.
 */
final readonly class OrganizationData
{
    /**
     * @param ContactData[] $contacts
     * @param CallData[]    $calls
     */
    public function __construct(
        public string $name,
        public ?string $industry = null,
        public ?string $city = null,
        public ?string $unp = null,
        public ?string $annualPlan = null,
        public ?string $description = null,
        public ?string $coursesAttended = null,
        public ?string $website = null,
        public array $contacts = [],
        public array $calls = [],
    ) {}
}
