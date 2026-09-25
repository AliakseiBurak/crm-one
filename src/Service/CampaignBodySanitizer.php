<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Санитизация HTML-тела рассылки (design D4): единая точка очистки при
 * сохранении кампании. Конфигурация — html_sanitizer.sanitizer.campaign_body
 * (allowlist элементов и атрибутов, https для media, allowlist CSS-свойств
 * через App\Html\CampaignStyleAttributeSanitizer).
 */
final class CampaignBodySanitizer
{
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.campaign_body')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
