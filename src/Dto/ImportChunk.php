<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Пакет строк, готовый к показу, вместе со списком строк, которые показывать
 * нельзя.
 *
 * Пропущенные строки остаются в `rows` намеренно: счётчик прогресса идёт по
 * порядку и должен перешагнуть каждую строку файла, иначе импорт встанет на
 * первой же из них навсегда. Поэтому они отфильтровываются только при показе —
 * `visibleRows()`.
 */
final class ImportChunk
{
    /**
     * @param ImportRow[] $rows               пакет по порядку, включая пропускаемые строки
     * @param int[]       $skippedRowNumbers  номера строк без данных, по порядку
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $skippedRowNumbers = [],
    ) {}

    /**
     * Строки, которые показываются пользователю: пропущенные не попадают в
     * таблицу — их нечего подтверждать и нечего исправлять.
     *
     * @return ImportRow[]
     */
    public function visibleRows(): array
    {
        if ([] === $this->skippedRowNumbers) {
            return $this->rows;
        }

        $skipped = $this->skippedRowNumbers;

        return array_values(array_filter(
            $this->rows,
            static fn(ImportRow $row): bool => !\in_array($row->rowNumber, $skipped, true),
        ));
    }

    /**
     * Пакет для сохранения: отправленные пользователем строки плюс пропущенные,
     * которых в форме нет — их нечего было подтверждать.
     *
     * Пропущенные обязаны попасть в сохранение, иначе счётчик прогресса не
     * перешагнёт их и импорт встанет на первой же из них навсегда. Порядок —
     * строго по номеру строки файла, потому что счётчик идёт по порядку.
     *
     * @param ImportRow[] $submitted строки, разобранные из отправленной формы
     *                                 вместе с правками пользователя
     *
     * @return ImportRow[]
     */
    public function forPersist(array $submitted): array
    {
        $merged = $submitted;
        foreach ($this->rows as $row) {
            if (\in_array($row->rowNumber, $this->skippedRowNumbers, true)) {
                $merged[] = $row;
            }
        }

        usort(
            $merged,
            static fn(ImportRow $a, ImportRow $b): int => $a->rowNumber <=> $b->rowNumber,
        );

        return $merged;
    }
}
