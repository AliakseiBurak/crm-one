<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Call;
use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\CallRepository;
use App\Repository\ContactRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\CallResultService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(requirements: ['id' => '\d+'])]
class CallController extends AbstractController
{
    private const string RESEND_FLASH = 'Письмо будет отправлено повторно.';

    public function __construct(
        private readonly CallRepository $calls,
        private readonly ContactRepository $contacts,
        private readonly OrganizationRepository $organizations,
        private readonly UserRepository $users,
        private readonly CallResultService $callResults,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/calls/new', name: 'app_call_new', methods: ['GET'])]
    #[Route('/organizations/{organizationId}/calls/new', name: 'app_call_new_org', methods: ['GET'])]
    public function new(Request $request, ?int $organizationId = null): Response
    {
        $organizations = $this->organizations->findAccessibleOrganizations($this->getUser());
        $selectedOrganizationId = null;
        foreach ($organizations as $organization) {
            if ($organization->id === $organizationId) {
                $selectedOrganizationId = $organizationId;

                break;
            }
        }

        $isAdmin = $this->isAdmin();

        return $this->render('call/form.html.twig', $this->formContext(
            call: null,
            organizations: $organizations,
            selectedOrganizationId: $selectedOrganizationId,
            contacts: null === $selectedOrganizationId ? [] : $this->organizationContacts($selectedOrganizationId),
            errors: [],
            isAdmin: $isAdmin,
            users: $isAdmin ? $this->users->findAdminsAndManagers() : [],
            defaultScheduledAt: $this->defaultScheduledDate(),
        ));
    }

    #[Route('/calls/new', name: 'app_call_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator): Response
    {
        $this->assertCsrfToken($request);
        $ajax = $request->isXmlHttpRequest();

        $call = new Call();
        $requestedOrganizationId = (int) $request->request->get('organization', 0);
        if ($requestedOrganizationId > 0) {
            $call->setOrganization($this->accessibleOrganization($requestedOrganizationId));
        }

        $resultInput = $this->parseResultInput($request);
        $errors = $this->applyRequest($request, $validator, $call, $resultInput);
        if ([] !== $errors) {
            if ($ajax) {
                return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $isAdmin = $this->isAdmin();

            return $this->render('call/form.html.twig', $this->formContext(
                call: $call,
                organizations: $this->organizations->findAccessibleOrganizations($this->getUser()),
                selectedOrganizationId: $requestedOrganizationId > 0 ? $requestedOrganizationId : null,
                contacts: isset($call->organization) ? $this->organizationContacts($call->organization->id) : [],
                errors: $errors,
                isAdmin: $isAdmin,
                users: $isAdmin ? $this->users->findAdminsAndManagers() : [],
                resultInput: $resultInput,
            ), new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->em->persist($call);
        $this->em->flush();

        if ($ajax) {
            return $this->json(['ok' => true]);
        }

        return $this->redirectToRoute('app_dashboard', ['highlight' => $call->organization->id]);
    }

    #[Route('/calls/{id}/edit', name: 'app_call_edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $call = $this->accessibleCall($id);
        $isAdmin = $this->isAdmin();

        return $this->render('call/form.html.twig', $this->formContext(
            call: $call,
            organizations: [$call->organization],
            selectedOrganizationId: $call->organization->id,
            contacts: $this->organizationContacts($call->organization->id),
            errors: [],
            isAdmin: $isAdmin,
            users: $isAdmin ? $this->users->findAdminsAndManagers() : [],
        ));
    }

    #[Route('/calls/{id}/edit', name: 'app_call_update', methods: ['POST'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): Response
    {
        $call = $this->accessibleCall($id);
        $ajax = $request->isXmlHttpRequest();
        $this->assertCsrfToken($request);

        $resultInput = $this->parseResultInput($request);
        $errors = $this->applyRequest($request, $validator, $call, $resultInput);
        if ([] !== $errors) {
            if ($ajax) {
                return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $isAdmin = $this->isAdmin();

            return $this->render('call/form.html.twig', $this->formContext(
                call: $call,
                organizations: [$call->organization],
                selectedOrganizationId: $call->organization->id,
                contacts: $this->organizationContacts($call->organization->id),
                errors: $errors,
                isAdmin: $isAdmin,
                users: $isAdmin ? $this->users->findAdminsAndManagers() : [],
                resultInput: $resultInput,
            ), new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $hadNextCall = null !== $call->nextCall;
        // applyResultActions сам no-op без madeAt/madeBy (в т.ч. после режима «Будущий звонок»).
        $resendFlash = $this->applyResultActions($call, $resultInput);
        $this->em->flush();

        if ($resendFlash) {
            $this->addFlash('notice', self::RESEND_FLASH);
        }

        if ($ajax) {
            $this->em->refresh($call);
            if (null !== $call->nextCall) {
                $this->em->refresh($call->nextCall);
            }

            $payload = [
                'ok' => true,
                'row' => $this->renderView('call/_row.html.twig', [
                    'call' => $this->rowOf($call),
                    'callContact' => $call->contact,
                ]),
            ];

            // Новый следующий звонок — отдельная строка в «Все звонки» без перезагрузки.
            if (!$hadNextCall && null !== $call->nextCall) {
                $payload['nextCallRow'] = $this->renderView('call/_row.html.twig', [
                    'call' => $this->rowOf($call->nextCall),
                    'callContact' => $call->nextCall->contact,
                ]);
            }

            return $this->json($payload);
        }

        return $this->redirectToRoute('app_dashboard', ['highlight' => $call->organization->id]);
    }

    #[Route('/calls/{id}/delete', name: 'app_call_delete', methods: ['GET'])]
    public function delete(int $id): Response
    {
        $call = $this->accessibleCall($id);

        return $this->render('call/delete.html.twig', [
            'call' => $call,
        ]);
    }

    #[Route('/calls/{id}/delete', name: 'app_call_remove', methods: ['POST'])]
    public function remove(int $id, Request $request): Response
    {
        $call = $this->accessibleCall($id);
        $this->assertCsrfToken($request);

        $organizationId = $call->organization->id;
        $this->em->remove($call);
        $this->em->flush();

        return $this->redirectToRoute('app_dashboard', ['highlight' => $organizationId]);
    }

    #[Route('/organizations/{id}/contacts.json', name: 'app_call_organization_contacts', methods: ['GET'])]
    public function organizationContactsAction(int $id): Response
    {
        $this->accessibleOrganization($id);

        return $this->json([
            'ok' => true,
            'contacts' => array_map(
                static fn(Contact $contact): array => ['id' => $contact->id, 'name' => $contact->name],
                $this->organizationContacts($id)
            ),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function applyRequest(Request $request, ValidatorInterface $validator, Call $call, array $resultInput): array
    {
        $errors = [];
        // Уже проведённый звонок нельзя перевести в режим «Будущий звонок».
        $alreadyMade = null !== $call->madeAt;
        $isFutureCall = !$alreadyMade && null !== $request->request->get('is_future_call');

        $contactId = (int) $request->request->get('contact', 0);
        if ($contactId > 0) {
            $contact = $this->contacts->find($contactId);
            if (null === $contact) {
                throw $this->createNotFoundException('Контакт не найден');
            }
            if (isset($call->organization) && $contact->organization->id !== $call->organization->id) {
                $errors['contact'] = 'Контакт не принадлежит выбранной организации';
            } else {
                $call->setContact($contact);
            }
        } else {
            $call->setContact(null);
        }

        $call->setNotes($this->optionalField($request, 'notes'));

        if ($isFutureCall) {
            // Режим планирования: org/contact/notes + optional scheduled_at.
            // made_at, сделка, рассылка, следующий звонок — игнорируются.
            $scheduledAt = $this->parseDateTime((string) $request->request->get('scheduled_at', ''));
            if (false === $scheduledAt) {
                $errors['scheduledAt'] = 'Некорректный формат даты звонка';
            } else {
                $call->setScheduledAt($scheduledAt);
                if (null !== $scheduledAt && $scheduledAt < new \DateTimeImmutable('today')) {
                    $errors['scheduledAt'] = 'Запланированная дата звонка не может быть в прошлом';
                }
            }
            $call->setMadeAt(null);
            $call->setMadeBy(null);
            $call->setIsDeal(false);
            $call->setIsNoAnswer(false);
        } else {
            // Обычный режим: scheduled_at на сущности не трогаем (поле скрыто).
            $madeAtRaw = (string) $request->request->get('made_at', '');
            if ('' !== trim($madeAtRaw)) {
                $madeAt = $this->parseDateTime($madeAtRaw);
                if (false === $madeAt) {
                    $errors['madeAt'] = 'Некорректный формат даты звонка';
                } else {
                    $call->setMadeAt($madeAt);
                    if ($madeAt > new \DateTimeImmutable()) {
                        $errors['madeAt'] = 'Фактическая дата звонка не может быть в будущем';
                    } else {
                        $call->setMadeBy($this->resolveMadeBy($request));
                    }
                }
            } else {
                if ($alreadyMade) {
                    $errors['madeAt'] = 'Фактическую дату звонка нельзя удалить, только изменить';
                } else {
                    $call->setMadeAt(null);
                    $call->setMadeBy(null);
                }
            }

            $call->setIsDeal(null !== $request->request->get('is_deal'));
            $call->setIsNoAnswer(null !== $request->request->get('is_no_answer'));

            if ($this->hasResultActions($request, $resultInput)) {
                if (null === $call->madeAt || null === $call->madeBy) {
                    $errors['madeAt'] ??= 'Для действий результата звонка нужна фактическая дата';
                }
            }

            if (!isset($errors['madeAt']) && null !== $call->madeAt && null !== $call->madeBy) {
                $errors = array_merge($errors, $this->validateResultCommands($call, $resultInput));
            }
        }

        $violations = $validator->validate($call);
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * @param array{mailingCampaignId: ?int, mailingContactId: ?int, nextCallDate: string} $resultInput
     */
    private function applyResultActions(Call $call, array $resultInput): bool
    {
        $resendFlash = false;

        if (null === $call->madeAt || null === $call->madeBy) {
            return false;
        }

        $mailingCampaignId = $resultInput['mailingCampaignId'];

        if (null !== $mailingCampaignId) {
            $mailingCampaign = $this->callResults->findMailableCampaign($mailingCampaignId);
            if (null !== $mailingCampaign) {
                // Пустой контакт в форме = вся организация; предвыбор
                // контакта звонка делается только в разметке (defaultMailingContactId).
                $recipientContact = $this->resolveMailingContact(
                    $call->organization,
                    $resultInput['mailingContactId'],
                );
                if ($this->callResults->upsertRecipient($mailingCampaign, $call->organization, $recipientContact)) {
                    $resendFlash = true;
                }
                $call->setCampaign($mailingCampaign);
            }
        }

        if ('' !== $resultInput['nextCallDate'] && null === $call->nextCall) {
            $nextCallDate = $this->parseDate($resultInput['nextCallDate']);
            if (null !== $nextCallDate) {
                $this->callResults->createNextCall($call, $nextCallDate);
            }
        }

        return $resendFlash;
    }

    /**
     * @param array{mailingCampaignId: ?int, mailingContactId: ?int, nextCallDate: string} $resultInput
     *
     * @return array<string, string>
     */
    private function validateResultCommands(Call $call, array $resultInput): array
    {
        $errors = [];

        if ('' !== $resultInput['nextCallDate'] && null === $call->nextCall) {
            $nextCallDate = $this->parseDate($resultInput['nextCallDate']);
            if (null === $nextCallDate) {
                $errors['nextCallDate'] = 'Некорректный формат даты следующего звонка';
            } elseif ($nextCallDate <= new \DateTimeImmutable('today')) {
                $errors['nextCallDate'] = 'Дата следующего звонка должна быть в будущем';
            }
        }

        if (null !== $resultInput['mailingCampaignId']) {
            $campaign = $this->callResults->findMailableCampaign($resultInput['mailingCampaignId']);
            if (null === $campaign) {
                $errors['mailingCampaign'] = 'Выберите рассылку (архивные недоступны)';
            } elseif (null !== $resultInput['mailingContactId']) {
                $contact = $this->contacts->find($resultInput['mailingContactId']);
                if (null === $contact || $contact->organization->id !== $call->organization->id) {
                    $errors['mailingContact'] = 'Контакт адресата не принадлежит организации звонка';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array{mailingCampaignId: ?int, mailingContactId: ?int, nextCallDate: string} $resultInput
     */
    private function hasResultActions(Request $request, array $resultInput): bool
    {
        if ('' !== $resultInput['nextCallDate']) {
            return true;
        }
        if (null !== $resultInput['mailingCampaignId']) {
            return true;
        }
        if (null !== $request->request->get('is_deal') || null !== $request->request->get('is_no_answer')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{mailingCampaignId: ?int, mailingContactId: ?int, nextCallDate: string}
     */
    private function parseResultInput(Request $request): array
    {
        $mailingCampaignId = (int) $request->request->get('mailing_campaign', 0);

        return [
            'mailingCampaignId' => $mailingCampaignId > 0 ? $mailingCampaignId : null,
            'mailingContactId' => $this->optionalInt($request, 'mailing_contact'),
            'nextCallDate' => trim((string) $request->request->get('next_call_date', '')),
        ];
    }

    private function optionalInt(Request $request, string $field): ?int
    {
        $raw = $request->request->get($field);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function resolveMailingContact(Organization $organization, ?int $contactId): ?Contact
    {
        if (null === $contactId) {
            return null;
        }

        $contact = $this->contacts->find($contactId);
        if (null !== $contact && $contact->organization->id === $organization->id) {
            return $contact;
        }

        return null;
    }

    /**
     * @param Organization[] $organizations
     * @param Contact[]      $contacts
     * @param User[]         $users
     * @param array{mailingCampaignId: ?int, mailingContactId: ?int, nextCallDate: string}|null $resultInput
     *
     * @return array<string, mixed>
     */
    private function formContext(
        ?Call $call,
        array $organizations,
        ?int $selectedOrganizationId,
        array $contacts,
        array $errors,
        bool $isAdmin,
        array $users,
        ?array $resultInput = null,
        ?\DateTimeImmutable $defaultScheduledAt = null,
    ): array {
        $defaultMailingContact = $call?->contact?->id;
        if (null !== $resultInput && null !== $resultInput['mailingContactId']) {
            $defaultMailingContact = $resultInput['mailingContactId'];
        }

        return [
            'call' => $call,
            'defaultScheduledAt' => $defaultScheduledAt,
            'defaultMadeAt' => new \DateTimeImmutable(),
            'organizations' => $organizations,
            'selectedOrganizationId' => $selectedOrganizationId,
            'contacts' => $contacts,
            'errors' => $errors,
            'isAdmin' => $isAdmin,
            'users' => $users,
            'mailingCampaigns' => $this->callResults->findMailableCampaigns(),
            'resultInput' => $resultInput ?? [
                'mailingCampaignId' => null,
                'mailingContactId' => $defaultMailingContact,
                'nextCallDate' => '',
            ],
            'defaultMailingContactId' => $defaultMailingContact,
        ];
    }

    private function defaultScheduledDate(): \DateTimeImmutable
    {
        $date = new \DateTimeImmutable('+3 days');
        $weekday = (int) $date->format('w');
        if (0 === $weekday) {
            $date = $date->modify('+1 day');
        } elseif (6 === $weekday) {
            $date = $date->modify('+2 days');
        }

        return $date;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date) {
            $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        }

        return false === $date ? null : $date;
    }

    private function parseDateTime(string $value): \DateTimeImmutable|false|null
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $date) {
                return $date;
            }
        }

        return false;
    }

    private function optionalField(Request $request, string $field): ?string
    {
        $value = trim((string) $request->request->get($field, ''));

        return '' === $value ? null : $value;
    }

    private function accessibleCall(int $id): Call
    {
        $call = $this->calls->find($id);
        if (null === $call) {
            throw $this->createNotFoundException('Звонок не найден');
        }

        if (!$this->isOrganizationAccessible($call->organization)) {
            throw new AccessDeniedHttpException('Организация звонка вне области доступа');
        }

        return $call;
    }

    private function isAdmin(): bool
    {
        $user = $this->getUser();

        return $user instanceof User && UserRole::Admin === $user->role;
    }

    private function resolveMadeBy(Request $request): ?User
    {
        $current = $this->getUser();
        if (!$current instanceof User) {
            return null;
        }

        if (UserRole::Admin !== $current->role) {
            return $current;
        }

        $madeById = (int) $request->request->get('made_by', 0);
        if ($madeById > 0) {
            $chosen = $this->users->find($madeById);
            if ($chosen instanceof User
                && \in_array($chosen->role, [UserRole::Admin, UserRole::Manager], true)) {
                return $chosen;
            }
        }

        return $current;
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
     * @return Contact[]
     */
    private function organizationContacts(int $organizationId): array
    {
        return $this->contacts->findBy(['organization' => $organizationId], ['name' => 'ASC']);
    }

    /**
     * @return array{id: int, organizationId: int, contactId: int, date: ?\DateTimeImmutable,
     *     scheduledAt: ?\DateTimeImmutable, madeAt: ?\DateTimeImmutable, madeById: ?int,
     *     isDeal: bool, isNoAnswer: bool, campaignId: ?int, campaignName: ?string,
     *     nextCallId: ?int, nextCallScheduledAt: ?\DateTimeImmutable, notes: ?string}
     */
    private function rowOf(Call $call): array
    {
        return [
            'id' => $call->id,
            'organizationId' => $call->organization->id,
            'contactId' => $call->contact?->id ?? 0,
            'date' => $call->madeAt ?? $call->scheduledAt,
            'scheduledAt' => $call->scheduledAt,
            'madeAt' => $call->madeAt,
            'madeById' => $call->madeBy?->id,
            'isDeal' => $call->isDeal,
            'isNoAnswer' => $call->isNoAnswer,
            'campaignId' => $call->campaign?->id,
            'campaignName' => $call->campaign?->name,
            'nextCallId' => $call->nextCall?->id,
            'nextCallScheduledAt' => $call->nextCall?->scheduledAt,
            'notes' => $call->notes,
        ];
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('call', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
