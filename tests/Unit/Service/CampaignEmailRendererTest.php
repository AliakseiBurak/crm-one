<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Campaign;
use App\Entity\Organization;
use App\Service\CampaignEmailRenderer;
use App\Service\CampaignTokenFiller;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extra\CssInliner\CssInlinerExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Unit-тесты CampaignEmailRenderer (design D5): полный HTML-документ, шелл
 * 600px, инлайн CSS, скрытый экранированный прехедер, текстовая часть,
 * tracking-pixel только при наличии URL и демо-значения предпросмотра.
 *
 * change email-body-base-template: шелл больше не содержит футер — видимое
 * содержимое письма (подпись, телефоны, логотип, ссылка отписки) приезжает в
 * bodyHtml из тела рассылки, а презентация тела держится на инлайн-стилях.
 */
final class CampaignEmailRendererTest extends TestCase
{
    private CampaignEmailRenderer $renderer;

    private Organization $org;

    protected function setUp(): void
    {
        $twig = new Environment(
            new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'),
            ['strict_variables' => true],
        );
        $twig->addExtension(new CssInlinerExtension());

        $this->renderer = new CampaignEmailRenderer($twig, new CampaignTokenFiller());
        $this->org = new Organization()->setName('ООО Ромашка');
    }

    public function testRendersFullHtmlDocumentWith600pxShell(): void
    {
        $rendered = $this->renderer->render($this->campaign(), null, $this->org);

        self::assertStringStartsWith('<!doctype html>', $rendered->html);
        self::assertStringContainsString('<html lang="ru">', $rendered->html);
        self::assertStringContainsString('width="600"', $rendered->html);
        self::assertStringContainsString('</html>', $rendered->html);
    }

    public function testInlinesShellCss(): void
    {
        $html = $this->renderer->render($this->campaign(), null, $this->org)->html;

        self::assertStringContainsString('<body style="margin: 0;', $html);
        self::assertMatchesRegularExpression(
            '/<table[^>]*class="email-container"[^>]*style="[^"]*width: 600px/',
            $html,
        );
    }

    /**
     * С change email-base-template презентация письма лежит в теле инлайном, а
     * не в классах: санитайзер не пропускает class, поэтому правила <style>
     * шелла не могут быть опорой для содержимого тела.
     */
    public function testBodyKeepsItsOwnInlineStyles(): void
    {
        $campaign = $this->campaign()->setBody(
            '<table style="width: 600px; max-width: 600px; background-color: #ffffff">'
            . '<tbody><tr><td style="padding: 24px; font-size: 15px">Контент</td></tr></tbody></table>',
        );

        $html = $this->renderer->render($campaign, null, $this->org)->html;

        self::assertStringContainsString('width: 600px; max-width: 600px; background-color: #ffffff', $html);
        self::assertStringContainsString('padding: 24px; font-size: 15px', $html);
    }

