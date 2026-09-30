<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Звонок строки пакета для проверки.
 *
 * Дата хранится текстом (`дд.мм.гггг`), а не `\DateTimeImmutable`: запись без
 * однозначной даты обязана остаться в таблице как звонок с пустым полем даты и
 * исходным текстом в заметке, чтобы администратор мог ввести дату вручную.
 * Разбор текста в дату происходит при сохранении строки; нечитаемое значение
 * остаётся как есть и звонка не датирует (design D6a).
 *
 * `planned` отличает плановый звонок из «Следующего контакта» (scheduledAt
 * задан, madeAt пуст) от записи взаимодействия.
 */
final class ImportRowCall
{
    public function __construct(
        public string $date = '',
        public ?string $notes = null,
        public bool $planned = false,
    ) {}
}
