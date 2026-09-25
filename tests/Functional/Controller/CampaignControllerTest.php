<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignAttachment;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Service\CampaignAttachmentStorage;
use App\Service\CampaignEmailRenderer;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Функциональные тесты CampaignController (change campaign-entity):
 * создание/редактирование администратором и менеджерами, валидация,
 * ручной запуск/остановка/сброс, вложения (upload/remove с файлами в
 * storage), клонирование, ручные адресаты.
 */
final class CampaignControllerTest extends DatabaseWebTestCase
{
    public function testAdminCreatesCampaignWithDefaults(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => 'Новые курсы',
            'subject' => 'Приглашаем на курсы 2026',
            'body' => '{{greeting}}! Приглашаем вас на курсы.',
            'status' => 'draft',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $campaign = $this->findCampaign('Новые курсы');
        self::assertNotNull($campaign);
        self::assertSame('Приглашаем на курсы 2026', $campaign->subject);
        self::assertSame(CampaignStatus::Draft, $campaign->status);
        self::assertNull($campaign->launchedAt);
    }

    public function testManagerCanCreateCampaign(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => 'Приглашение на вебинар',
            'subject' => 'Вебинар по аналитике',
            'body' => 'Добрый день!',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertNotNull($this->findCampaign('Приглашение на вебинар'));
    }

    public function testCreateWithBlankRequiredFieldsShowsRussianErrors(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => '',
            'subject' => '',
            'body' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Название обязательно для заполнения', $html);
        self::assertStringContainsString('Тема письма обязательна для заполнения', $html);
        self::assertStringContainsString('Текст письма обязателен для заполнения', $html);

        self::assertSame(0, $this->em()->getRepository(Campaign::class)->count([]));
    }

