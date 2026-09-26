<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CampaignBaseBody;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Unit-тесты CampaignBaseBody (change email-base-template): базовое тело —
 * значение поля «Текст письма» по умолчанию на форме создания и общий источник
 * футера для dev-фикстур. Раскладки письма в нём быть не должно: ею владеет
 * шелл (design D3).
 */
final class CampaignBaseBodyTest extends TestCase
{
    private CampaignBaseBody $baseBody;

    protected function setUp(): void
    {
        $this->baseBody = new CampaignBaseBody(
            new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]),
        );
    }

    public function testDefaultContentIsGreetingAndFooter(): void
    {
        $html = $this->baseBody->render();

        self::assertStringContainsString('{{greeting}}', $html);
        self::assertStringContainsString('{{organization_name}}', $html);
        self::assertStringContainsString('Отписаться от рассылки', $html);
        self::assertStringContainsString('{{unsubscribe_url}}', $html);
    }

    /**
     * Футер — единственный источник, поэтому он обязан быть в теле при любом
     * переопределении контента.
     *
     * Проверяется именно видимый текст: строка в alt логотипа содержит то же
     * название компании, и утверждение по сырому HTML нашло бы её вместо
     * видимого футера и пропустило бы опечатку в нём.
     */
    public function testFooterSurvivesContentOverride(): void
    {
        $html = $this->baseBody->render('<p>Скидка 20%</p>');

        self::assertStringContainsString('Скидка 20%', $html);
        self::assertStringContainsString('ОДО «Центр Обучающих Технологий»', $this->visibleText($html));
        self::assertStringContainsString('+375 (29) 684-84-26', $this->visibleText($html));
        self::assertStringContainsString('logo.svg', $html);
    }

    /**
     * Переопределение заменяет приветствие, а не добавляет его вторым абзацем —
     * иначе письмо в фикстурах начинается с двух «Уважаемый(ая)».
     */
    public function testContentOverrideReplacesDefaultGreeting(): void
    {
        $html = $this->baseBody->render('<p>{{greeting}}! Скидка</p>');

        self::assertSame(1, substr_count($html, '{{greeting}}'));
        self::assertStringNotContainsString('Приглашаем вас на курсы', $html);
    }

    public function testTemplateHasNoLetterLayout(): void
    {
        $html = $this->baseBody->render();

        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('<head', $html);
        self::assertStringNotContainsString('<body', $html);
        self::assertStringNotContainsString('width: 600px', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    /**
     * Видимый текст без разметки: в нём нет ни атрибутов, ни содержимого alt,
     * поэтому утверждение о видимом футере не может «найтись» в атрибуте.
     */
    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
