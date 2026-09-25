<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Contact;
use App\Entity\Organization;
use App\Service\CampaignTokenFiller;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты CampaignTokenFiller (design D6): подстановка токенов
 * {{greeting}}, {{contact_name}}, {{organization_name}}, {{unsubscribe_url}}
 * и HTML-экранирование значений для тела/прехедера (сценарий
 * «Экранирование значений токенов в HTML»).
 */
final class CampaignTokenFillerTest extends TestCase
{
    private CampaignTokenFiller $filler;

    private Organization $org;

    protected function setUp(): void
    {
        $this->filler = new CampaignTokenFiller();
        $this->org = new Organization()->setName('ООО Ромашка');
    }

    public function testGreetingWithContact(): void
    {
        $contact = $this->contact('Иван Петров');

        self::assertSame(
            'Уважаемый(ая) Иван Петров! Добро пожаловать.',
            $this->filler->fillHtml('{{greeting}}! Добро пожаловать.', $contact, $this->org),
        );
    }

    public function testGreetingWithoutContact(): void
    {
        self::assertSame(
            'Уважаемые сотрудники ООО Ромашка! Ждём вас.',
            $this->filler->fillHtml('{{greeting}}! Ждём вас.', null, $this->org),
        );
    }

    public function testContactNameFallsBackToOrganizationName(): void
    {
        self::assertSame(
            'ООО Ромашка, здравствуйте.',
            $this->filler->fillHtml('{{contact_name}}, здравствуйте.', null, $this->org),
        );
    }

    public function testOrganizationNameAndUnsubscribeUrl(): void
    {
        self::assertSame(
            'ООО Ромашка: https://b2b-crm.local/unsubscribe/abc123',
            $this->filler->fillHtml('{{organization_name}}: {{unsubscribe_url}}', null, $this->org, 'https://b2b-crm.local/unsubscribe/abc123'),
        );
    }

    public function testMainContactDoesNotAffectTokens(): void
    {
        $this->contact('Мария Смирнова')->setIsMain(true);

        self::assertSame(
            'Уважаемые сотрудники ООО Ромашка! ООО Ромашка, добро пожаловать.',
            $this->filler->fillHtml('{{greeting}}! {{contact_name}}, добро пожаловать.', null, $this->org),
        );
    }

    public function testFillHtmlEscapesTokenValues(): void
    {
        $org = new Organization()->setName('ООО <Ромашка> & "Партнёр"');

        $html = $this->filler->fillHtml('<p>{{organization_name}}</p>', null, $org);

        self::assertSame('<p>ООО &lt;Ромашка&gt; &amp; &quot;Партнёр&quot;</p>', $html);
    }

    public function testFillHtmlEscapesTokenInsideAttribute(): void
    {
        $org = new Organization()->setName('ООО "Ромашка"');

        $html = $this->filler->fillHtml('<a href="{{unsubscribe_url}}" title="{{organization_name}}">Отписаться</a>', null, $org, 'https://x.local/u/1?a=1&b=2');

        self::assertSame(
            '<a href="https://x.local/u/1?a=1&amp;b=2" title="ООО &quot;Ромашка&quot;">Отписаться</a>',
            $html,
        );
    }

    public function testFillPlainDoesNotEscape(): void
    {
        $org = new Organization()->setName('ООО <Ромашка>');

        self::assertSame('Тема: ООО <Ромашка>', $this->filler->fillPlain('Тема: {{organization_name}}', null, $org));
    }

    private function contact(string $name): Contact
    {
        return new Contact()->setOrganization($this->org)->setName($name);
    }
}
