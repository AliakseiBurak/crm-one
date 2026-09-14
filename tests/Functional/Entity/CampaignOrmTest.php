<?php

declare(strict_types=1);

namespace App\Tests\Functional\Entity;

use App\Entity\Campaign;
use App\Tests\DatabaseWebTestCase;

/**
 * Lazy-load Campaign after fail(): Doctrine ghosts reject explicit property hooks.
 */
final class CampaignOrmTest extends DatabaseWebTestCase
{
    public function testLazyCampaignWithFailureReasonCanBeInitialized(): void
    {
        $campaign = new Campaign()
            ->setName('Акция')
            ->setSubject('Тема')
            ->setBody('Текст');
        $campaign->fail('Проверьте MAILER_DSN.');
        $this->em()->persist($campaign);
        $this->em()->flush();
        $id = $campaign->id;
        $this->em()->clear();

        $ref = $this->em()->getReference(Campaign::class, $id);

        self::assertSame('Проверьте MAILER_DSN.', $ref->failureReason);
        self::assertSame('Акция', $ref->name);
    }
}
