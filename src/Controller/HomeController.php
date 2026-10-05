<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Pagination;
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
    use CanonicalListUrl;

    /**
     * Query-параметры списка панели. Всё, что не входит в список, в
     * канонический URL панели не попадает: чужой параметр (например
     * `filter` со статистики) переход по пагинации сохранять не должен.
     */
    private const DASHBOARD_LIST_PARAMS = ['q', 'sort', 'dir', 'inactive', 'optout', 'highlight'];

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
        // getUser() отдаёт UserInterface|null; область доступа считается по
        // App\Entity\User, поэтому тип сужается явно.
        $currentUser = $this->getUser();
        $user = $currentUser instanceof User ? $currentUser : null;
        $isAdmin = null !== $user && UserRole::Admin === $user->role;
        $adminUsers = $isAdmin ? $userRepository->findAdminsAndManagers() : [];

        $search = (string) $request->query->get('q', '');
        $sort = (string) $request->query->get('sort', '');
        $dir = (string) $request->query->get('dir', 'asc');
        $inactive = $request->query->getBoolean('inactive');
        $optout = $request->query->getBoolean('optout');
        $highlight = (int) $request->query->get('highlight', 0);

        // Один $now на весь запрос: иначе «следующий звонок» мог бы посчитать
        // разные строки при сортировке и при разрешении подсветки.
        $now = new \DateTimeImmutable();
        $isActive = $inactive ? false : null;
        $isOptedOut = $optout ? true : null;

        $total = $organizationRepository->countForDashboard($user, $search, $isActive, $isOptedOut);
        $requestedPage = $request->query->get('page', 1);
        $pagination = new Pagination($total, Pagination::PER_PAGE, $requestedPage);

        if ($highlight > 0) {
            $highlighted = $organizationRepository->find($highlight);
            if (null !== $highlighted) {
                $position = $organizationRepository->findDashboardPosition(
                    $user,
                    $highlighted,
                    $search,
                    $sort,
                    $dir,
                    $isActive,
                    $isOptedOut,
                    $now,
                );
                // Подсветка открывает страницу, содержащую организацию: если
                // такая страница одна и та же, номер в URL не меняется.
                if (null !== $position) {
                    $highlightPage = intdiv($position, Pagination::PER_PAGE) + 1;
                    if ($highlightPage !== $pagination->page) {
                        $pagination = new Pagination($total, Pagination::PER_PAGE, $highlightPage);
                    }
                }
            }
        }

        $redirect = $this->canonicalListRedirect($request, $pagination, 'app_dashboard', [], self::DASHBOARD_LIST_PARAMS);
        if (null !== $redirect) {
            return $redirect;
        }

        $organizationRows = $organizationRepository->findForDashboard(
            $user,
            $search,
            $sort,
            $dir,
            $isActive,
            $isOptedOut,
            $pagination->offset,
            Pagination::PER_PAGE,
            $now,
        );

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
            // Список элементов формы, не таблица: пагинировать его нельзя,
            // иначе контакт нельзя было бы создать для далёкой организации.
            'organizations' => $organizationRepository->findAccessibleOrganizations($user),
            'pagination' => $pagination,
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
            'inactive' => $inactive,
            'optout' => $optout,
            'highlight' => $highlight,
        ]);
    }
}
