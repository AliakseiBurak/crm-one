<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateUserRequest;
use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
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

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OrganizationGroupRepository $groups,
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_user_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('user/list.html.twig', [
            'users' => $this->users->findAdminsAndManagers(),
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('user/form.html.twig', [
            'request' => new CreateUserRequest(),
            'errors' => [],
        ]);
    }

    #[Route('/new', name: 'app_user_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator): Response
    {
        $this->assertCsrfToken($request);

        $createRequest = new CreateUserRequest();
        $createRequest->login = trim((string) $request->request->get('login', ''));
        $createRequest->email = trim((string) $request->request->get('email', ''));
        $createRequest->name = trim((string) $request->request->get('name', '')) ?: null;
        $createRequest->surname = trim((string) $request->request->get('surname', '')) ?: null;
        $createRequest->role = (string) $request->request->get('role', '');

        $violations = $validator->validate($createRequest);
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= $violation->getMessage();
        }

        if ('' === $createRequest->role) {
            $errors['role'] ??= 'Роль обязательна для заполнения';
        }

        if ([] === $errors) {
            $existingLogin = $this->users->findOneBy(['login' => $createRequest->login]);
            if (null !== $existingLogin) {
                $errors['login'] = 'Пользователь с таким логином уже существует';
            }

            if ('' !== $createRequest->email) {
                $existing = $this->users->findOneBy(['email' => $createRequest->email]);
                if (null !== $existing) {
                    $errors['email'] = 'Пользователь с таким email уже существует';
                }
            }
        }

        if ([] !== $errors) {
            return $this->render('user/form.html.twig', [
                'request' => $createRequest,
                'errors' => $errors,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $role = UserRole::from($createRequest->role);

        $this->em->wrapInTransaction(function () use ($createRequest, $role): void {
            $email = '' === $createRequest->email ? null : $createRequest->email;
            $user = new User()
                ->setLogin($createRequest->login)
                ->setEmail($email)
                ->setRole($role);
            $user->setPassword(''); // Пароль не задаётся при создании

            if (null !== $createRequest->name) {
                $user->setName($createRequest->name);
            }
            if (null !== $createRequest->surname) {
                $user->setSurname($createRequest->surname);
            }

            $this->em->persist($user);
            $this->em->flush();
        });

        return $this->redirectToRoute('app_user_list');
    }

    #[Route('/{id}/delete', name: 'app_user_delete', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $user = $this->users->find($id);
        if (null === $user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        $createdGroups = $this->groups->findCreatedBy($user);
        $createdOrgs = $this->organizations->findBy(['createdBy' => $user]);

        return $this->render('user/delete.html.twig', [
            'user' => $user,
            'createdGroups' => $createdGroups,
            'createdOrgs' => $createdOrgs,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_user_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id, Request $request): Response
    {
        $this->assertCsrfToken($request);

        $user = $this->users->find($id);
        if (null === $user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->id === $user->id) {
            throw new AccessDeniedHttpException('Нельзя удалить самого себя');
        }

        $createdGroups = $this->groups->findCreatedBy($user);

        // Process per-group choices
        foreach ($createdGroups as $group) {
            $action = $request->request->get('group_action_' . $group->id);
            if ('reassign' === $action) {
                $group->setCreatedBy($currentUser);
            } elseif ('delete' === $action) {
                $this->em->remove($group);
            }
            // If no action selected, throw error
            if (null === $action) {
                throw new AccessDeniedHttpException('Необходимо выбрать действие для каждой группы');
            }
        }

        $this->em->flush();

        // Auto-reassign organizations created by deleted user to current admin
        // (change enhance-org-tables: spec user-delete).
        $createdOrgs = $this->organizations->findBy(['createdBy' => $user]);
        foreach ($createdOrgs as $org) {
            $org->setCreatedBy($currentUser);
        }

        // Теперь удаляем пользователя: группы уже обработаны выше (ADR-0011)
        $this->em->flush();
        $this->em->remove($user);
        $this->em->flush();

        return $this->redirectToRoute('app_user_list');
    }

    #[Route('/{id}/assign', name: 'app_user_assign', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function assign(int $id): Response
    {
        $user = $this->managerUser($id);

        return $this->render('user/assign.html.twig', [
            'user' => $user,
            'groups' => $this->groups->findAllGroups(),
            'assignedIds' => array_map(
                static fn(GroupAssignment $a): int => (int) $a->group->id,
                $user->groupAssignments->toArray(),
            ),
        ]);
    }

    #[Route('/{id}/assign', name: 'app_user_update_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateAssign(int $id, Request $request): Response
    {
        $user = $this->managerUser($id);
        $this->assertCsrfToken($request);

        $selectedIds = array_map('intval', $request->request->all('groups'));

        // Remove existing assignments not in selection
        foreach ($user->groupAssignments as $assignment) {
            if (!\in_array($assignment->group->id, $selectedIds, true)) {
                $user->groupAssignments->removeElement($assignment);
                $this->em->remove($assignment);
            }
        }

        // Add new assignments (несуществующие id групп игнорируются)
        $currentIds = array_map(
            static fn(GroupAssignment $a): int => (int) $a->group->id,
            $user->groupAssignments->toArray(),
        );
        foreach ($selectedIds as $groupId) {
            if (!\in_array($groupId, $currentIds, true)) {
                $group = $this->groups->find($groupId);
                if (null !== $group) {
                    $this->em->persist(new GroupAssignment($user, $group));
                }
            }
        }

        $this->em->flush();

        return $this->redirectToRoute('app_user_list');
    }

    /**
     * Пользователь, которому назначаются группы: только менеджеры (design D6).
     * Администратору доступны все группы без GroupAssignment (ADR-0008).
     */
    private function managerUser(int $id): User
    {
        $user = $this->users->find($id);
        if (null === $user || UserRole::Manager !== $user->role) {
            throw $this->createNotFoundException('Менеджер не найден');
        }

        return $user;
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('user', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
