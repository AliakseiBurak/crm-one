<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class UnsubscribeControllerTest extends DatabaseWebTestCase
{
    public function testValidTokenSetsOptedOut(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $campaign = $this->makeCampaign('Тестовая рассылка');
        $recipient = new CampaignRecipient($campaign, $organization);
        $this->em()->persist($recipient);
        $this->em()->flush();

        $token = $recipient->trackingToken;
        self::assertNotNull($token);

        $this->client->request('GET', '/unsubscribe/' . $token);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вы отписались от рассылки');

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Отписка из письма', $org->optOutReason);
        self::assertNotNull($org->optedOutAt);
    }

    public function testInvalidTokenReturns404(): void
    {
        $this->client->request('GET', '/unsubscribe/invalid-token-12345');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAlreadyOptedOutShowsMessage(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $organization->setIsOptedOut(true);
        $organization->setOptOutReason('Ранее отписаны');
        $campaign = $this->makeCampaign('Тестовая рассылка');
        $recipient = new CampaignRecipient($campaign, $organization);
        $this->em()->persist($recipient);
        $this->em()->flush();

        $token = $recipient->trackingToken;
        self::assertNotNull($token);

        $this->client->request('GET', '/unsubscribe/' . $token);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вы уже отписались');

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Ранее отписаны', $org->optOutReason);
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = new User()
            ->setLogin($login)
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeOrganization(string $name): Organization
    {
        $organization = new Organization()->setName($name)->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
    }

    private function makeCampaign(string $name): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setSubject('Тема');
        $campaign->setBody('Текст письма');
        $this->em()->persist($campaign);
        $this->em()->flush();

        return $campaign;
    }
}
