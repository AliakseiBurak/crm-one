<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Pagination;
use App\Entity\Organization;
use App\Entity\OrganizationHide;
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
    use CanonicalListUrl;

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

        // Страница реестра — это организации, а не отдельные записи скрытия:
        // одну организацию можно скрыть от нескольких менеджеров, и по
        // записям страницы считались бы полупустыми (change
        // organizations-pagination). Сами записи скрытия организаций
        // страницы подтягиваются для отображения.
        $total = $this->hides->countRegistry();
        $pagination = new Pagination($total, Pagination::PER_PAGE, $request->query->get('page', 1));

        $redirect = $this->canonicalListRedirect(
            $request,
            $pagination,
            'app_organization_hide_list',
            [],
            ['sort', 'dir'],
        );
        if (null !== $redirect) {
            return $redirect;
        }

        $pageRows = $this->hides->findRegistryPage(
            $sort,
            $direction,
            $pagination->offset,
            Pagination::PER_PAGE,
        );

        $organizations = array_map(
            static fn(OrganizationHide $hide): Organization => $hide->organization,
            $pageRows,
        );

        $grouped = [];
        foreach ($organizations as $organization) {
            $grouped[(int) $organization->id]['organization'] = $organization;
            $grouped[(int) $organization->id]['hides'] = [];
        }
        foreach ($this->hides->findForOrganizations($organizations) as $hide) {
            $grouped[(int) $hide->organization->id]['hides'][] = $hide;
        }

        return $this->render('organization_hide/list.html.twig', [
            'grouped' => $grouped,
            'pagination' => $pagination,
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
