<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Строка пакета для проверки: одна организация со своими контактами и
 * звонками, всё в редактируемом виде (design D4).
 *
 * Пакет — только порция для проверки, отдельная транзакция на строку; поля
 * намеренно мутабельны, потому что администратор правит их прямо в таблице
 * перед утверждением. Номера строки в источнике сохраняется: по нему
 * называется остановившаяся строка и по нему же отсекаются уже сохранённые
 * строки повторно отправленной формы.
 *
 * `industry`, `city`, `unp` и `annualPlan` заполняются только на JSON-пути
 * (change add-organizations-json-import): их приносит ответ, и в таблице пакета
 * они показываются и правятся — иначе значение ушло бы в базу вслепую. У
 * прогонов из CSV-файла они остаются null, и форма для них не выводится.
 */
final class ImportRow
{
    /** @var ImportRowContact[] */
    public array $contacts = [];

    /** @var ImportRowCall[] */
    public array $calls = [];

    /**
     * Что остановило строку: пустое название, отказ базы, конфликт по имени.
     */
    public ?string $error = null;

    /**
     * Существующая организация, в которую строку предлагается слить.
     * Заполняется, когда вставка остановилась на совпадении названия.
     */
    public ?int $conflictOrganizationId = null;

    public ?string $conflictOrganizationName = null;

    public function __construct(
        public int $rowNumber,
        public string $name = '',
        public ?string $industry = null,
        public ?string $city = null,
        public ?string $unp = null,
        public ?string $annualPlan = null,
        public ?string $description = null,
        public ?string $coursesAttended = null,
        public ?string $website = null,
    ) {}

    public function hasConflict(): bool
    {
        return null !== $this->conflictOrganizationId;
    }

    /**
     * Запись не содержит данных организации и контактов: ни названия, ни
     * контактов.
     *
     * Назвать строку — значит создать организацию. Если названия нет, то и
     * контактов быть не должно: контакт без организации некуда записать.
     * Поэтому строки 336 и 351 выгрузки — с пустым названием, пустыми
     * контактами и одним звонком — пропускаются, а не останавливают импорт
     * ошибкой «Название обязательно для заполнения». Звонку без организации всё
     * равно некуда прикрепиться, а описание названия не заменяет.
     *
     * Исправлять такую строку нечем, а нерешённая оставалась бы первой в каждом
     * пакете — пакет всегда начинается с `processedRows + 1`, — и импорт не
     * двигался бы никогда. Поэтому запись пропускается.
     *
     * Пустое название при непустых контактах — не «пустая запись»: там есть что
     * сохранить, кроме названия, и импорт останавливается на ней для исправления.
     */
    public function isEmptyRecord(): bool
    {
        return '' === trim($this->name) && [] === $this->contacts;
    }

    /**
     * Помечает строку как остановившуюся на совпадении названия.
     */
    public function markConflict(int $organizationId, string $organizationName): void
    {
        $this->conflictOrganizationId = $organizationId;
        $this->conflictOrganizationName = $organizationName;
        $this->error = \sprintf('Организация «%s» уже существует', $organizationName);
    }
}
