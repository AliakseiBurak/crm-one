<?php

declare(strict_types=1);

namespace App\Service\Import\Exception;

/**
 * Название организации уже существует в базе (design D10).
 *
 * Проверка выполняется при вставке, а не при показе пакета: так ловятся и
 * ранее существовавшие организации, и дубликаты внутри того же файла — строка,
 * уже вставленная этим же прогоном. Остановка идёт тем же путём, что и любая
 * другая несохранённая строка, а на остановившейся строке появляется выбор
 * «слить с существующей» / «создать новую».
 */
final class ImportRowConflict extends ImportRowStopped
{
    public function __construct(
        int $rowNumber,
        public readonly int $existingOrganizationId,
        public readonly string $existingOrganizationName,
    ) {
        parent::__construct(
            $rowNumber,
            \sprintf('организация «%s» уже существует', $existingOrganizationName),
        );
    }
}
