<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Контакт строки пакета для проверки.
 *
 * `notes` и `isMain` приходят из колонок источника пустыми: формат выгрузки не
 * различает заметки контакта и основной контакт. Они появляются в пакете только
 * из формы проверки, где администратор заполняет их вручную, и в этом
 * единственном случае влияют на сохранение.
 *
 * Отметка основного контакта — правило приложения, а не поле строки: в
 * организации он может быть только один, поэтому при сохранении
 * `ContactRepository::resetIsMainForOrganization()` снимает отметку с остальных
 * контактов этой организации. Раньше импорт отметку не выставлял вовсе, и
 * организация получала основной контакт вручную позже.
 */
final class ImportRowContact
{
    public function __construct(
        public string $name = '',
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $position = null,
        public ?string $notes = null,
        public bool $isMain = false,
    ) {}
}
