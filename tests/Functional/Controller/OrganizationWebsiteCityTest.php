<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * ADR-0015: необязательные поля сайта и города организации — в форме и в
 * quick-edit модалке, сайт колонкой таблицы панели, город только в раскрытой
 * строке.
 */
final class OrganizationWebsiteCityTest extends DatabaseWebTestCase
{
    public function testCreateFormSavesWebsiteAndCity(): void
    {
        $this->login($this->makeUser());

        $this->open('/organizations/new');
        $this->submitFormByButton('Создать', [
            'name' => 'ООО Ромашка',
            'website' => 'https://romashka.by',
            'city' => 'Минск',
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $organization = $this->em()->getRepository(Organization::class)->findOneBy(['name' => 'ООО Ромашка']);
        self::assertNotNull($organization);
        self::assertSame('https://romashka.by', $organization->website);
        self::assertSame('Минск', $organization->city);
    }

    public function testEmptyWebsiteAndCityStayNull(): void
    {
        $this->login($this->makeUser());

        $this->open('/organizations/new');
        $this->submitFormByButton('Создать', ['name' => 'ООО Ромашка']);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $organization = $this->em()->getRepository(Organization::class)->findOneBy(['name' => 'ООО Ромашка']);
        self::assertNotNull($organization);
        self::assertNull($organization->website);
        self::assertNull($organization->city);
    }

    public function testEditFormSavesWebsiteAndCity(): void
    {
        $this->login($this->makeUser());
        $organization = $this->makeOrganization('ООО Ромашка');

        $this->open('/organizations/' . $organization->id . '/edit');
        $this->submitFormByButton('Сохранить', [
            'name' => 'ООО Ромашка',
            'website' => 'https://romashka.by',
            'city' => 'Брест',
        ]);

        $this->em()->clear();
        $reloaded = $this->em()->find(Organization::class, $organization->id);
        self::assertInstanceOf(Organization::class, $reloaded);
        self::assertSame('https://romashka.by', $reloaded->website);
        self::assertSame('Брест', $reloaded->city);
    }

    public function testQuickEditModalSavesWebsiteAndCity(): void
    {
        $this->login($this->makeUser());
        $organization = $this->makeOrganization('ООО Ромашка');

        // Модалка быстрого редактирования живёт на панели.
        $crawler = $this->open('/dashboard');
        self::assertSame(1, $crawler->filter('#modal-organization-website')->count());
        self::assertSame(1, $crawler->filter('#modal-organization-city')->count());

        $this->submitOrganizationAjax(
            '/organizations/' . $organization->id . '/edit',
            '/organizations/' . $organization->id . '/edit',
            [
                'name' => 'ООО Ромашка',
                'website' => 'https://romashka.by',
                'city' => 'Гомель',
            ],
        );

        $this->em()->clear();
        $reloaded = $this->em()->find(Organization::class, $organization->id);
        self::assertInstanceOf(Organization::class, $reloaded);
        self::assertSame('https://romashka.by', $reloaded->website);
        self::assertSame('Гомель', $reloaded->city);
    }

    public function testCityAndWebsiteAreShownInTheExpandedRowAndHaveNoColumns(): void
    {
        $this->login($this->makeUser());
        $organization = $this->makeOrganization('ООО Ромашка', 'https://romashka.by', 'Минск');

        $crawler = $this->open('/dashboard?highlight=' . $organization->id);

        $this->assertResponseIsSuccessful();

        // Ни город, ни сайт колонкой в таблице не показываются (ADR-0015):
        // оба видны только в раскрытой строке.
        self::assertSame(0, $crawler->filterXPath('//table//th[normalize-space(.) = "Город"]')->count());
        self::assertSame(0, $crawler->filterXPath('//table//th[normalize-space(.) = "Сайт"]')->count());

        $city = $crawler->filterXPath('//span[@data-organization-cell = "city"]');
        self::assertSame(1, $city->count());
        self::assertSame('Минск', trim($city->text()));

        $website = $crawler->filterXPath('//span[@data-organization-cell = "website"]');
        self::assertSame(1, $website->count());
        self::assertSame('https://romashka.by', trim($website->text()));
    }

    public function testStoredWebsiteIsShownAsGivenInTheExpandedRow(): void
    {
        $this->login($this->makeUser());
        $organization = $this->makeOrganization('ООО Ромашка', 'https://romashka.by/contacts/');

        $crawler = $this->open('/dashboard?highlight=' . $organization->id);

        // Хранимое значение показывается как есть, без переписывания в домен.
        $website = $crawler->filterXPath('//span[@data-organization-cell = "website"]');
        self::assertSame(1, $website->count());
        self::assertSame('https://romashka.by/contacts/', trim($website->text()));

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Organization::class)->findOneBy(['name' => 'ООО Ромашка']);
        self::assertNotNull($reloaded);
        self::assertSame('https://romashka.by/contacts/', $reloaded->website);
    }

    public function testEmptyWebsiteRendersADashInTheExpandedRow(): void
    {
        $this->login($this->makeUser());
        $organization = $this->makeOrganization('ООО Без сайта');

        $crawler = $this->open('/dashboard?highlight=' . $organization->id);

        $website = $crawler->filterXPath('//span[@data-organization-cell = "website"]');
        self::assertSame(1, $website->count());
        self::assertSame('—', trim($website->text()));
    }

    public function testOnlyTheDeclaredColumnsAreSortable(): void
    {
        $this->login($this->makeUser());
        $this->makeOrganization('ООО Ромашка');

        $crawler = $this->open('/dashboard');
        $headers = $crawler->filterXPath('//table//th')->each(static fn($node): string => trim($node->text()));

        // Города, сайта и сферы деятельности в таблице нет, поэтому и
        // сортировки по ним не предлагается.
        self::assertNotContains('Город', $headers);
        self::assertNotContains('Сайт', $headers);
        self::assertNotContains('Сфера деятельности', $headers);
        self::assertSame(
            ['Название', 'Последний звонок', 'Следующий звонок', 'Активна', 'Дата отписки'],
            $headers,
        );
    }

    private function makeUser(): User
    {
        $user = (new User())
            ->setLogin('admin-site')
            ->setEmail('admin-site@b2b-crm.loc')
            ->setRole(UserRole::Admin);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeOrganization(string $name, ?string $website = null, ?string $city = null): Organization
    {
        $organization = (new Organization())->setName($name)->setWebsite($website)->setCity($city);
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
    }
}
