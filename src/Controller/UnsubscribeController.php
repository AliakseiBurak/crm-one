<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CampaignRecipient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UnsubscribeController extends AbstractController
{
    #[Route('/unsubscribe/{trackingToken}', name: 'app_unsubscribe', methods: ['GET'])]
    public function __invoke(string $trackingToken, EntityManagerInterface $em): Response
    {
        $recipient = $em->getRepository(CampaignRecipient::class)->findOneBy([
            'trackingToken' => $trackingToken,
        ]);

        if (null === $recipient) {
            throw $this->createNotFoundException('Ссылка устарела или недействительна');
        }

        $organization = $recipient->organization;

        if ($organization->isOptedOut) {
            return $this->render('unsubscribe/already.html.twig');
        }

        $organization->setIsOptedOut(true);
        $organization->setOptOutReason('Отписка из письма');
        $em->flush();

        return $this->render('unsubscribe/confirmed.html.twig');
    }
}
