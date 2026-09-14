<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CampaignRecipientRepository;
use App\Repository\CampaignRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON-endpoint'ы для polling'а прогресса рассылок: статистика всех рассылок
 * и статусы адресатов конкретной рассылки.
 */
class CampaignProgressController extends AbstractController
{
    public function __construct(
        private readonly CampaignRecipientRepository $campaignRecipients,
        private readonly CampaignRepository $campaigns,
    ) {}

    /**
     * Статистика всех рассылок: [{ campaignId, delivered, total }].
     */
    #[Route('/campaigns/statuses', name: 'app_campaign_statuses', methods: ['GET'])]
    public function statuses(): Response
    {
        $data = $this->campaignRecipients->statsForAllCampaigns();

        return $this->json($data, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Статусы адресатов рассылки: [{ recipientId, status }].
     * 404 для несуществующей рассылки.
     */
    #[Route('/campaigns/{id}/recipients/statuses', name: 'app_campaign_recipient_statuses', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function recipientStatuses(int $id): Response
    {
        $campaign = $this->campaigns->find($id);
        if (null === $campaign) {
            throw $this->createNotFoundException('Рассылка не найдена');
        }

        $data = $this->campaignRecipients->findStatusesForCampaign($id);

        return $this->json($data, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
