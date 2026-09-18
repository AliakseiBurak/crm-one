<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Repository\CampaignRecipientRepository;
use App\Repository\OrganizationGroupRepository;
use App\Repository\OrganizationHideRepository;
use App\Repository\OrganizationRepository;
use App\Service\OrganizationHideService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(requirements: ['id' => '\d+'])]
class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationGroupRepository $groups,
        private readonly CampaignRecipientRepository $campaignRecipients,
        private readonly OrganizationHideRepository $hides,
        private readonly OrganizationHideService $hideService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/organizations/new', name: 'app_organization_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('organization/form.html.twig', [
            'organization' => null,
            'errors' => [],
            'groups' => $this->availableGroupsFor($this->getUser()),
            'groupIds' => [],
        ]);
    }

    #[Route('/organizations/new', name: 'app_organization_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator): Response
    {
        $this->assertCsrfToken($request);

        $organization = new Organization();
        $errors = $this->applyRequest($request, $validator, $organization);
        $selectedGroupIds = $this->normalizeGroupSelection($request, $this->getUser());
        if ([] !== $errors) {
            return $this->render('organization/form.html.twig', [
                'organization' => $organization,
                'errors' => $errors,
                'groups' => $this->availableGroupsFor($this->getUser()),
                'groupIds' => $selectedGroupIds,
                'hides' => [],
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->em->persist($organization);
        $this->updateGroupMemberships($organization, $selectedGroupIds);
        $this->em->flush();

        return $this->redirectToRoute('app_dashboard', ['highlight' => $organization->id]);
    }

    #[Route('/organizations/{id}/edit', name: 'app_organization_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $organization = $this->accessibleOrganization($id);

        // Get available groups
        $groups = $this->availableGroupsFor($this->getUser());

        // Get current group memberships
        $groupIds = array_map(
            static fn(OrgGroupMembership $m): int => (int) $m->group->id,
            $organization->groupMemberships->toArray()
        );

        $hides = $this->hides->findForOrganization($organization);

        return $this->render('organization/form.html.twig', [
            'organization' => $organization,
            'errors' => [],
            'errorRecipients' => $this->campaignRecipients->findErrorRecipientsForOrganization($organization),
            'groups' => $groups,
            'groupIds' => $groupIds,
            'hides' => $hides,
        ]);
    }

    #[Route('/organizations/{id}/edit', name: 'app_organization_update', methods: ['POST'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): Response
    {
        $organization = $this->accessibleOrganization($id);
        $ajax = $request->isXmlHttpRequest();

        // Токен приходит из формы (FormData) или заголовком X-CSRF-Token.
        $this->assertCsrfToken($request);

        // Кнопка «Показать» (unhide) внутри формы редактирования: обрабатываем
        // до валидации, чтобы «Показать» не падал на пустом названии.
        // Только администратор может возвращать видимость (ADR-0012).
        $unhideId = $request->request->get('unhide');
        if (null !== $unhideId && $organization->id) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                throw new AccessDeniedHttpException('Только администратор может управлять скрытием');
            }

            $hide = $this->hides->find((string) $unhideId);
            if (null !== $hide && $hide->organization->id === $organization->id) {
                $this->hideService->unhide($hide->organization, $hide->manager);
                $this->addFlash('success', 'Организация снова видна менеджеру');
            }

            return $this->redirectToRoute('app_organization_edit', ['id' => $id]);
        }

        $errors = $this->applyRequest($request, $validator, $organization);
        $selectedGroupIds = $this->normalizeGroupSelection($request, $this->getUser());
        if ([] !== $errors) {
            if ($ajax) {
                return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->render('organization/form.html.twig', [
                'organization' => $organization,
                'errors' => $errors,
                'groups' => $this->availableGroupsFor($this->getUser()),
                'groupIds' => $selectedGroupIds,
                'hides' => $organization->id ? $this->hides->findForOrganization($organization) : [],
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // Handle group memberships
        $this->updateGroupMemberships($organization, $selectedGroupIds);

        // Сохранение изменений — updatedAt обновляется вручную (авто-таймстампов нет).
        $organization->touch();
        $this->em->flush();

        if ($ajax) {
            return $this->json([
                'ok' => true,
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'industry' => $organization->industry,
                    'annualPlan' => $organization->annualPlan,
                    'description' => $organization->description,
                    'hasUsedServices' => $organization->hasUsedServices,
                    'isActive' => $organization->isActive,
                ],
            ]);
        }

        return $this->redirectToRoute('app_dashboard', ['highlight' => $organization->id]);
    }

    #[Route('/organizations/{id}/delete', name: 'app_organization_delete', methods: ['GET'])]
    public function delete(int $id): Response
    {
        $organization = $this->accessibleOrganization($id);

        return $this->render('organization/delete.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[Route('/organizations/{id}/delete', name: 'app_organization_remove', methods: ['POST'])]
    public function remove(int $id, Request $request): Response
    {
        $organization = $this->accessibleOrganization($id);
        $this->assertCsrfToken($request);

        // Каскадное удаление контактов и звонков обеспечивает БД
        // (FK organization_id ON DELETE CASCADE).
        $this->em->remove($organization);
        $this->em->flush();

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Заполняет организацию данными формы и возвращает ошибки валидации,
     * сгруппированные по полям (name, industry).
     *
     * @return array<string, string>
     */
    private function applyRequest(Request $request, ValidatorInterface $validator, Organization $organization): array
    {
        $organization->setName(trim((string) $request->request->get('name', '')));
        $industry = $request->request->get('industry');
        $organization->setIndustry($industry !== null && $industry !== '' ? trim((string) $industry) : null);
        $annualPlan = $request->request->get('annualPlan');
        $organization->setAnnualPlan($annualPlan !== null && $annualPlan !== '' ? trim((string) $annualPlan) : null);
        $description = $request->request->get('description');
        $organization->setDescription($description !== null && $description !== '' ? trim((string) $description) : null);
        $organization->setIsActive((bool) $request->request->get('isActive', true));
        $isOptedOut = (bool) $request->request->get('isOptedOut', false);
        $organization->setIsOptedOut($isOptedOut);
        // Decision 5 (call-result-deal-and-optout): при снятом отказе причина
        // игнорируется, что бы ни пришло в запросе (setIsOptedOut(false)
        // сбрасывает reason/date, но их могло перезаписать поле формы).
        if ($isOptedOut) {
            $optOutReason = $request->request->get('optOutReason');
            $organization->setOptOutReason($optOutReason !== null && $optOutReason !== '' ? trim((string) $optOutReason) : null);
        }
        $organization->setHasUsedServices((bool) $request->request->get('hasUsedServices', false));

        $violations = $validator->validate($organization);

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * Организация в области доступа пользователя: менеджеру — все
     * организации, кроме скрытых (ADR-0012); администратору — все
     * организации без ограничений (ADR-0008).
     */
    private function accessibleOrganization(int $id): Organization
    {
        $organization = $this->organizations->find($id);
        if (null === $organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        $accessibleIds = $this->organizations->findAccessibleIds($this->getUser());
        if (null !== $accessibleIds && !\in_array($id, $accessibleIds, true)) {
            throw new AccessDeniedHttpException('Организация вне области доступа');
        }

        return $organization;
    }

    /**
     * Защита state-changing форм от CSRF; для AJAX-запросов токен передаётся
     * в заголовке X-CSRF-Token.
     */
    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('organization', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }

    /**
     * Выбранные в форме группы, ограниченные областью доступа пользователя:
     * администратору — все, менеджеру — только созданные и назначенные
     * (ADR-0011). Недоступные группы игнорируются.
     *
     * @return int[]
     */
    private function normalizeGroupSelection(Request $request, ?User $user): array
    {
        $selected = array_map('intval', $request->request->all('groups'));
        $allowed = array_map(
            static fn(OrganizationGroup $g): int => (int) $g->id,
            $this->availableGroupsFor($user)
        );

        return array_values(array_intersect($selected, $allowed));
    }

    /**
     * Группы, доступные для выбора при создании/редактировании организации:
     * администратору — все, менеджеру — созданные и назначенные (ADR-0011).
     *
     * @return OrganizationGroup[]
     */
    private function availableGroupsFor(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        if (UserRole::Admin === $user->role) {
            return $this->groups->findAllGroups();
        }

        return $this->groups->findForManager($user);
    }

    private function updateGroupMemberships(Organization $organization, array $selectedGroupIds): void
    {
        // Remove existing memberships not in selection
        foreach ($organization->groupMemberships as $membership) {
            if (!\in_array($membership->group->id, $selectedGroupIds, true)) {
                $organization->groupMemberships->removeElement($membership);
                $this->em->remove($membership);
            }
        }

        // Add new memberships
        foreach ($selectedGroupIds as $groupId) {
            $alreadyMember = false;
            foreach ($organization->groupMemberships as $existing) {
                if ($existing->group->id === $groupId) {
                    $alreadyMember = true;
                    break;
                }
            }

            if (!$alreadyMember) {
                $group = $this->groups->find($groupId);
                if (null !== $group) {
                    $membership = new OrgGroupMembership($organization, $group);
                    $this->em->persist($membership);
                }
            }
        }
    }
}
