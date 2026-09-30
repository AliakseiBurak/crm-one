<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Звонок, разобранный из одной записи колонки «Взаимодействия» либо из
 * колонки «Следующий контакт».
 *
 * `madeAt` и `scheduledAt` взаимоисключающи: у совершённого звонка заполнена
 * `madeAt` (12:00 разобранной даты), у планового — `scheduledAt`, причём
 * `madeAt` остаётся null, ведь его никто не совершал. Запись без читаемой даты
 * даёт и то, и другое пустым: она видна в проверке пакета как звонок с пустым
 * полем даты и исходным текстом в заметке.
 */
final readonly class CallData
{
    public function __construct(
        public ?\DateTimeImmutable $madeAt = null,
        public ?\DateTimeImmutable $scheduledAt = null,
        public ?string $notes = null,
    ) {}
}
