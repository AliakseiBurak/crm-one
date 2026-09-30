<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Одна запись «Взаимодействий»: дата, если она читается по базовой грамматике,
 * и весь оставшийся текст записи в заметках.
 *
 * Заметка хранит текст как он есть: администратор видит в ней и то, что не
 * удалось прочитать как дату, и интервал целиком —
 * `(26.09.2025-06.10.2025- 13.10.2025) Недозвон снова`.
 */
final class InteractionEntry
{
    private string $notes;

    public function __construct(
        public readonly ?\DateTimeImmutable $date,
        string $notes = '',
    ) {
        $this->notes = trim($notes);
    }

    /**
     * Дописывает текст в заметку записи, отделяя его от уже накопленного.
     */
    public function appendText(string $text): void
    {
        $text = trim($text);
        if ('' === $text) {
            return;
        }
        $this->notes = '' === $this->notes ? $text : $this->notes . ' ' . $text;
    }

    public function notes(): ?string
    {
        $notes = trim($this->notes);

        return '' === $notes ? null : $notes;
    }
}
