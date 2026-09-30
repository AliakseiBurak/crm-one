<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Контакт, разобранный из колонки «Контакты» (design D3). Эвристика, а не
 * разбор: ячейка не структурирована, поэтому проверка пакета — страховка.
 *
 * `name` не может быть пустым (NOT NULL в БД), поэтому фрагмент без
 * читаемого имени импортируется под константным именем «Без имени»: фрагмент
 * не выбрасывается.
 */
final readonly class ContactData
{
    public const string ANONYMOUS_NAME = 'Без имени';

    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $position = null,
        public ?string $notes = null,
    ) {}
}
