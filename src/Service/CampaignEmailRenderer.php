<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Contact;
use App\Entity\Organization;
use Symfony\Component\Mime\HtmlToTextConverter\DefaultHtmlToTextConverter;
use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;
use Twig\Environment;

/**
 * Единственный источник правды о том, как выглядит письмо рассылки (design D5):
 * подставляет токены (CampaignTokenFiller), рендерит шелл
 * templates/emails/campaign.html.twig с инлайн-CSS и генерирует текстовую
 * часть. Используется и MailingService, и всеми предпросмотрами, поэтому
 * предпросмотр показывает ровно то, что уйдёт получателю.
 *
 * С change email-base-template шелл отвечает только за то, что менеджеру не
 * редактировать: doctype, <head> с базовым CSS, скрытый прехедер, раскладку
 * 600px и tracking-pixel. Всё видимое содержимое — включая подпись, телефоны,
 * логотип и ссылку отписки — приезжает в bodyHtml из тела рассылки, которое
 * предзаполняется базовым шаблоном emails/campaign_base_body.html.twig.
 */
final class CampaignEmailRenderer
{
    public const string DEMO_ORGANIZATION_NAME = 'ООО «Пример»';

    public const string DEMO_CONTACT_NAME = 'Иван Петров';

    public const string DEMO_UNSUBSCRIBE_URL = 'https://example.com/unsubscribe/demo-token';

    public function __construct(
        private readonly Environment $twig,
        private readonly CampaignTokenFiller $tokenFiller,
        private readonly HtmlToTextConverterInterface $textConverter = new DefaultHtmlToTextConverter(),
    ) {}

    /**
     * @param string|null $unsubscribeUrl   Абсолютный URL отписки получателя
     * @param string|null $trackingPixelUrl Абсолютный URL tracking-pixel; null — без пикселя
     */
    public function render(
        Campaign $campaign,
        ?Contact $contact,
        Organization $organization,
        ?string $unsubscribeUrl = null,
        ?string $trackingPixelUrl = null,
    ): RenderedEmail {
        $subject = $this->tokenFiller->fillPlain($campaign->subject, $contact, $organization);
        $html = $this->twig->render('emails/campaign.html.twig', [
            'subject' => $subject,
            'bodyHtml' => $this->tokenFiller->fillHtml($campaign->body, $contact, $organization, $unsubscribeUrl ?? ''),
            'preheader' => null !== $campaign->previewText
                ? $this->tokenFiller->fillPlain(
                    $campaign->previewText,
                    $contact,
                    $organization,
                    $unsubscribeUrl ?? '',
                )
                : null,
            'unsubscribeUrl' => $unsubscribeUrl,
            'trackingPixelUrl' => $trackingPixelUrl,
        ]);

        return new RenderedEmail($subject, $html, $this->textConverter->convert($html, 'UTF-8'));
    }

    /**
     * Предпросмотр с фиксированными демо-значениями токенов (design D7);
     * $bodyHtml позволяет отрендерить несохранённое тело из формы.
     */
    public function renderDemo(Campaign $campaign, ?string $bodyHtml = null): RenderedEmail
    {
        $demo = new Campaign()
            ->setName($campaign->name)
            ->setSubject($campaign->subject)
            ->setPreviewText($campaign->previewText)
            ->setBody($bodyHtml ?? $campaign->body);

        return $this->render(
            $demo,
            new Contact()->setName(self::DEMO_CONTACT_NAME),
            new Organization()->setName(self::DEMO_ORGANIZATION_NAME),
            self::DEMO_UNSUBSCRIBE_URL,
        );
    }
}
