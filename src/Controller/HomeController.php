<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\CallRepository;
use App\Repository\CampaignRecipientRepository;
use App\Repository\ContactRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\CallResultService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        CallRepository $callRepository,
        OrganizationRepository $organizationRepository,
    ): Response {
        $user = $this->getUser();
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        $now = new \DateTimeImmutable();
        $organizationIds = $organizationRepository->findAccessibleIds($user);
        // Y — всего организаций области доступа; администратор видит все
        // организации системы (ADR-0008).
        $totalOrgs = null !== $organizationIds
            ? \count($organizationIds)
            : $organizationRepository->count([]);

        return $this->render('home/index.html.twig', [
            'stats' => $callRepository->dashboardStats($organizationIds, $now),
            'statsByOrg' => $callRepository->organizationCounts($organizationIds, $now),
            'totalOrgs' => $totalOrgs,
            'optOutStats' => $organizationRepository->optOutStats($organizationIds, $now),
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(
        Request $request,
        CallRepository $callRepository,
        OrganizationRepository $organizationRepository,
        ContactRepository $contactRepository,
        CampaignRecipientRepository $campaignRecipients,
        UserRepository $userRepository,
        CallResultService $callResults,
    ): Response {
        $user = $this->getUser();
        $organizationIds = $organizationRepository->findAccessibleIds($user);
        $isAdmin = $user instanceof User && UserRole::Admin === $user->role;
        $adminUsers = $isAdmin ? $userRepository->findAdminsAndManagers() : [];

        $search = (string) $request->query->get('q', '');
        $sort = (string) $request->query->get('sort', '');
        $dir = (string) $request->query->get('dir', 'asc');
        $filter = (string) $request->query->get('filter', '');
        $highlight = (int) $request->query->get('highlight', 0);

        $organizationRows = $organizationRepository->findForDashboard($user, $search, $sort, $dir);

        $ids = array_map(static fn(\App\Dto\DashboardOrganizationRow $row): int => (int) $row->organization->id, $organizationRows);

        $contacts = $contactRepository->findByOrganizations($ids);
        $contactsByOrganization = [];
        $contactById = [];
        foreach ($contacts as $contact) {
            $contactsByOrganization[(int) $contact->organization->id][] = $contact;
            $contactById[(int) $contact->id] = $contact;
        }

        // Эффективный главный контакт каждой организации (isMain; при его
        // отсутствии или нескольких — минимальный ID): подсветка карточки,
        // порядок контактов не меняется (по ID).
        $effectiveMainByOrganization = [];
        foreach ($contactsByOrganization as $organizationId => $organizationContacts) {
            $main = $contactRepository->findEffectiveMainAmong($organizationContacts);
            if (null !== $main && null !== $main->id) {
                $effectiveMainByOrganization[$organizationId] = (int) $main->id;
            }
        }

        // Отметка bounced для карточек контактов на дашборде.
        $bouncedContactIds = [];
        foreach ($contacts as $contact) {
            if ($campaignRecipients->hasBouncedForContact($contact)) {
                $bouncedContactIds[(int) $contact->id] = true;
            }
        }

        return $this->render('home/dashboard.html.twig', [
            'organizationRows' => $organizationRows,
            'organizations' => $organizationRepository->findAccessibleOrganizations($user),
            'contactsByOrganization' => $contactsByOrganization,
            'effectiveMainByOrganization' => $effectiveMainByOrganization,
            'contactById' => $contactById,
            'bouncedContactIds' => $bouncedContactIds,
            'callsByOrganization' => $callRepository->findAllCallsByOrganizations($ids),
            'mailingCampaigns' => $callResults->findMailableCampaigns(),
            'isAdmin' => $isAdmin,
            'users' => $adminUsers,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'filter' => $filter,
            'highlight' => $highlight,
        ]);
    }
}
