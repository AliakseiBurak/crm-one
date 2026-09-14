<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\Organization;
use App\Repository\CampaignRecipientRepository;
use App\Repository\ContactRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(requirements: ['id' => '\d+'])]
class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CampaignRecipientRepository $campaignRecipients,
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/contacts/new', name: 'app_contact_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        $organizations = $this->organizations->findAccessibleOrganizations($user);

        return $this->render('contact/form.html.twig', [
            'contact' => null,
            'organizations' => $organizations,
            'selectedOrganizationId' => $this->preselectedOrganizationId($request, $organizations),
            'errors' => [],
        ]);
    }

    #[Route('/contacts/new', name: 'app_contact_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator): Response
    {
        $this->assertCsrfToken($request);
        $ajax = $request->isXmlHttpRequest();

        $contact = new Contact();
        // Организация выбирается из области доступа (ADR-0007/0008). Пустой
        // ввод — ошибка валидации; несуществующий или невидимый идентификатор —
        // отказ (404/403).
        $requestedOrganizationId = (int) $request->request->get('organization', 0);
        if ($requestedOrganizationId > 0) {
            $contact->setOrganization($this->accessibleOrganization($requestedOrganizationId));
        }

        $errors = $this->applyRequest($request, $validator, $contact);
        if ([] !== $errors) {
            if ($ajax) {
                return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->render('contact/form.html.twig', [
                'contact' => $contact,
                'organizations' => $this->organizations->findAccessibleOrganizations($this->getUser()),
                'selectedOrganizationId' => $requestedOrganizationId > 0 ? $requestedOrganizationId : null,
                'errors' => $errors,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->em->persist($contact);
        $this->em->flush();

        if ($ajax) {
            return $this->json([
                'ok' => true,
                'organizationId' => $contact->organization->id,
            ]);
        }

        // Подсветка организации на панели: отдельной страницы контакта нет.
        return $this->redirectToRoute('app_dashboard', ['highlight' => $contact->organization->id]);
    }

    #[Route('/contacts/{id}/edit', name: 'app_contact_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $contact = $this->accessibleContact($id);

        return $this->render('contact/form.html.twig', [
            'contact' => $contact,
            'organizations' => [$contact->organization],
            'selectedOrganizationId' => $contact->organization->id,
            'errors' => [],
            'errorRecipients' => $this->campaignRecipients->findErrorRecipientsForContact($contact),
        ]);
    }

    #[Route('/contacts/{id}/edit', name: 'app_contact_update', methods: ['POST'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): Response
    {
        $contact = $this->accessibleContact($id);
        $ajax = $request->isXmlHttpRequest();

        // Токен приходит из формы (FormData) или заголовком X-CSRF-Token.
        $this->assertCsrfToken($request);

        $errors = $this->applyRequest($request, $validator, $contact);
        if ([] !== $errors) {
            if ($ajax) {
                return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->render('contact/form.html.twig', [
                'contact' => $contact,
                'organizations' => [$contact->organization],
                'selectedOrganizationId' => $contact->organization->id,
                'errors' => $errors,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // Сохранение изменений — updatedAt обновляется вручную.
        $contact->touch();
        $this->em->flush();

        if ($ajax) {
            return $this->json([
                'ok' => true,
                'card' => $this->renderView('contact/_card.html.twig', ['contact' => $contact]),
            ]);
        }

        return $this->redirectToRoute('app_dashboard', ['highlight' => $contact->organization->id]);
    }

    #[Route('/contacts/{id}/delete', name: 'app_contact_delete', methods: ['GET'])]
    public function delete(int $id): Response
    {
        $contact = $this->accessibleContact($id);

        return $this->render('contact/delete.html.twig', [
            'contact' => $contact,
        ]);
    }

    #[Route('/contacts/{id}/delete', name: 'app_contact_remove', methods: ['POST'])]
    public function remove(int $id, Request $request): Response
    {
        $contact = $this->accessibleContact($id);
        $this->assertCsrfToken($request);

        $this->em->remove($contact);
        $this->em->flush();

        return $this->redirectToRoute('app_dashboard', ['highlight' => $contact->organization->id]);
    }

    /**
     * Заполняет контакт данными формы и возвращает ошибки валидации,
     * сгруппированные по полям (name, phone, email, ...).
     *
     * @return array<string, string>
     */
    private function applyRequest(Request $request, ValidatorInterface $validator, Contact $contact): array
    {
        $contact->setName(trim((string) $request->request->get('name', '')));
        $contact->setPhone($this->optionalField($request, 'phone'));
        $contact->setEmail($this->optionalField($request, 'email'));
        $contact->setPosition($this->optionalField($request, 'position'));
        $contact->setNotes($this->optionalField($request, 'notes'));

        $violations = $validator->validate($contact);

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * Необязательное поле: пустая строка сохраняется как NULL.
     */
    private function optionalField(Request $request, string $field): ?string
    {
        $value = trim((string) $request->request->get($field, ''));

        return '' === $value ? null : $value;
    }

    /**
     * Контакт в области доступа пользователя: менеджеру — только контакты
     * организаций созданных и назначенных ему групп (ADR-0011), администратору —
     * все (ADR-0008, группы не проверяются).
     */
    private function accessibleContact(int $id): Contact
    {
        $contact = $this->contacts->find($id);
        if (null === $contact) {
            throw $this->createNotFoundException('Контакт не найден');
        }

        if (!$this->isOrganizationAccessible($contact->organization)) {
            throw new AccessDeniedHttpException('Организация контакта вне области доступа');
        }

        return $contact;
    }

    private function accessibleOrganization(int $id): Organization
    {
        $organization = $this->organizations->find($id);
        if (null === $organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        if (!$this->isOrganizationAccessible($organization)) {
            throw new AccessDeniedHttpException('Организация вне области доступа');
        }

        return $organization;
    }

    private function isOrganizationAccessible(Organization $organization): bool
    {
        $accessibleIds = $this->organizations->findAccessibleIds($this->getUser());

        return null === $accessibleIds || \in_array($organization->id, $accessibleIds, true);
    }

    /**
     * Предвыбор организации из параметра ссылки «Добавить контакт»:
     * только если организация есть в списке доступных.
     *
     * @param Organization[] $organizations
     */
    private function preselectedOrganizationId(Request $request, array $organizations): ?int
    {
        $requested = (int) $request->query->get('organization', 0);
        foreach ($organizations as $organization) {
            if ($organization->id === $requested) {
                return $requested;
            }
        }

        return null;
    }

    /**
     * Защита state-changing форм от CSRF; для AJAX-запросов токен передаётся
     * в заголовке X-CSRF-Token.
     */
    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('contact', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
