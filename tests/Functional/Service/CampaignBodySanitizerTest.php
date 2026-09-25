<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\CampaignBodySanitizer;
use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты CampaignBodySanitizer (change wysiwyg-email-body):
 * проверяют конфигурацию html_sanitizer.sanitizer.campaign_body — allowlist
 * элементов/атрибутов, https для media, удаление скриптов и опасных CSS.
 */
final class CampaignBodySanitizerTest extends DatabaseWebTestCase
{
    public function testRemovesScriptsAndEventHandlers(): void
    {
        $sanitized = $this->sanitize('<p onclick="alert(1)">Текст<script>alert(1)</script></p>');

        self::assertSame('<p>Текст</p>', $sanitized);
    }

    public function testRemovesIframeEntirely(): void
    {
        self::assertSame('<p>до</p>', $this->sanitize('<p>до</p><iframe src="https://evil.example"></iframe>'));
    }

    public function testKeepsTableWithColspanAndImageAttributes(): void
    {
        $html = '<table border="1" cellpadding="4"><tbody><tr>'
            . '<td colspan="2" style="background-color: #f5f5f5">Ячейка</td></tr></tbody></table>'
            . '<img src="https://cdn.example/logo.png" alt="Логотип" width="120" style="display: block">';

        $sanitized = $this->sanitize($html);

        self::assertStringContainsString('<table border="1" cellpadding="4">', $sanitized);
        self::assertStringContainsString('colspan="2"', $sanitized);
        self::assertStringContainsString('style="background-color: #f5f5f5"', $sanitized);
        self::assertStringContainsString('src="https://cdn.example/logo.png"', $sanitized);
        self::assertStringContainsString('alt="Логотип"', $sanitized);
        self::assertStringContainsString('width="120"', $sanitized);
    }

    public function testDropsDangerousCssPropertiesButKeepsAllowedOnes(): void
    {
        $sanitized = $this->sanitize('<p style="position: fixed; z-index: 9; color: red; text-align: center">X</p>');

        self::assertStringContainsString('color: red', $sanitized);
        self::assertStringContainsString('text-align: center', $sanitized);
        self::assertStringNotContainsString('position', $sanitized);
        self::assertStringNotContainsString('z-index', $sanitized);
    }

    public function testAllowsOnlyHttpsMedia(): void
    {
        self::assertStringNotContainsString(
            'http://cdn.example/logo.png',
            $this->sanitize('<img src="http://cdn.example/logo.png" alt="x">'),
        );
        self::assertStringContainsString(
            'https://cdn.example/logo.png',
            $this->sanitize('<img src="https://cdn.example/logo.png" alt="x">'),
        );
    }

    public function testKeepsSafeLinksAndDropsJavascriptScheme(): void
    {
        $safe = $this->sanitize('<a href="https://example.com">Сайт</a><a href="mailto:info@example.com">Почта</a>');
        self::assertStringContainsString('href="https://example.com"', $safe);
        self::assertStringContainsString('href="mailto:info', $safe);

        $unsafe = $this->sanitize('<a href="javascript:alert(1)">Клик</a>');
        self::assertStringNotContainsString('javascript', $unsafe);
    }

    public function testReturnsEmptyStringWhenOnlyForbiddenContent(): void
    {
        self::assertSame('', $this->sanitize('<script>alert(1)</script>'));
    }

    public function testKeepsFormattingAndLists(): void
    {
        $sanitized = $this->sanitize('<h2>Заголовок</h2><ul><li><strong>Пункт</strong></li></ul>');

        self::assertStringContainsString('<h2>Заголовок</h2>', $sanitized);
        self::assertStringContainsString('<ul><li><strong>Пункт</strong></li></ul>', $sanitized);
    }

    private function sanitize(string $html): string
    {
        /** @var CampaignBodySanitizer $sanitizer */
        $sanitizer = static::getContainer()->get(CampaignBodySanitizer::class);

        return $sanitizer->sanitize($html);
    }
}
