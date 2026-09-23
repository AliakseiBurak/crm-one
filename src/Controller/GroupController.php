<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Repository\OrganizationGroupRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/groups')]
class GroupController extends AbstractController
{
    public function __construct(
        private readonly OrganizationGroupRepository $groups,
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'app_group_list', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function list(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('User must be authenticated');
        }
        $isAdmin = UserRole::Admin === $user->role;

        $groups = $isAdmin
            ? $this->groups->findAllGroups()
            : $this->groups->findForManager($user);

        $sort = $request->query->get('sort', 'name');
        $direction = strtoupper($request->query->get('dir', 'ASC'));

        $groupsArray = $groups instanceof \Traversable ? iterator_to_array($groups) : $groups;
        usort(
            $groupsArray,
            static function (OrganizationGroup $a, OrganizationGroup $b) use ($sort, $direction): int {
                $cmp = match ($sort) {
                    'creator' => strcmp(
                        (string) ($a->createdBy?->email ?? ''),
                        (string) ($b->createdBy?->email ?? ''),
                    ),
                    default => strcmp((string) $a->name, (string) $b->name),
                };

                return 'DESC' === $direction ? -$cmp : $cmp;
            },
        );

        return $this->render('group/list.html.twig', [
            'groups' => $groupsArray,
            // null — доступна правка всех групп (админ, ADR-0008); иначе — id
            // групп, созданных менеджером (spec: organization-groups).
            'manageableIds' => $isAdmin
                ? null
                : array_map(
                    static fn(OrganizationGroup $g): int => (int) $g->id,
                    $this->groups->findCreatedBy($user),
                ),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    #[Route('/new', name: 'app_group_new', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(): Response
    {
        return $this->render('group/form.html.twig', [
            'group' => null,
            'errors' => [],
        ]);
    }

    #[Route('/new', name: 'app_group_create', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function create(Request $request): Response
    {
        $this->assertCsrfToken($request);

        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', '')) ?: null;
        $color = trim((string) $request->request->get('color', '')) ?: null;

        $group = new OrganizationGroup();
        $group->setName($name);
        $group->setDescription($description);
        $group->setColor($color);

        $errors = [];
        if ('' === $name) {
            $errors['name'] = 'Название обязательно';
        }
        foreach ($this->validator->validate($group) as $violation) {
            if ('color' === $violation->getPropertyPath()) {
                $errors['color'] = $violation->getMessage();
            }
        }

        if ([] !== $errors) {
            return $this->render('group/form.html.twig', [
                'group' => null,
                'errors' => $errors,
                'name' => $name,
                'description' => $description,
                'color' => $color,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $group->setCreatedBy($this->getUser());

        $this->em->persist($group);
        $this->em->flush();

        return $this->redirectToRoute('app_group_list');
    }

    #[Route('/{id}/edit', name: 'app_group_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(int $id): Response
    {
        $group = $this->editableGroup($id);

        return $this->render('group/form.html.twig', [
            'group' => $group,
            'errors' => [],
        ]);
    }

    #[Route('/{id}/edit', name: 'app_group_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function update(int $id, Request $request): Response
    {
        $group = $this->editableGroup($id);
        $this->assertCsrfToken($request);

        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', '')) ?: null;
        $color = trim((string) $request->request->get('color', '')) ?: null;

        $errors = [];
        if ('' === $name) {
            $errors['name'] = 'Название обязательно';
        }

        $group->setName($name);
        $group->setDescription($description);
        $group->setColor($color);

        foreach ($this->validator->validate($group) as $violation) {
            if ('color' === $violation->getPropertyPath()) {
                $errors['color'] = $violation->getMessage();
            }
        }

        if ([] !== $errors) {
            $this->em->clear(OrganizationGroup::class);

            return $this->render('group/form.html.twig', [
                'group' => $group,
                'errors' => $errors,
                'name' => $name,
                'description' => $description,
                'color' => $color,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->em->flush();

        return $this->redirectToRoute('app_group_list');
    }

    #[Route('/{id}/delete', name: 'app_group_delete', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(int $id): Response
    {
        $group = $this->editableGroup($id);

        return $this->render('group/delete.html.twig', [
            'group' => $group,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_group_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function remove(int $id, Request $request): Response
    {
        $group = $this->editableGroup($id);
        $this->assertCsrfToken($request);

        $this->em->remove($group);
        $this->em->flush();

        return $this->redirectToRoute('app_group_list');
    }

    #[Route('/{id}/members', name: 'app_group_members', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function members(int $id, Request $request): Response
    {
        $group = $this->viewableGroup($id);
        $canEdit = $this->canManageGroup($group);

        $sort = $request->query->get('sort', 'name');
        $dir = strtoupper($request->query->get('dir', 'ASC'));

        $memberIds = array_map(
            static fn(OrgGroupMembership $m): int => (int) $m->organization->id,
            $group->memberships->toArray()
        );

        if (!$canEdit) {
            // Назначенная группа доступна только на просмотр (spec:
            // organization-groups): состав показывается без формы изменения.
            // Скрытые организации не отображаются менеджеру (ADR-0012);
            // администратор видит всех участников.
            $members = array_map(
                static fn(OrgGroupMembership $m): Organization => $m->organization,
                $group->memberships->toArray(),
            );
            $accessibleIds = $this->organizations->findAccessibleIds($this->getUser());
            if (null !== $accessibleIds) {
                $members = array_values(array_filter(
                    $members,
                    static fn(Organization $o): bool => \in_array($o->id, $accessibleIds, true),
                ));
            }

            $members = $this->sortMembers($members, $sort, $dir);

            return $this->render('group/members.html.twig', [
                'group' => $group,
                'canEdit' => false,
                'organizations' => [],
                'members' => $members,
                'memberIds' => $memberIds,
                'sort' => $sort,
                'dir' => $dir,
            ]);
        }

        $organizations = $this->organizations->findAccessibleOrganizations($this->getUser());
        $organizations = $this->sortMembers($organizations, $sort, $dir);

        return $this->render('group/members.html.twig', [
            'group' => $group,
            'canEdit' => true,
            'organizations' => $organizations,
            'members' => [],
            'memberIds' => $memberIds,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    /**
     * @param Organization[] $members
     * @return Organization[]
     */
    private function sortMembers(array $members, string $sort, string $dir): array
    {
        $asc = strtoupper($dir) !== 'DESC';

        usort($members, static function (Organization $a, Organization $b) use ($sort, $asc): int {
            $cmp = match ($sort) {
                'industry' => strcmp((string) $a->industry, (string) $b->industry),
                'createdAt' => $a->createdAt <=> $b->createdAt,
                'creator' => strcmp(
                    self::creatorName($a),
                    self::creatorName($b),
                ),
                default => strcmp((string) $a->name, (string) $b->name),
            };

            return $asc ? $cmp : -$cmp;
        });

        return $members;
    }

    private static function creatorName(Organization $org): string
    {
        if (null === $org->createdBy) {
            return '';
        }

        return trim(($org->createdBy->name ?? '') . ' ' . ($org->createdBy->surname ?? ''));
    }

    #[Route('/{id}/members', name: 'app_group_update_members', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function updateMembers(int $id, Request $request): Response
    {
        $group = $this->editableGroup($id);
        $this->assertCsrfToken($request);

        $selectedIds = array_map('intval', $request->request->all('organizations'));

        // Менеджер может добавлять в группу только организации своей области
        // доступа (ADR-0012); администратору доступны все (ADR-0008).
        $accessibleIds = $this->organizations->findAccessibleIds($this->getUser());
        if (null !== $accessibleIds) {
            $selectedIds = array_values(array_intersect($selectedIds, $accessibleIds));
        }

        // Remove existing memberships not in selection,
        // but preserve memberships of organizations not in the current
        // user's accessible set (they may be hidden from this manager
        // but still belong to the group for other managers — ADR-0012).
        foreach ($group->memberships as $membership) {
            if (!\in_array($membership->organization->id, $selectedIds, true)) {
                if (null !== $accessibleIds && !\in_array($membership->organization->id, $accessibleIds, true)) {
                    continue; // preserve hidden org membership
                }
                $group->memberships->removeElement($membership);
                $this->em->remove($membership);
            }
        }

        // Add new memberships
        foreach ($selectedIds as $orgId) {
            $alreadyMember = false;
            foreach ($group->memberships as $existing) {
                if ($existing->organization->id === $orgId) {
                    $alreadyMember = true;
                    break;
                }
            }

            if (!$alreadyMember) {
                $organization = $this->organizations->find($orgId);
                if (null !== $organization) {
                    $membership = new OrgGroupMembership($organization, $group);
                    $this->em->persist($membership);
                }
            }
        }

        $this->em->flush();

        return $this->redirectToRoute('app_group_list');
    }

    #[Route('/{id}/assign', name: 'app_group_assign', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function assign(int $id): Response
    {
        $group = $this->findGroup($id);

        return $this->render('group/assign.html.twig', [
            'group' => $group,
            'managers' => $this->users->findManagers(),
            'assignedIds' => array_map(
                static fn(GroupAssignment $a): int => (int) $a->user->id,
                $group->assignments->toArray(),
            ),
        ]);
    }

    #[Route('/{id}/assign', name: 'app_group_update_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateAssign(int $id, Request $request): Response
    {
        $group = $this->findGroup($id);
        $this->assertCsrfToken($request);

        $selectedIds = array_map('intval', $request->request->all('managers'));

        // Назначать группу можно только менеджерам (ADR-0008): администратор
        // видит все группы без GroupAssignment, отправка id админа игнорируется.
        $managerIds = array_map(
            static fn(User $m): int => (int) $m->id,
            $this->users->findManagers(),
        );
        $selectedIds = array_values(array_intersect($selectedIds, $managerIds));

        // Remove existing assignments not in selection
        foreach ($group->assignments as $assignment) {
            if (!\in_array($assignment->user->id, $selectedIds, true)) {
                $group->assignments->removeElement($assignment);
                $this->em->remove($assignment);
            }
        }

        // Add new assignments
        $currentIds = array_map(
            static fn(GroupAssignment $a): int => (int) $a->user->id,
            $group->assignments->toArray(),
        );
        foreach ($selectedIds as $managerId) {
            if (!\in_array($managerId, $currentIds, true)) {
                $manager = $this->users->find($managerId);
                if (null !== $manager) {
                    $this->em->persist(new GroupAssignment($manager, $group));
                }
            }
        }

        $this->em->flush();

        return $this->redirectToRoute('app_group_list');
    }

    /**
     * Группа, доступная менеджеру хотя бы на просмотр: созданная им или
     * назначенная через GroupAssignment (ADR-0011); администратору доступны
     * все группы (ADR-0008).
     */
    private function viewableGroup(int $id): OrganizationGroup
    {
        $group = $this->findGroup($id);
        if ($this->canManageGroup($group)) {
            return $group;
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($this->groups->isAssignedTo($group, $user)) {
            return $group;
        }

        throw new AccessDeniedHttpException('Группа вне области доступа');
    }

    /**
     * Группа, доступная для изменения (правка, состав, удаление): только
     * создатель группы и администратор.
     */
    private function editableGroup(int $id): OrganizationGroup
    {
        $group = $this->findGroup($id);
        if (!$this->canManageGroup($group)) {
            throw new AccessDeniedHttpException('Группа вне области доступа');
        }

        return $group;
    }

    private function canManageGroup(OrganizationGroup $group): bool
    {
        $user = $this->getUser();

        return $user instanceof User
            && (UserRole::Admin === $user->role || $group->createdBy?->id === $user->id);
    }

    private function findGroup(int $id): OrganizationGroup
    {
        $group = $this->groups->find($id);
        if (null === $group) {
            throw $this->createNotFoundException('Группа не найдена');
        }

        return $group;
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('group', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
