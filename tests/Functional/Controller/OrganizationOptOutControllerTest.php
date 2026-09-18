<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Call;
use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class OrganizationOptOutControllerTest extends DatabaseWebTestCase
{
    public function testAdminOptOutOrganization(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['reason' => 'Не заинтересованы']),
        );

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertTrue($payload['organization']['isOptedOut']);
        self::assertSame('Не заинтересованы', $payload['organization']['optOutReason']);
        self::assertNotNull($payload['organization']['optedOutAt']);

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Не заинтересованы', $org->optOutReason);
        self::assertNotNull($org->optedOutAt);
    }

    public function testOptOutWithoutReason(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([]),
        );

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertTrue($payload['organization']['isOptedOut']);
        self::assertNull($payload['organization']['optOutReason']);
    }

    public function testManagerCanOptOutAccessibleOrganization(): void
    {
        [$manager, $organization] = $this->makeManagerWithOrganization('manager@b2b-crm.loc');
        $this->login($manager);

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['reason' => 'Отписка']),
        );

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertTrue($payload['organization']['isOptedOut']);
    }

    public function testManagerCannotOptOutInaccessibleOrganization(): void
    {
        [$manager, $organization] = $this->makeManagerWithOrganization('manager@b2b-crm.loc');
        // Hide the organization from the manager to make it inaccessible
        $hide = new OrganizationHide($organization, $manager);
        $this->em()->persist($hide);
        $this->em()->flush();
        $this->em()->clear();

        $this->login($manager);

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([]),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOptOutNonexistentOrganization(): void
    {
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request(
            'POST',
            '/organizations/999/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([]),
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOptOutIsIdempotent(): void
    {
        $organization = $this->makeOrganization('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['reason' => 'Первая причина']),
        );
        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/organizations/' . $organization->id . '/opt-out',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['reason' => 'Вторая причина']),
        );
        $this->assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Вторая причина', $payload['organization']['optOutReason']);
    }

    public function testCallWithRefusalMarksOrganizationOptedOut(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '17.09.2026 10:00',
            'is_refusal' => '1',
            'refusal_mark_opt_out' => '1',
            'refusal_opt_out_reason' => 'Отказ от сотрудничества',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Отказ от сотрудничества', $org->optOutReason);
        self::assertNotNull($org->optedOutAt);
    }

    public function testCallWithRefusalMarkInactiveOnly(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '17.09.2026 10:00',
            'is_refusal' => '1',
            'refusal_mark_inactive' => '1',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertFalse($org->isActive);
        self::assertFalse($org->isOptedOut);
    }

    public function testCallWithRefusalBothCheckboxes(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '17.09.2026 10:00',
            'is_refusal' => '1',
            'refusal_mark_inactive' => '1',
            'refusal_mark_opt_out' => '1',
            'refusal_opt_out_reason' => 'Закрылись',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertFalse($org->isActive);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Закрылись', $org->optOutReason);
    }

    public function testCallWithRefusalWithoutMadeAtDoesNotOptOut(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '',
            'is_refusal' => '1',
            'refusal_mark_opt_out' => '1',
        ]);

        // Validation requires madeAt for result actions — form returns 422
        $this->assertResponseStatusCodeSame(422);

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertFalse($org->isOptedOut);
    }

    public function testCallWithRefusalOnlyRecordsCallWithoutOrganizationChanges(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '17.09.2026 10:00',
            'is_refusal' => '1',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isActive);
        self::assertFalse($org->isOptedOut);
        self::assertNull($org->optOutReason);
        self::assertNull($org->optedOutAt);

        $call = $this->em()->getRepository(Call::class)->findOneBy(['organization' => $organization->id]);
        self::assertNotNull($call);
        self::assertTrue($call->isRefusal);
    }

    public function testCallWithRefusalOptOutAndMailingTogether(): void
    {
        [$organization, $contact] = $this->makeOrganizationWithContact('ООО Ромашка');
        $contact->setEmail('info@romashka.ru');
        $this->em()->flush();

        $campaign = new Campaign()
            ->setName('Осенняя рассылка')
            ->setSubject('Осенние курсы')
            ->setBody('{{greeting}}');
        $campaign->setStatus(CampaignStatus::Ready);
        $this->em()->persist($campaign);
        $this->em()->flush();

        $this->login($this->makeUser('admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/organizations/' . $organization->id . '/calls/new');
        $this->submitFormByButton('Создать', [
            'organization' => (string) $organization->id,
            'contact' => (string) $contact->id,
            'made_at' => '17.09.2026 10:00',
            'is_refusal' => '1',
            'refusal_mark_opt_out' => '1',
            'refusal_opt_out_reason' => 'Не заинтересованы',
            'mailing_campaign' => (string) $campaign->id,
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $org = $this->em()->getRepository(Organization::class)->find($organization->id);
        self::assertTrue($org->isOptedOut);
        self::assertSame('Не заинтересованы', $org->optOutReason);

        $recipient = $this->em()->getRepository(CampaignRecipient::class)->findOneBy([
            'campaign' => $campaign->id,
            'organization' => $organization->id,
        ]);
        self::assertNotNull($recipient);

        $call = $this->em()->getRepository(Call::class)->findOneBy(['organization' => $organization->id]);
        self::assertNotNull($call);
        self::assertTrue($call->isRefusal);
        self::assertNotNull($call->campaign);
        self::assertSame($campaign->id, $call->campaign->id);
    }

    private function makeUser(string $email, UserRole $role): User
    {
        $user = new User()
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

    /**
     * @return array{0: Organization, 1: Contact}
     */
    private function makeOrganizationWithContact(string $name): array
    {
        $organization = $this->makeOrganization($name);
        $contact = new \App\Entity\Contact()
            ->setOrganization($organization)
            ->setName('Иван Петров');
        $this->em()->persist($contact);
        $this->em()->flush();

        return [$organization, $contact];
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function makeManagerWithOrganization(string $email): array
    {
        $manager = $this->makeUser($email, UserRole::Manager);
        $group = new OrganizationGroup()
            ->setName('Группа ' . $email)
            ->setCreatedBy($manager);
        $this->em()->persist($group);

        $organization = new Organization()->setName('ООО Ромашка')->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->persist(new OrgGroupMembership($organization, $group));
        $this->em()->flush();

        return [$manager, $organization];
    }
}
