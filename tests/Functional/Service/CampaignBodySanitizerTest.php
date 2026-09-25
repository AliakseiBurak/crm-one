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

    public function testDropsCodeBlocksAndKeepsInlineCode(): void
    {
        $sanitized = $this->sanitize(
            '<pre style="background-color: #eee"><code>блок</code></pre><p><code style="color: red">инлайн</code></p>',
        );

        self::assertStringNotContainsString('<pre', $sanitized);
        self::assertStringNotContainsString('блок', $sanitized);
        self::assertStringContainsString('<code style="color: red">инлайн</code>', $sanitized);
    }

    public function testKeepsEmailSafeTableImageAndStyledDiv(): void
    {
        $html = '<table border="1" cellpadding="4" style="width: 100%"><tbody><tr style="height: 20px">'
            . '<th colspan="2" rowspan="1" bgcolor="#eee" style="background-color: #f5f5f5">Заголовок</th></tr>'
            . '<td colspan="2" style="background-color: #f5f5f5">Ячейка</td></tr></tbody></table>'
            . '<img src="https://cdn.example/logo.png" alt="Логотип" title="Заголовок" width="120" height="60" style="display: block">'
            . '<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow" style="color: blue">Ссылка</a>'
            . '<div style="padding: 8px"><p>Блок</p></div>';

        $sanitized = $this->sanitize($html);

        self::assertStringContainsString('<table style="width: 100%">', $sanitized);
        self::assertStringNotContainsString('border=', $sanitized);
        self::assertStringNotContainsString('cellpadding=', $sanitized);
        self::assertStringNotContainsString('bgcolor=', $sanitized);
        self::assertStringContainsString('<tr style="height: 20px">', $sanitized);
        self::assertStringContainsString('colspan="2"', $sanitized);
        self::assertStringContainsString('rowspan="1"', $sanitized);
        self::assertStringContainsString('style="background-color: #f5f5f5"', $sanitized);
        self::assertStringContainsString('src="https://cdn.example/logo.png"', $sanitized);
        self::assertStringContainsString('alt="Логотип"', $sanitized);
        self::assertStringContainsString('title="Заголовок"', $sanitized);
        self::assertStringContainsString('width="120"', $sanitized);
        self::assertStringContainsString('height="60"', $sanitized);
        self::assertStringContainsString('target="_blank"', $sanitized);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $sanitized);
        self::assertStringContainsString('<div style="padding: 8px">', $sanitized);
    }

    public function testDropsUnsupportedTableSections(): void
    {
        $sanitized = $this->sanitize(
            '<table><thead style="color: red"><tr><th>Шапка</th></tr></thead>'
            . '<tbody><tr><td>Тело</td></tr></tbody><tfoot><tr><td>Подвал</td></tr></tfoot></table>',
        );

        self::assertStringNotContainsString('<thead', $sanitized);
        self::assertStringNotContainsString('<tfoot', $sanitized);
        self::assertStringNotContainsString('Шапка', $sanitized);
        self::assertStringNotContainsString('Подвал', $sanitized);
        self::assertStringContainsString('<td>Тело</td>', $sanitized);
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
