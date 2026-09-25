<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Результат рендера письма рассылки (design D5): тема, HTML-документ и
 * текстовая часть. Используется и отправкой, и предпросмотрами.
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