    /**
     * Базовая рамка письма приезжает в тело, поэтому отрендеренное письмо
     * содержит подпись и ссылку отписки из тела, а не из шелла.
     */
    public function testRendersBaseBodyTemplateAsLetterContent(): void
    {
        $base = (new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates')))
            ->render('emails/campaign_base_body.html.twig');
        $campaign = $this->campaign()->setBody($base);

        $html = $this->renderer
            ->render($campaign, null, $this->org, 'https://b2b-crm.local/unsubscribe/abc')
            ->html;

        self::assertStringContainsString('ОДО «Центр Обучающих Технологий»', $html);
        self::assertStringContainsString('+375 (29) 684-84-26', $html);
        self::assertStringContainsString('https://trainingcenter.by/catalog', $html);
        self::assertStringContainsString('logo.svg', $html);
        self::assertStringContainsString('href="https://b2b-crm.local/unsubscribe/abc"', $html);
        self::assertStringNotContainsString('{{unsubscribe_url}}', $html);
    }

    /**
     * Регрессия: когда и шелл, и базовое тело несли раскладку, письмо
     * получалось вложенным дважды — карточка 600px внутри карточки 600px с
     * удвоенным отступом. Раскладкой владеет шелл, тело её не повторяет.
     */
    public function testLetterHasExactlyOneLayout(): void
    {
        $base = (new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates')))
            ->render('emails/campaign_base_body.html.twig');
        $campaign = $this->campaign()->setBody($base);

        $html = $this->renderer->render($campaign, null, $this->org)->html;

        self::assertSame(1, substr_count($html, 'padding: 24px 12px'));
        self::assertSame(1, substr_count($html, 'class="email-container"'));
    }

    public function testShellDoesNotAddFooterOfItsOwn(): void
    {
        $html = $this->renderer->render($this->campaign(), null, $this->org)->html;

        self::assertStringNotContainsString('Отписаться от рассылки', $html);
        self::assertStringNotContainsString('trainingcenter.by', $html);
    }

    public function testPreheaderIsHiddenAndEscaped(): void
    {
        $campaign = $this->campaign()->setPreviewText('Новости <b>недели</b>');

        $html = $this->renderer->render($campaign, null, $this->org)->html;

        self::assertStringContainsString('mso-hide:all', $html);
        self::assertStringContainsString('Новости &lt;b&gt;недели&lt;/b&gt;', $html);
    }

    public function testPreheaderFillsAndEscapesUnsubscribeUrlOnce(): void
    {
        $campaign = $this->campaign()->setPreviewText('Отписаться: {{unsubscribe_url}}');

        $html = $this->renderer->render(
            $campaign,
            null,
            $this->org,
            'https://b2b-crm.local/unsubscribe/abc?a=1&b=2',
        )->html;

        self::assertStringNotContainsString('{{unsubscribe_url}}', $html);
        self::assertStringContainsString(
            'Отписаться: https://b2b-crm.local/unsubscribe/abc?a=1&amp;b=2',
            $html,
        );
    }

    public function testNoPreheaderBlockWhenPreviewTextIsEmpty(): void
    {
        $html = $this->renderer->render($this->campaign()->setPreviewText(null), null, $this->org)->html;

        self::assertStringNotContainsString('mso-hide:all', $html);
    }

    public function testTokenValuesAreEscapedInHtml(): void
    {
        $campaign = $this->campaign()->setBody('<p>{{organization_name}}</p>');
        $org = new Organization()->setName('ООО <Ромашка> & "Партнёр"');

        $html = $this->renderer->render($campaign, null, $org)->html;

        self::assertStringContainsString('<p>ООО &lt;Ромашка&gt; &amp; "Партнёр"</p>', $html);
    }

    public function testSubjectIsPlainAndEscapedInTitle(): void
    {
        $campaign = $this->campaign()->setSubject('Тема <b>');

        $rendered = $this->renderer->render($campaign, null, $this->org);

        self::assertSame('Тема <b>', $rendered->subject);
        self::assertStringContainsString('<title>Тема &lt;b&gt;</title>', $rendered->html);
    }

    public function testTextPartHasNoHtmlTags(): void
    {
        $rendered = $this->renderer->render($this->campaign(), null, $this->org);

        self::assertStringNotContainsString('<', $rendered->text);
        self::assertStringContainsString('Уважаемые сотрудники ООО Ромашка', $rendered->text);
    }

    public function testTrackingPixelIsIncludedOnlyWhenUrlProvided(): void
    {
        $withPixel = $this->renderer->render(
            $this->campaign(),
            null,
            $this->org,
            null,
            'https://b2b-crm.local/t/pixel.png',
        );
        self::assertStringContainsString('https://b2b-crm.local/t/pixel.png', $withPixel->html);

        $withoutPixel = $this->renderer->render($this->campaign(), null, $this->org);
        self::assertStringNotContainsString('/t/', $withoutPixel->html);
    }

    public function testUnsubscribeUrlFromBodyIsFilled(): void
    {
        $campaign = $this->campaign()->setBody(
            '<a href="{{unsubscribe_url}}">Отписаться от рассылки</a>',
        );

        $html = $this->renderer->render($campaign, null, $this->org, 'https://b2b-crm.local/unsubscribe/abc')->html;

        self::assertStringContainsString('href="https://b2b-crm.local/unsubscribe/abc"', $html);
        self::assertStringContainsString('Отписаться от рассылки', $html);
    }

    public function testRenderDemoUsesFixedValuesAndBodyOverride(): void
    {
        $campaign = $this->campaign()->setBody('<p>сохранённое</p>');

        $rendered = $this->renderer->renderDemo($campaign, '<p>{{greeting}}</p><a href="{{unsubscribe_url}}">Отписаться</a>');

        self::assertStringContainsString('<p>Уважаемый(ая) Иван Петров</p>', $rendered->html);
        self::assertStringContainsString(CampaignEmailRenderer::DEMO_ORGANIZATION_NAME, $rendered->html);
        self::assertStringContainsString(CampaignEmailRenderer::DEMO_UNSUBSCRIBE_URL, $rendered->html);
        self::assertStringNotContainsString('сохранённое', $rendered->html);
    }

    private function campaign(): Campaign
    {
        return new Campaign()
            ->setName('Акция')
            ->setSubject('Для {{organization_name}}')
            ->setPreviewText('{{greeting}}')
            ->setBody('<p>{{greeting}}! Письмо для {{contact_name}}.</p>');
    }
}
