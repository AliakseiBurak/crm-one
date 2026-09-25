<?php

declare(strict_types=1);

namespace App\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Санитайзер атрибута style для тела рассылки (design D4): оставляет только
 * CSS-свойства из allowlist и отбрасывает опасные значения (position, z-index,
 * expression(), url(), javascript:), чтобы HTML тела не влиял на вёрстку
 * интерфейса CRM и не загружал внешние ресурсы.
 */
final class CampaignStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    /** CSS-свойства, безопасные для письма и предпросмотра. */
    private const array ALLOWED_PROPERTIES = [
        'background',
        'background-color',
        'border',
        'border-bottom',
        'border-bottom-color',
        'border-bottom-style',
        'border-bottom-width',
        'border-collapse',
        'border-color',
        'border-left',
        'border-left-color',
        'border-left-style',
        'border-left-width',
        'border-radius',
        'border-right',
        'border-right-color',
        'border-right-style',
        'border-right-width',
        'border-spacing',
        'border-style',
        'border-top',
        'border-top-color',
        'border-top-style',
        'border-top-width',
        'border-width',
        'box-sizing',
        'color',
        'display',
        'font-family',
        'font-size',
        'font-style',
        'font-variant',
        'font-weight',
        'height',
        'letter-spacing',
        'line-height',
        'margin',
        'margin-bottom',
        'margin-left',
        'margin-right',
        'margin-top',
        'max-height',
        'max-width',
        'min-height',
        'min-width',
        'overflow-wrap',
        'padding',
        'padding-bottom',
        'padding-left',
        'padding-right',
        'padding-top',
        'table-layout',
        'text-align',
        'text-decoration',
        'text-indent',
        'text-transform',
        'vertical-align',
        'white-space',
        'width',
        'word-break',
    ];

    /** Подстроки, при которых значение свойства отбрасывается целиком. */
    private const array FORBIDDEN_VALUE_PATTERNS = [
        'expression',
        'javascript:',
        'vbscript:',
        'url(',
        '@import',
        'behavior',
        '-moz-binding',
        '\\',
        '/*',
        '*/',
    ];

    private const int MAX_DECLARATION_LENGTH = 512;

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $declarations = [];

        foreach (explode(';', $value) as $declaration) {
            $declaration = trim($declaration);
            if ('' === $declaration || \strlen($declaration) > self::MAX_DECLARATION_LENGTH) {
                continue;
            }

            $parts = explode(':', $declaration, 2);
            if (2 !== \count($parts)) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $propertyValue = trim($parts[1]);

            if ('' === $propertyValue || !\in_array($property, self::ALLOWED_PROPERTIES, true)) {
                continue;
            }

            $lowerValue = strtolower($propertyValue);
            foreach (self::FORBIDDEN_VALUE_PATTERNS as $pattern) {
                if (str_contains($lowerValue, $pattern)) {
                    continue 2;
                }
            }

            $declarations[] = $property . ': ' . $propertyValue;
        }

        return [] === $declarations ? null : implode('; ', $declarations);
    }
}
