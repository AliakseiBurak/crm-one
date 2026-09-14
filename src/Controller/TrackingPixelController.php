<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CampaignRecipient;
use App\Entity\Enum\RecipientStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TrackingPixelController extends AbstractController
{
    private const string PIXEL = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    #[Route('/t/{trackingToken}.png', name: 'app_tracking_pixel', methods: ['GET'])]
    public function __invoke(string $trackingToken, EntityManagerInterface $em): Response
    {
        $recipient = $em->getRepository(CampaignRecipient::class)->findOneBy([
            'trackingToken' => $trackingToken,
        ]);

        if (null !== $recipient && RecipientStatus::Delivered === $recipient->status) {
            $recipient->markOpened();
            $em->flush();
        }

        return new Response(self::PIXEL, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
