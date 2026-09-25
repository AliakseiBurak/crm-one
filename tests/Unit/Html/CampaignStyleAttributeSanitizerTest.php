<?php

declare(strict_types=1);

namespace App\Tests\Unit\Html;

use App\Html\CampaignStyleAttributeSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Unit-тесты CampaignStyleAttributeSanitizer: allowlist CSS-свойств и отброс
 * опасных значений (design D4, сценарии «Опасные CSS-свойства удаляются»).
 */
final class CampaignStyleAttributeSanitizerTest extends TestCase
{
    private CampaignStyleAttributeSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new CampaignStyleAttributeSanitizer();
    }

    public function testKeepsAllowedProperties(): void
    {
        self::assertSame(
            'color: red; text-align: center',
            $this->sanitize('color: red; text-align: center'),
        );
    }

    public function testKeepsTableProperties(): void
    {
        self::assertSame(
            'border-collapse: collapse; border: 1px solid #ddd; padding: 8px',
            $this->sanitize('border-collapse: collapse; border: 1px solid #ddd; padding: 8px'),
        );
    }

    public function testDropsDeclarationsContainingCampaignTokens(): void
    {
        self::assertSame(
            'font-size: 12px',
            $this->sanitize('color: {{organization_name}}; font-size: 12px'),
        );
        self::assertNull($this->sanitize('color: {{greeting}}'));
    }

    public function testDropsPositionAndZIndex(): void
    {
        self::assertSame('color: red', $this->sanitize('position: fixed; z-index: 999; color: red'));
    }

    public function testDropsExpressionValue(): void
    {
        self::assertNull($this->sanitize('width: expression(alert(1))'));
    }

    public function testDropsUrlValue(): void
    {
        self::assertNull($this->sanitize('background-color: url(https://evil.example/x.png)'));
    }

    public function testDropsJavascriptScheme(): void
    {
        self::assertNull($this->sanitize('color: javascript:alert(1)'));
    }

    public function testDropsCommentsAndBackslashEscapes(): void
    {
        self::assertNull($this->sanitize('color: red /* hidden */'));
        self::assertNull($this->sanitize('color: red\\65 xpression(alert(1))'));
    }

    public function testDropsMozBinding(): void
    {
        self::assertNull($this->sanitize('width: -moz-binding(url(https://evil.example/x.xml))'));
    }

    public function testReturnsNullWhenNothingAllowed(): void
    {
        self::assertNull($this->sanitize('position: fixed'));
    }

    public function testIgnoresMalformedDeclarations(): void
    {
        self::assertNull($this->sanitize('color; ;; text-align'));
    }

    public function testPropertyNamesAreCaseInsensitive(): void
    {
        self::assertSame('color: Red', $this->sanitize('COLOR: Red'));
    }

    public function testTrimsWhitespaceAroundPropertyAndValue(): void
    {
        self::assertSame('color: red', $this->sanitize('  color :   red  '));
    }

    public function testDropsTooLongDeclarations(): void
    {
        self::assertNull($this->sanitize('color: ' . str_repeat('a', 600)));
    }

    public function testSupportsAllElementsAndStyleAttributeOnly(): void
    {
        self::assertNull($this->sanitizer->getSupportedElements());
        self::assertSame(['style'], $this->sanitizer->getSupportedAttributes());
    }

    private function sanitize(string $style): ?string
    {
        return $this->sanitizer->sanitizeAttribute('p', 'style', $style, new HtmlSanitizerConfig());
    }
}
