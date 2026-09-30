<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Итог сохранения пакета.
 *
 * `saved` — сколько строк создали организацию (слитую или новую), `skipped` —
 * номера строк, пропущенных как не содержащие данных. Оба значения уже учтены
 * в `ImportRun::processedRows`: счётчик один и считает решённые строки, второй
 * счётчик в модели нет.
 */
final readonly class PersistResult
{
    /**
     * @param int   $saved    число созданных организаций
     * @param int[] $skipped  номера пропущенных строк источника, по порядку
     */
    public function __construct(
        public int $saved = 0,
        public array $skipped = [],
    ) {}
}
