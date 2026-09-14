<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\TrackingPixelController;
use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TrackingPixelControllerTest extends TestCase
{
    public function testMarksDeliveredRecipientOpened(): void
    {
        $recipient = $this->recipient();
        $recipient->markDelivered();

        $em = $this->entityManager($recipient);
        $em->expects(self::once())->method('flush');

        $response = new TrackingPixelController()('token-1', $em);

        self::assertSame(RecipientStatus::Opened, $recipient->status);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertNotSame('', $response->getContent());
    }

    public function testDoesNotOpenPendingRecipient(): void
    {
        $recipient = $this->recipient();
        $em = $this->entityManager($recipient);
        $em->expects(self::never())->method('flush');

        new TrackingPixelController()('token-1', $em);

        self::assertSame(RecipientStatus::Pending, $recipient->status);
    }

    public function testUnknownTokenStillReturnsPng(): void
    {
        $em = $this->entityManager(null);
        $em->expects(self::never())->method('flush');

        $response = new TrackingPixelController()('missing', $em);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
    }

    private function recipient(): CampaignRecipient
    {
        return new CampaignRecipient(
            new Campaign()->setName('Акция'),
            new Organization()->setName('ООО Ромашка'),
        );
    }

    private function entityManager(?CampaignRecipient $recipient): EntityManagerInterface&MockObject
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($recipient);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(CampaignRecipient::class)->willReturn($repo);

        return $em;
    }
}