    public function testCreateSanitizesHtmlBody(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => 'HTML-рассылка',
            'subject' => 'Тема',
            'body' => '<p onclick="alert(1)">Текст<script>alert(1)</script></p>'
                . '<table><tbody><tr><td colspan="2">Ячейка</td></tr></tbody></table>'
                . '<img src="https://cdn.example/logo.png" alt="Логотип">',
        ]);

        $this->assertResponseRedirects();

        $this->em()->clear();
        $campaign = $this->findCampaign('HTML-рассылка');
        self::assertNotNull($campaign);
        self::assertStringContainsString('<p>Текст</p>', $campaign->body);
        self::assertStringContainsString('colspan="2"', $campaign->body);
        self::assertStringContainsString('src="https://cdn.example/logo.png"', $campaign->body);
        self::assertStringNotContainsString('script', $campaign->body);
        self::assertStringNotContainsString('onclick', $campaign->body);
    }

    public function testCreateRejectsBodyLongerThanLimit(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => 'Слишком длинная',
            'subject' => 'Тема',
            'body' => '<p>' . str_repeat('а', 200001) . '</p>',
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Текст письма не должен превышать 200 000 символов',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertSame(0, $this->em()->getRepository(Campaign::class)->count([]));
    }

    public function testCreateRejectsBodyWithoutAllowedContent(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $this->open('/campaigns/new');
        $this->submitFormByButton('Создать', [
            'name' => 'Только скрипт',
            'subject' => 'Тема',
            'body' => '<script>alert(1)</script>',
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Тело письма не содержит допустимого содержимого',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertSame(0, $this->em()->getRepository(Campaign::class)->count([]));
    }

    public function testPreviewPageShowsEmailWithDemoTokens(): void
    {
        $campaign = $this->persistCampaign('Предпросмотр');
        $campaign->setBody('<p>{{greeting}}</p><p>{{organization_name}}</p>');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('GET', '/campaigns/' . $campaign->id . '/preview');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('width="600"', $html);
        self::assertStringContainsString(CampaignEmailRenderer::DEMO_ORGANIZATION_NAME, $html);
        self::assertStringContainsString(CampaignEmailRenderer::DEMO_CONTACT_NAME, $html);
        self::assertStringNotContainsString('/t/', $html);
    }

    public function testManagerCanOpenPreviewPage(): void
    {
        $campaign = $this->persistCampaign('Предпросмотр менеджера');
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));

        $this->client->request('GET', '/campaigns/' . $campaign->id . '/preview');

        $this->assertResponseIsSuccessful();
    }

    public function testPreviewPageForUnknownCampaignReturns404(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('GET', '/campaigns/999999/preview');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLivePreviewRendersUnsavedBodyWithoutSaving(): void
    {
        $campaign = $this->persistCampaign('Живое превью');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $token = (string) $this->open('/campaigns/' . $campaign->id . '/edit')
            ->filter('input[name="_csrf_preview"]')->attr('value');

        $this->client->request('POST', '/campaigns/preview', [
            'subject' => 'Тема',
            'body' => '<p>{{greeting}}</p>',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString(
            'Уважаемый(ая) ' . CampaignEmailRenderer::DEMO_CONTACT_NAME,
            (string) $payload['html'],
        );

        $this->em()->clear();
        self::assertSame('{{greeting}}!', $this->findCampaign('Живое превью')?->body);
    }

    public function testLivePreviewSanitizesBody(): void
    {
        $campaign = $this->persistCampaign('Санитизация превью');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $token = (string) $this->open('/campaigns/' . $campaign->id . '/edit')
            ->filter('input[name="_csrf_preview"]')->attr('value');

        $this->client->request('POST', '/campaigns/preview', [
            'body' => '<p>Текст<script>alert(1)</script></p>',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('<p>Текст</p>', (string) $payload['html']);
        self::assertStringNotContainsString('<script>', (string) $payload['html']);
    }

    public function testLivePreviewRejectsInvalidCsrf(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/preview', ['body' => '<p>x</p>']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLivePreviewRejectsTooLongBody(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $token = (string) $this->open('/campaigns/new')
            ->filter('input[name="_csrf_preview"]')->attr('value');

        $this->client->request('POST', '/campaigns/preview', [
            'body' => '<p>' . str_repeat('а', 200001) . '</p>',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testFormPageContainsEditorScaffolding(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $page = $this->open('/campaigns/new');

        self::assertGreaterThan(0, $page->filter('[data-campaign-editor]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-toolbar] [data-editor-command="bold"]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-toolbar] [data-editor-command="strike"]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-toolbar] [data-editor-command="heading"]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-toggle-source]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-image-form]')->count());
        self::assertGreaterThan(0, $page->filter('[data-editor-image-form] input[data-editor-image-url]')->count());
        self::assertGreaterThan(0, $page->filter('textarea[name="body"]')->count());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('{{unsubscribe_url}}', $html);
        self::assertGreaterThan(0, $page->filter('[data-campaign-preview-open]')->count());
        self::assertGreaterThan(0, $page->filter('[data-campaign-preview-modal] .modal[data-modal]')->count());
    }

    public function testShowPageRendersFormattedHtmlBody(): void
    {
        $campaign = $this->persistCampaign('Форматирование');
        $campaign->setBody('<p>Привет <strong>мир</strong></p>');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('GET', '/campaigns/' . $campaign->id);

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<p>Привет <strong>мир</strong></p>', $html);
        self::assertStringNotContainsString('<pre class="campaign-card__template">', $html);
    }

    public function testCreateWithFailedStatusShowsError(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
        $token = (string) $this->open('/campaigns/new')
            ->filter('input[name="_csrf_token"]')
            ->attr('value');
        $this->client->request('POST', '/campaigns/new', [
            'name' => 'Тест',
            'subject' => 'Тема',
            'body' => 'Текст',
            'status' => 'failed',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Статус «Ошибка» устанавливается автоматически сервисом отправки', $html);

        self::assertSame(0, $this->em()->getRepository(Campaign::class)->count([]));
    }

    public function testIndexListsCampaigns(): void
    {
        $this->persistCampaign('Новые курсы');
        $this->persistCampaign('Акция');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/campaigns');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Рассылки');
        $this->assertSelectorTextContains('body', 'Новые курсы');
        $this->assertSelectorTextContains('body', 'Акция');
    }

    public function testIndexStatisticsCountDeliveredAndOpenedRecipients(): void
    {
        $campaign = $this->persistCampaign('Статистика');
        for ($i = 1; $i <= 10; ++$i) {
            $recipient = new CampaignRecipient(
                $campaign,
                $this->persistOrganization('Организация ' . $i),
            );
            if ($i <= 6) {
                $recipient->markDelivered();
            } elseif (7 === $i) {
                $recipient->markDelivered()->markOpened();
            }
            $this->em()->persist($recipient);
        }
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns');

        $this->assertResponseIsSuccessful();
        $headers = $crawler->filter('thead th')->each(
            static fn(Crawler $header): string => trim($header->text()),
        );
        self::assertContains('Статистика', $headers);
        self::assertStringContainsString('7 из 10', $crawler->filter('tbody tr')->first()->text());
    }

    public function testShowDisplaysCampaignFields(): void
    {
        $campaign = $this->persistCampaign('Новые курсы');
        $campaign->setStatus(CampaignStatus::Ready);
        $attachment = new CampaignAttachment($campaign, 'брошюра.pdf', bin2hex(random_bytes(16)));
        $attachment->setSize(2048);
        $this->em()->persist($attachment);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns/' . $campaign->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Рассылка «Новые курсы»');
        $this->assertSelectorTextContains('body', 'Приглашаем на курсы');
        $this->assertSelectorTextContains('body', 'брошюра.pdf');
        // Кнопка «Запустить» видна для статуса ready.
        $this->assertSelectorExists('form[action="' . $this->launchPath($campaign->id) . '"]');
    }

    public function testShowDisplaysProcessedRecipientCounter(): void
    {
        $campaign = $this->persistCampaign('Рассылка в процессе');
        $campaign->launch();

        $delivered = new CampaignRecipient($campaign, $this->persistOrganization('Доставлено'));
        $delivered->markDelivered();
        $bounced = new CampaignRecipient($campaign, $this->persistOrganization('Отказ'));
        $bounced->markBounced('550');
        $failed = new CampaignRecipient($campaign, $this->persistOrganization('Ошибка'));
        $failed->markFailed('Нет адреса', false);
        $pending = new CampaignRecipient($campaign, $this->persistOrganization('Ожидает'));
        foreach ([$delivered, $bounced, $failed, $pending] as $recipient) {
            $this->em()->persist($recipient);
        }
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/campaigns/' . $campaign->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#campaign-progress', 'обработано 3 из 4');
    }

    public function testLaunchSetsLaunchedAtAndStatusOnce(): void
    {
        $campaign = $this->persistCampaign('Новые курсы');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', $this->launchPath($campaign->id), ['_csrf_token' => $token]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $launched = $this->findCampaign('Новые курсы');
        self::assertNotNull($launched->launchedAt);
        self::assertSame(CampaignStatus::Launched, $launched->status);
        $firstLaunch = $launched->launchedAt;

        // Повторный запуск не перезаписывает момент запуска.
        $this->client->request('POST', $this->launchPath($campaign->id), ['_csrf_token' => $token]);
        $this->em()->clear();
        self::assertSame($firstLaunch->format(\DateTimeInterface::ATOM), $this->findCampaign('Новые курсы')->launchedAt->format(\DateTimeInterface::ATOM));
    }

    public function testStopSetsReadyStatus(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $campaign->launch();
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/stop', ['_csrf_token' => $token]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $stopped = $this->findCampaign('Акция');
        self::assertSame(CampaignStatus::Ready, $stopped->status);
    }

    public function testResetSetsReadyStatus(): void
    {
        $campaign = $this->persistCampaign('Рассылка с ошибкой');
        $campaign->fail('Проверьте MAILER_DSN.');
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/reset', ['_csrf_token' => $token]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $reset = $this->findCampaign('Рассылка с ошибкой');
        self::assertSame(CampaignStatus::Ready, $reset->status);
        self::assertNull($reset->failureReason);
    }

    public function testDeleteRemovesCampaignAndAttachments(): void
    {
        $storage = $this->storage();
        $campaign = $this->persistCampaign('Акция');
        $storageKey = bin2hex(random_bytes(16));
        // Write test file directly into storage to avoid UploadedFile test-mode issues.
        $dir = \dirname($storage->path($storageKey));
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
        file_put_contents($storage->path($storageKey), 'PDF-DATA');
        $attachment = new CampaignAttachment($campaign, 'брошюра.pdf', $storageKey);
        $this->em()->persist($attachment);
        $this->em()->flush();
        self::assertFileExists($storage->path($storageKey));
        $campaignId = $campaign->id;
        $attachmentId = $attachment->id;
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/delete', ['_csrf_token' => $token]);

        $this->assertResponseRedirects('/campaigns');

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Campaign::class, $campaignId));
        self::assertNull($em->find(CampaignAttachment::class, $attachmentId));
        self::assertFileDoesNotExist($storage->path($storageKey));
    }

    public function testAttachmentUploadStoresFileAndMetadata(): void
    {
        $storage = $this->storage();
        $campaign = $this->persistCampaign('Акция');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->attachmentCsrfToken($campaign->id);
        $tmp = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($tmp, '%PDF-1.4 attachment body');
        $upload = new UploadedFile($tmp, 'брошюра.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
        $this->client->request(
            'POST',
            '/campaigns/' . $campaign->id . '/attachments',
            ['_csrf_token' => $token],
            ['attachment' => $upload],
        );

        $this->assertResponseRedirects();

        $this->em()->clear();
        $campaign = $this->findCampaign('Акция');
        self::assertCount(1, $campaign->attachments);
        $attachment = $campaign->attachments->first();
        self::assertSame('брошюра.pdf', $attachment->filename);
        self::assertFileExists($storage->path($attachment->storageKey));

        // Удаление вложения: токен берётся из data-csrf кнопки «Удалить».
        $attachmentToken = $this->attachmentCsrfToken($campaign->id);
        $this->client->request(
            'POST',
            '/campaigns/' . $campaign->id . '/attachments/' . $attachment->id . '/delete',
            ['_csrf_token' => $attachmentToken],
        );
        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(CampaignAttachment::class, $attachment->id));
        self::assertFileDoesNotExist($storage->path($attachment->storageKey));
    }

    public function testEditShowsAttachmentsWithDeleteActions(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $attachment = new CampaignAttachment($campaign, 'прайс.xlsx', bin2hex(random_bytes(16)));
        $this->em()->persist($attachment);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns/' . $campaign->id . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'прайс.xlsx');
        $this->assertSelectorExists('input[type="file"][name="attachments[]"]');
    }

    public function testGuestCannotAccessCampaignPages(): void
    {
        $this->client->request('GET', '/campaigns');

        $this->assertResponseRedirects('/login');
    }

    // --- CSRF rejection tests ---

    public function testCreateCampaignRejectsInvalidCsrfToken(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/new', [
            '_csrf_token' => 'invalid',
            'name' => 'Рассылка',
            'subject' => 'Тема',
            'body' => 'Текст',
        ]);

        $this->assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Campaign::class)->count([]));
    }

    public function testUpdateCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/edit', [
            '_csrf_token' => 'invalid',
            'name' => 'Взлом',
            'subject' => 'Тема',
            'body' => 'Текст',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame('Акция', $this->findCampaign('Акция')->name);
    }

    public function testLaunchCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $campaign->setStatus(CampaignStatus::Ready);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', $this->launchPath($campaign->id), [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame(CampaignStatus::Ready, $this->findCampaign('Акция')->status);
    }

    public function testStopCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $campaign->launch();
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/stop', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame(CampaignStatus::Launched, $this->findCampaign('Акция')->status);
    }

    public function testResetCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $campaign->launch();
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/reset', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame(CampaignStatus::Launched, $this->findCampaign('Акция')->status);
    }

    public function testDeleteCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/delete', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->findCampaign('Акция'));
    }

    public function testCloneCampaignRejectsInvalidCsrfToken(): void
    {
        $campaign = $this->persistCampaign('Оригинал');
        $campaign->setStatus(CampaignStatus::Ready);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/clone', [
            '_csrf_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(Campaign::class)->findBy(['name' => 'Оригинал (копия)']));
    }

    public function testBulkAddByGroupRejectsInvalidCsrfToken(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->flush();
        $campaign = $this->persistCampaign('Рассылка');
        $this->login($manager);

        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients/bulk-by-group', [
            '_csrf_token' => 'invalid',
            'group_id' => $group->id,
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->findCampaign('Рассылка')->recipients);
    }

    public function testManagerCannotAddInaccessibleOrganizationAsRecipient(): void
    {
        [$manager1, , $romashka, $zavod] = $this->makeTwoManagersWithOrganizations();
        // Недоступность задаётся скрытием организации (ADR-0012).
        $this->em()->persist(new OrganizationHide($zavod, $manager1));
        $this->em()->flush();
        $campaign = $this->persistCampaign('Акция');
        $this->login($manager1);

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients', [
            'organization' => $zavod->id,
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertCount(0, $this->findCampaign('Акция')->recipients);
    }

    public function testManagerAddsAccessibleOrganizationAsRecipient(): void
    {
        [$manager1, , $romashka, $zavod] = $this->makeTwoManagersWithOrganizations();
        $this->em()->persist((new Contact())->setOrganization($romashka)->setName('Контакт')->setEmail('romashka@example.test'));
        $this->em()->flush();
        // «ООО Завод» скрыта от менеджера (ADR-0012): в списке организаций
        // формы её нет.
        $this->em()->persist(new OrganizationHide($zavod, $manager1));
        $this->em()->flush();
        $campaign = $this->persistCampaign('Акция');
        $this->login($manager1);

        $crawler = $this->open('/campaigns/' . $campaign->id . '/recipients');
        self::assertStringNotContainsString('ООО Завод', $crawler->filter('select[name="organization"]')->html());

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients', [
            'organization' => $this->findOrganization('ООО Ромашка')->id,
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $recipients = $this->findCampaign('Акция')->recipients;
        self::assertCount(1, $recipients);
        self::assertSame('ООО Ромашка', $recipients->first()->organization->name);
    }

    public function testRecipientsPageDisplaysErrorMessageOnlyWhenPresent(): void
    {
        $campaign = $this->persistCampaign('Ошибки адресатов');
        $failed = new CampaignRecipient(
            $campaign,
            $this->persistOrganization('Без адреса'),
        );
        $failed->markFailed('Отсутствует email-адрес организации', false);
        $pending = new CampaignRecipient(
            $campaign,
            $this->persistOrganization('С адресом'),
        );
        $this->em()->persist($failed);
        $this->em()->persist($pending);
        $this->em()->flush();
        $this->login($this->makeUser('admin-errors', 'admin-errors@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns/' . $campaign->id . '/recipients');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.campaign-recipients__error-message'));
        self::assertSame(
            'Отсутствует email-адрес организации',
            trim($crawler->filter('.campaign-recipients__error-message')->text()),
        );
    }

    public function testAdminCanAddAnyOrganizationAsRecipient(): void
    {
        [, $manager2, , $zavod] = $this->makeTwoManagersWithOrganizations();
        $this->em()->persist((new Contact())->setOrganization($zavod)->setName('Контакт')->setEmail('zavod@example.test'));
        $this->em()->flush();
        $campaign = $this->persistCampaign('Акция');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients', [
            'organization' => $zavod->id,
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertCount(1, $this->findCampaign('Акция')->recipients);
    }

    public function testDuplicateRecipientPromptsReplace(): void
    {
        [$manager1] = $this->makeTwoManagersWithOrganizations();
        $romashka0 = $this->findOrganization('ООО Ромашка');
        $this->em()->persist((new Contact())->setOrganization($romashka0)->setName('Контакт')->setEmail('romashka@example.test'));
        $this->em()->flush();
        $campaign = $this->persistCampaign('Акция');
        $romashka = $this->findOrganization('ООО Ромашка');
        $recipient = new CampaignRecipient($campaign, $romashka);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $campaignId = $campaign->id;
        $this->login($manager1);

        $token = $this->formToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients', [
            'organization' => $romashka->id,
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/recipients/' . $recipient->id . '/replace', (string) $location);

        $this->em()->clear();
        self::assertSame(1, $this->em()->getRepository(CampaignRecipient::class)->count([
            'campaign' => $campaignId,
        ]));
    }

    public function testRemoveRecipientDeletesLink(): void
    {
        $campaign = $this->persistCampaign('Акция');
        $recipient = new CampaignRecipient($campaign, $this->persistOrganization('ООО Ромашка'));
        $this->em()->persist($recipient);
        $this->em()->flush();
        $campaignId = $campaign->id;
        $recipientId = $recipient->id;
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/' . $recipientId . '/delete', [
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(CampaignRecipient::class, $recipientId));
    }

    public function testCloneCampaignCopiesFieldsAndRecipients(): void
    {
        $campaign = $this->persistCampaign('Оригинал');
        $campaign->setStatus(CampaignStatus::Ready);
        $romashka = $this->persistOrganization('ООО Ромашка');
        $recipient = new CampaignRecipient($campaign, $romashka);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/clone', [
            '_csrf_token' => $token,
            'clone_mode' => 'recipients',
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $clones = $this->em()->getRepository(Campaign::class)->findBy(['name' => 'Оригинал (копия)']);
        self::assertCount(1, $clones);
        $clone = $clones[0];
        self::assertSame(CampaignStatus::Draft, $clone->status);
        self::assertCount(1, $clone->recipients);
    }

    public function testCloneWithoutRecipientsDoesNotCopyThem(): void
    {
        $campaign = $this->persistCampaign('Оригинал');
        $campaign->setStatus(CampaignStatus::Ready);
        $romashka = $this->persistOrganization('ООО Ромашка');
        $recipient = new CampaignRecipient($campaign, $romashka);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/clone', [
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $clones = $this->em()->getRepository(Campaign::class)->findBy(['name' => 'Оригинал (копия)']);
        self::assertCount(1, $clones);
        self::assertCount(0, $clones[0]->recipients);
    }

    public function testCloneFromDraftIsRejected(): void
    {
        $campaign = $this->persistCampaign('Черновик');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/clone', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRecipientAddedToDraftExistsBeforeCampaignLaunch(): void
    {
        $campaign = $this->persistCampaign('Черновик');
        $romashka = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.test');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients', [
            'organization' => $romashka->id,
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/campaigns/' . $campaign->id . '/recipients');

        $this->em()->clear();
        $draft = $this->findCampaign('Черновик');
        self::assertSame(CampaignStatus::Draft, $draft->status);
        self::assertCount(1, $draft->recipients);

        $draft->setStatus(CampaignStatus::Ready);
        $draft->launch();
        $this->em()->flush();
        $this->em()->clear();

        $launched = $this->findCampaign('Черновик');
        self::assertSame(CampaignStatus::Launched, $launched->status);
        self::assertCount(1, $launched->recipients);
    }

    public function testIndexSortingByNameDesc(): void
    {
        $this->persistCampaign('Я');
        $this->persistCampaign('А');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns?sort=name&dir=DESC');

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertSame('Я', $rows->first()->filter('td')->first()->text());
    }

    public function testIndexArchivedAtBottom(): void
    {
        $archived = $this->persistCampaign('Архивная');
        $archived->setStatus(CampaignStatus::Archived);
        $this->em()->flush();
        $this->persistCampaign('Активная');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns');

        $rows = $crawler->filter('tbody tr');
        $lastRow = $rows->last();
        self::assertStringContainsString('Архивная', $lastRow->text());
    }

    public function testShowCloneRowHiddenForDraft(): void
    {
        $campaign = $this->persistCampaign('Черновик');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns/' . $campaign->id);

        $this->assertSelectorNotExists('.campaign-card__clone-row');
    }

    public function testShowCloneRowVisibleForReady(): void
    {
        $campaign = $this->persistCampaign('Готова');
        $campaign->setStatus(CampaignStatus::Ready);
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $crawler = $this->open('/campaigns/' . $campaign->id);

        $this->assertSelectorExists('.campaign-card__clone-row');
    }

    public function testShowLaunchButtonOnlyForReady(): void
    {
        $draft = $this->persistCampaign('Черновик');
        $ready = $this->persistCampaign('Готова');
        $ready->setStatus(CampaignStatus::Ready);
        $this->em()->flush();
        $launched = $this->persistCampaign('Запущена');
        $launched->launch();
        $this->em()->flush();
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $this->open('/campaigns/' . $draft->id);
        $this->assertSelectorNotExists('form[action="' . $this->launchPath($draft->id) . '"]');

        $this->open('/campaigns/' . $ready->id);
        $this->assertSelectorExists('form[action="' . $this->launchPath($ready->id) . '"]');

        $this->open('/campaigns/' . $launched->id);
        $this->assertSelectorNotExists('form[action="' . $this->launchPath($launched->id) . '"]');
    }

    // --- Campaign bulk-by-group tests (task 6.7) ---

    public function testBulkAddByGroupAddsOrgsToCampaign(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $org1 = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $org2 = $this->persistOrganizationWithEmail('ООО Вектор', 'vector@example.com');
        $this->em()->persist(new OrgGroupMembership($org1, $group));
        $this->em()->persist(new OrgGroupMembership($org2, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $campaignId = $campaign->id;
        $this->login($manager);

        $token = $this->campaignToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $recipients = $this->findCampaign('Рассылка')->recipients;
        self::assertCount(2, $recipients);
    }

    public function testBulkAddByGroupSkipsExistingRecipients(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $org1 = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $this->em()->persist(new OrgGroupMembership($org1, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $existing = new CampaignRecipient($campaign, $org1);
        $this->em()->persist($existing);
        $this->em()->flush();
        $campaignId = $campaign->id;

        $this->login($manager);
        $token = $this->campaignToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Добавлено: 0, пропущено: 1');
        self::assertStringNotContainsString('нет e-mail', (string) $this->client->getResponse()->getContent());
        $this->em()->clear();

        $recipients = $this->findCampaign('Рассылка')->recipients;
        self::assertCount(1, $recipients);
    }

    public function testBulkAddByGroupSkipsOrganizationsWithoutEmail(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $withEmail = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $withoutEmail = $this->persistOrganization('ООО Без Почты');
        $this->em()->persist(new OrgGroupMembership($withEmail, $group));
        $this->em()->persist(new OrgGroupMembership($withoutEmail, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $campaignId = $campaign->id;
        $this->login($manager);

        $token = $this->campaignToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Добавлено: 1, пропущено: 1 (в том числе нет e-mail: 1)');

        $this->em()->clear();
        $recipients = $this->findCampaign('Рассылка')->recipients;
        self::assertCount(1, $recipients);
        self::assertSame('ООО Ромашка', $recipients->first()->organization->name);
    }

    public function testManagerCannotBulkAddFromInaccessibleGroup(): void
    {
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Чужая')->setCreatedBy($manager2);
        $this->em()->persist($group);
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $this->login($manager1);

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->findCampaign('Рассылка')->recipients);
    }

    public function testBulkAddByGroupAjaxReturnsJson(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $org1 = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $this->em()->persist(new OrgGroupMembership($org1, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $existing = new CampaignRecipient($campaign, $org1);
        $this->em()->persist($existing);
        $org2 = $this->persistOrganizationWithEmail('ООО Вектор', 'vector@example.com');
        $this->em()->persist(new OrgGroupMembership($org2, $group));
        $this->em()->flush();
        $campaignId = $campaign->id;

        $this->login($manager);
        $token = $this->campaignToken($campaignId);
        $this->client->request(
            'POST',
            '/campaigns/' . $campaignId . '/recipients/bulk-by-group',
            ['_csrf_token' => $token, 'group_id' => $group->id],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['added' => 1, 'skipped' => 1, 'no_email' => 0],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
        $this->em()->clear();
        self::assertCount(2, $this->findCampaign('Рассылка')->recipients);
    }

    public function testBulkAddByGroupWithEmptyGroupAddsNothing(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Пустая')->setCreatedBy($manager);
        $this->em()->persist($group);
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $this->login($manager);

        $token = $this->campaignToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'В группе «Пустая» нет организаций — добавлять нечего');
        $this->em()->clear();
        self::assertCount(0, $this->findCampaign('Рассылка')->recipients);
    }

    public function testBulkAddAllDiscardsNoEmailOrganizationsInMessage(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $withEmail = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $noEmail1 = $this->persistOrganization('ООО Без Почты');
        $noEmail2 = $this->persistOrganization('ООО Тишина');
        $this->em()->persist(new OrgGroupMembership($withEmail, $group));
        $this->em()->persist(new OrgGroupMembership($noEmail1, $group));
        $this->em()->persist(new OrgGroupMembership($noEmail2, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $campaignId = $campaign->id;
        $this->login($manager);

        $token = $this->formToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/bulk', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Добавлено: 1, пропущено: 2 (в том числе нет e-mail: 2)');

        $this->em()->clear();
        self::assertCount(1, $this->findCampaign('Рассылка')->recipients);
    }

    // --- Opt-out filtering tests (tasks 10.3-10.4) ---

    public function testManualAddOptedOutOrganizationShowsFlashAndDoesNotAdd(): void
    {
        $org = $this->persistOrganizationWithEmail('ООО Отписана', 'optout@example.com');
        $org->setIsOptedOut(true);
        $this->em()->flush();
        $campaign = $this->persistCampaign('Рассылка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients', [
            'organization' => $org->id,
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Организация отписана от рассылок');

        $this->em()->clear();
        self::assertCount(0, $this->findCampaign('Рассылка')->recipients);
    }

    public function testBulkAddSkipsOptedOutOrganizations(): void
    {
        $withEmail = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $optedOut = $this->persistOrganizationWithEmail('ООО Отписана', 'optout@example.com');
        $optedOut->setIsOptedOut(true);
        $this->em()->flush();
        $campaign = $this->persistCampaign('Рассылка');
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));

        $token = $this->formToken($campaign->id);
        $this->client->request('POST', '/campaigns/' . $campaign->id . '/recipients/bulk', [
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Добавлено: 1, пропущено: 1');
        self::assertStringContainsString('отписаны: 1', (string) $this->client->getResponse()->getContent());

        $this->em()->clear();
        $recipients = $this->findCampaign('Рассылка')->recipients;
        self::assertCount(1, $recipients);
        self::assertSame('ООО Ромашка', $recipients->first()->organization->name);
    }

    public function testBulkAddByGroupSkipsOptedOutOrganizations(): void
    {
        $manager = $this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager);
        $group = (new OrganizationGroup())->setName('Группа А')->setCreatedBy($manager);
        $this->em()->persist($group);

        $withEmail = $this->persistOrganizationWithEmail('ООО Ромашка', 'romashka@example.com');
        $optedOut = $this->persistOrganizationWithEmail('ООО Отписана', 'optout@example.com');
        $optedOut->setIsOptedOut(true);
        $this->em()->persist(new OrgGroupMembership($withEmail, $group));
        $this->em()->persist(new OrgGroupMembership($optedOut, $group));
        $this->em()->flush();

        $campaign = $this->persistCampaign('Рассылка');
        $campaignId = $campaign->id;
        $this->login($manager);

        $token = $this->campaignToken($campaignId);
        $this->client->request('POST', '/campaigns/' . $campaignId . '/recipients/bulk-by-group', [
            '_csrf_token' => $token,
            'group_id' => $group->id,
        ]);

        $this->assertResponseRedirects();
        $this->em()->clear();

        $recipients = $this->findCampaign('Рассылка')->recipients;
        self::assertCount(1, $recipients);
        self::assertSame('ООО Ромашка', $recipients->first()->organization->name);
    }

    private function storage(): CampaignAttachmentStorage
    {
        /** @var CampaignAttachmentStorage $storage */
        $storage = static::getContainer()->get(CampaignAttachmentStorage::class);

        return $storage;
    }

    private function campaignToken(int $campaignId): string
    {
        return (string) $this->open('/campaigns/' . $campaignId . '/edit')
            ->filter('input[name="_csrf_token"]')
            ->first()
            ->attr('value');
    }

    /**
     * CSRF-токен для вложений: берётся из data-csrf существующей кнопки
     * «Удалить» (campaign_attachment token ID).
     */
    private function attachmentCsrfToken(int $campaignId): string
    {
        $crawler = $this->open('/campaigns/' . $campaignId . '/edit');
        $btn = $crawler->filter('button[data-action="remove-attachment"]');

        if ($btn->count() > 0) {
            return (string) $btn->first()->attr('data-csrf');
        }

        // Нет вложений — токен в скрытом поле рядом с upload.
        return (string) $crawler->filter('input[name="_attachment_csrf"]')->first()->attr('value');
    }

    private function launchPath(int $id): string
    {
        return '/campaigns/' . $id . '/launch';
    }

    private function persistCampaign(string $name): Campaign
    {
        $campaign = new Campaign()
            ->setName($name)
            ->setSubject('Приглашаем на курсы')
            ->setBody('{{greeting}}!');
        $this->em()->persist($campaign);
        $this->em()->flush();

        return $campaign;
    }

    private function persistOrganizationWithEmail(string $name, string $email): Organization
    {
        $organization = $this->persistOrganization($name);
        $this->em()->persist(
            (new Contact())->setOrganization($organization)->setName('Контакт')->setEmail($email)
        );
        $this->em()->flush();

        return $organization;
    }

    private function persistOrganization(string $name): Organization
    {
        $organization = new Organization()->setName($name)->setIndustry('IT');
        $this->em()->persist($organization);
        $this->em()->flush();

        return $organization;
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

    private function findCampaign(string $name): ?Campaign
    {
        return $this->em()->getRepository(Campaign::class)->findOneBy(['name' => $name]);
    }

    private function findOrganization(string $name): ?Organization
    {
        return $this->em()->getRepository(Organization::class)->findOneBy(['name' => $name]);
    }

    private function makeTwoManagersWithOrganizations(): array
    {
        $em = $this->em();
        $manager1 = $this->makeUser('manager1', 'manager1@b2b-crm.loc', UserRole::Manager);
        $manager2 = $this->makeUser('manager2', 'manager2@b2b-crm.loc', UserRole::Manager);
        $em->flush();

        $personal1 = (new OrganizationGroup())
            ->setName('Личная группа ' . $manager1->email)
            ->setCreatedBy($manager1);
        $personal2 = (new OrganizationGroup())
            ->setName('Личная группа ' . $manager2->email)
            ->setCreatedBy($manager2);
        $em->persist($personal1);
        $em->persist($personal2);

        $romashka = $this->persistOrganization('ООО Ромашка');
        $zavod = $this->persistOrganization('ООО Завод');

        $em->persist(new OrgGroupMembership($romashka, $personal1));
        $em->persist(new OrgGroupMembership($zavod, $personal2));
        $em->flush();

        return [$manager1, $manager2, $romashka, $zavod];
    }

    private function formToken(int $campaignId): string
    {
        $crawler = $this->open('/campaigns/' . $campaignId . '/recipients');

        // Предпочтительно: data-csrf кнопки «Добавить».
        $btn = $crawler->filter('[data-action="add-recipient"]');
        if ($btn->count() > 0) {
            return (string) $btn->first()->attr('data-csrf');
        }

        // Fallback: скрытое поле _recipient_csrf (всегда на странице редактирования).
        $hidden = $crawler->filter('input[name="_recipient_csrf"]');
        if ($hidden->count() > 0) {
            return (string) $hidden->first()->attr('value');
        }

        return (string) $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }
}
