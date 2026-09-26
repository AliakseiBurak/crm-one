<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Санитизация HTML-тела рассылки (design D4): единая точка очистки при
 * сохранении кампании. Конфигурация — html_sanitizer.sanitizer.campaign_body
 * («разрешено почти всё, запрещено опасное»: allow_safe_elements минус
 * drop_elements, allowlist CSS-свойств через
 * App\Html\CampaignStyleAttributeSanitizer).
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

    /**
     * Элементы, которые присутствовали в исходном HTML, но не пережили
     * очистку (change email-base-template). Нужны, чтобы предупредить
     * пользователя: молчаливое удаление разметки выглядит как ошибка
     * редактора. Сравнение идёт по именам тегов, поэтому нечувствительно к
     * нормализации сериализации (`<br>` → `<br />`, `+` → `&#43;`).
     *
     * @return list<string>
     */
    public function removedElements(string $html, string $sanitized): array
    {
        return array_values(array_diff(self::tagNames($html), self::tagNames($sanitized)));
    }

    /**
     * @return list<string>
     */
    private static function tagNames(string $html): array
    {
        preg_match_all('/<\s*\/?\s*([a-z][a-z0-9]*)/i', $html, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }
}
