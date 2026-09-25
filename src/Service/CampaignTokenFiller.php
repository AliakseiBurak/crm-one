<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Organization;

/**
 * Подстановка токенов письма (design D6): {{greeting}}, {{contact_name}},
 * {{organization_name}}, {{unsubscribe_url}}. Значения для HTML-контекста
 * (тело, прехедер) экранируются, для темы — подставляются как plain text.
 */
final class CampaignTokenFiller
{
    private const array TOKENS = [
        '{{contact_name}}',
        '{{organization_name}}',
        '{{greeting}}',
        '{{unsubscribe_url}}',
    ];

    public function fillHtml(
        string $template,
        ?Contact $contact,
        Organization $organization,
        string $unsubscribeUrl = '',
    ): string {
        return $this->replace($template, $this->values($contact, $organization, $unsubscribeUrl), true);
    }

    public function fillPlain(
        string $template,
        ?Contact $contact,
        Organization $organization,
        string $unsubscribeUrl = '',
    ): string {
        return $this->replace($template, $this->values($contact, $organization, $unsubscribeUrl), false);
    }

    /**
     * @return list<string>
     */
    private function values(?Contact $contact, Organization $organization, string $unsubscribeUrl): array
    {
        $greeting = null !== $contact
            ? 'Уважаемый(ая) ' . $contact->name
            : 'Уважаемые сотрудники ' . $organization->name;
        $contactName = null !== $contact ? $contact->name : $organization->name;

        return [
            $contactName,
            $organization->name,
            $greeting,
            $unsubscribeUrl,
        ];
    }

    /**
     * @param list<string> $values
     */
    private function replace(string $template, array $values, bool $escapeHtml): string
    {
        if ($escapeHtml) {
            $values = array_map(
                static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $values,
            );
        }

        return str_replace(self::TOKENS, $values, $template);
    }
}
