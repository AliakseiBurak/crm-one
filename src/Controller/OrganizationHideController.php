<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrganizationHideRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHideService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Управление скрытием организаций (change organization-hiding, ADR-0012).
 * Доступно только администратору: реестр «Скрытые организации» со
 * встроенной формой добавления и возврат видимости одному менеджеру.
 */
#[Route('/admin/hides')]
#[IsGranted('ROLE_ADMIN')]
class OrganizationHideController extends AbstractController
{
    public function __construct(
        private readonly OrganizationHideRepository $hides,
        private readonly OrganizationRepository $organizations,
        private readonly UserRepository $users,
        private readonly OrganizationHideService $hideService,
    ) {}

    #[Route('', name: 'app_organization_hide_list', methods: ['GET', 'POST'])]
    public function list(Request $request): Response
    {
        if ('POST' === $request->getMethod()) {
            return $this->handleCreate($request);
        }

        $sort = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('dir', 'ASC'));

        $grouped = [];
        foreach ($this->hides->findBy([], ['hiddenAt' => 'DESC']) as $hide) {
            $grouped[(int) $hide->organization->id]['organization'] = $hide->organization;
            $grouped[(int) $hide->organization->id]['hides'][] = $hide;
        }

        usort(
            $grouped,
            static function (array $a, array $b) use ($sort, $direction): int {
                $cmp = match ($sort) {
                    'manager' => strcmp(
                        (string) ($a['hides'][0]->manager->email ?? ''),
                        (string) ($b['hides'][0]->manager->email ?? ''),
                    ),
                    'createdAt' => $a['organization']->createdAt <=> $b['organization']->createdAt,
                    'industry' => strcmp((string) $a['organization']->industry, (string) $b['organization']->industry),
                    'hiddenAt' => $a['hides'][0]->hiddenAt <=> $b['hides'][0]->hiddenAt,
                    default => strcmp(
                        (string) $a['organization']->name,
                        (string) $b['organization']->name,
                    ),
                };

                return 'DESC' === $direction ? -$cmp : $cmp;
            },
        );

        return $this->render('organization_hide/list.html.twig', [
            'grouped' => $grouped,
            'organizations' => $this->organizations->findBy([], ['name' => 'ASC']),
            'managers' => $this->users->findManagers(),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    private function handleCreate(Request $request): Response
    {
        $this->assertCsrfToken($request);

        $organization = $this->organizations->find((int) $request->request->get('organization', 0));
        $selectedManagerIds = array_filter(array_map('intval', $request->request->all('managers')));

        if (null === $organization) {
            $this->addFlash('error', 'Организация обязательна для выбора');

            return $this->redirectToRoute('app_organization_hide_list');
        }

        if ([] !== $selectedManagerIds) {
            // Проверяем, что выбраны только менеджеры
            $invalid = $this->hideService->validateHideTargets($selectedManagerIds);
            if ([] !== $invalid) {
                $this->addFlash('error', 'Скрыть можно только от менеджеров');

                return $this->redirectToRoute('app_organization_hide_list');
            }

            $managers = [];
            foreach ($selectedManagerIds as $managerId) {
                $manager = $this->users->find($managerId);
                if (null !== $manager) {
                    $managers[] = $manager;
                }
            }

            // Повторное скрытие пары отклоняется с ошибкой конфликта
            $duplicated = $this->hideService->findDuplicateTargets($organization, $managers);
            if ([] !== $duplicated) {
                $emails = array_filter(array_map(
                    static fn(User $m): ?string => $m->email,
                    $duplicated,
                ));
                $this->addFlash('error', 'Организация уже скрыта от: ' . implode(', ', $emails));

                return $this->redirectToRoute('app_organization_hide_list');
            }

            $created = $this->hideService->hide($organization, $managers);
        } else {
            // Менеджер не выбран — скрываем от всех
            $created = $this->hideService->hideFromAllManagers($organization);
        }

        $this->addFlash('success', \sprintf('Организация скрыта от %d менеджеров', $created));

        return $this->redirectToRoute('app_organization_hide_list');
    }

    #[Route('/{id}/delete', name: 'app_organization_hide_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id, Request $request): Response
    {
        $this->assertCsrfToken($request);

        $hide = $this->hides->find($id);
        if (null === $hide) {
            throw $this->createNotFoundException('Запись скрытия не найдена');
        }

        $this->hideService->unhide($hide->organization, $hide->manager);
        $this->addFlash('success', 'Организация снова видна менеджеру');

        return $this->redirectToRoute('app_organization_hide_list');
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('hide', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
