<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class OrganizationOptOutController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/organizations/{id}/opt-out', name: 'app_organization_opt_out', methods: ['POST'])]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $organization = $this->organizations->find($id);
        if (null === $organization) {
            throw new NotFoundHttpException('Организация не найдена');
        }

        $currentUser = $this->getUser();
        $accessibleIds = $this->organizations->findAccessibleIds($currentUser instanceof User ? $currentUser : null);
        if (null !== $accessibleIds && !\in_array($id, $accessibleIds, true)) {
            throw new AccessDeniedHttpException('Организация вне области доступа');
        }

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? null;

        $organization->setIsOptedOut(true);
        if (null !== $reason && '' !== $reason) {
            $organization->setOptOutReason(trim((string) $reason));
        }

        $this->em->flush();

        return $this->json([
            'ok' => true,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'isOptedOut' => $organization->isOptedOut,
                'optOutReason' => $organization->optOutReason,
                'optedOutAt' => $organization->optedOutAt?->format('c'),
            ],
        ]);
    }
}
