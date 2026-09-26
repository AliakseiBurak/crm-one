<?php

declare(strict_types=1);

namespace App\Service;

use Twig\Environment;

/**
 * Базовое тело письма рассылки (change email-base-template): значение поля
 * «Текст письма» по умолчанию на форме создания. Рендерит
 * emails/campaign_base_body.html.twig — единственный источник разметки
 * дефолта, чтобы форма, dev-фикстуры и отправляемое письмо не разошлись.
 *
 * Тело намеренно не self-contained по CSS: презентацию несут инлайн-стили,
 * потому что санитайзер не пропускает class (design D1).
 */
final class CampaignBaseBody
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * @param string|null $content Готовая HTML-разметка контентной области
     *                             вместо приветствия по умолчанию. Рендерится
     *                             как есть, поэтому передавать можно только
     *                             доверенную разметку (dev-фикстуры), не
     *                             пользовательский ввод.
     */
    public function render(?string $content = null): string
    {
        return $this->twig->render('emails/campaign_base_body.html.twig', [
            'content' => $content,
        ]);
    }
}
