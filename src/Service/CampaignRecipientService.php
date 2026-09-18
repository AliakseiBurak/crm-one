<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\User;
use App\Repository\CampaignRecipientRepository;
use App\Repository\OrganizationGroupRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Сервис управления адресатами рассылок (change organization-groups).
 */
final class CampaignRecipientService
{
    public function __construct(
        private readonly CampaignRecipientRepository $recipients,
        private readonly OrganizationGroupRepository $groups,
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Массовое добавление организаций из группы в рассылку.
     *
     * Пропускаются уже существующие адресаты и организации без e-mail у
     * контактов (spec: campaigns — «Организация без e-mail не может стать
     * адресатом» распространяется на все пути создания адресатов).
     *
     * @return array{added: int, skipped: int, no_email: int, total: int, opted_out: int}
     */
    public function bulkAddByGroup(Campaign $campaign, OrganizationGroup $group, User $user): array
    {
        // Verify campaign is not archived
        if (CampaignStatus::Archived === $campaign->status) {
            throw new \InvalidArgumentException('Адресаты недоступны для рассылки в статусе «В архиве»');
        }

        // Verify manager has access to group
        if (UserRole::Admin !== $user->role) {
            $accessibleGroups = $this->groups->findForManager($user);
            $hasAccess = false;
            foreach ($accessibleGroups as $accessibleGroup) {
                if ($accessibleGroup->id === $group->id) {
                    $hasAccess = true;
                    break;
                }
            }
            if (!$hasAccess) {
                throw new AccessDeniedHttpException('Группа вне области доступа');
            }
        }

        // Get organizations in group
        $organizations = [];
        foreach ($group->memberships as $membership) {
            $organizations[] = $membership->organization;
        }

        $total = $group->memberships->count();
        $added = 0;
        $skipped = 0;
        $noEmail = 0;
        $optedOut = 0;

        // Скрытые организации не становятся адресатами (ADR-0012): менеджеру
        // доступны только организации его области доступа; администратору —
        // все (null). Отфильтрованные считаются пропущенными.
        $accessibleIds = $this->organizations->findAccessibleIds($user);
        if (null !== $accessibleIds) {
            $before = \count($organizations);
            $organizations = array_values(array_filter(
                $organizations,
                static fn(Organization $o): bool => \in_array($o->id, $accessibleIds, true),
            ));
            $skipped += $before - \count($organizations);
        }

        foreach ($organizations as $organization) {
            // Check if recipient already exists
            $existing = $this->recipients->findOneBy([
                'campaign' => $campaign,
                'organization' => $organization,
            ]);

            if (null !== $existing) {
                ++$skipped;
                continue;
            }

            // Организации, отписанные от рассылок, пропускаются.
            if ($organization->isOptedOut) {
                ++$skipped;
                ++$optedOut;
                continue;
            }

            // Доменная проверка: организация без e-mail адресатом не становится.
            if (!$this->organizationHasEmail($organization)) {
                ++$skipped;
                ++$noEmail;
                continue;
            }

            $recipient = new CampaignRecipient($campaign, $organization);
            $this->em->persist($recipient);
            ++$added;
        }

        $this->em->flush();

        return ['added' => $added, 'skipped' => $skipped, 'no_email' => $noEmail, 'total' => $total, 'opted_out' => $optedOut];
    }

    private function organizationHasEmail(Organization $organization): bool
    {
        foreach ($organization->contacts as $contact) {
            if (null !== $contact->email && '' !== $contact->email) {
                return true;
            }
        }

        return false;
    }
}
