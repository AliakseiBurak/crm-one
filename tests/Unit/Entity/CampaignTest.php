<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Campaign;
use App\Entity\CampaignAttachment;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты Campaign: renderBody(), cloneFrom(), launch(), fail().
 */
final class CampaignTest extends TestCase
{
    public function testRenderBodyWithContact(): void
    {
        $campaign = new Campaign()
            ->setBody('{{greeting}}! {{contact_name}}, добро пожаловать.');

        $org = new Organization()->setName('ООО Ромашка');
        $contact = new Contact()->setOrganization($org)->setName('Иван Петров');

        $rendered = $campaign->renderBody($contact, $org);

        self::assertSame('Уважаемый(ая) Иван Петров! Иван Петров, добро пожаловать.', $rendered);
    }

    public function testRenderBodyWithoutContact(): void
    {
        $campaign = new Campaign()
            ->setBody('{{greeting}}! Ждём вас.');

        $org = new Organization()->setName('ООО Ромашка');

        $rendered = $campaign->renderBody(null, $org);

        self::assertSame('Уважаемые сотрудники ООО Ромашка! Ждём вас.', $rendered);
    }

    public function testRenderBodyOrganizationNameToken(): void
    {
        $campaign = new Campaign()
            ->setBody('Компания {{organization_name}} приглашает.');

        $org = new Organization()->setName('АО Вектор');

        $rendered = $campaign->renderBody(null, $org);

        self::assertSame('Компания АО Вектор приглашает.', $rendered);
    }

    public function testRenderBodyContactNameToken(): void
    {
        $campaign = new Campaign()
            ->setBody('{{contact_name}}, здравствуйте.');

        $org = new Organization()->setName('ООО Ромашка');
        $contact = new Contact()->setOrganization($org)->setName('Мария Ивановна');

        $rendered = $campaign->renderBody($contact, $org);

        self::assertSame('Мария Ивановна, здравствуйте.', $rendered);
    }

    public function testRenderBodyWithoutContactUsesOrganizationNameEvenWithMainContact(): void
    {
        $campaign = new Campaign()
            ->setBody('{{greeting}}! {{contact_name}}, добро пожаловать.');

        $org = new Organization()->setName('ООО Ромашка');
        // isMain не влияет на токены: при отсутствии адресата {{contact_name}}
        // и {{greeting}} подставляются названием организации.
        new Contact()->setOrganization($org)->setName('Мария Смирнова')->setIsMain(true);

        $rendered = $campaign->renderBody(null, $org);

        self::assertSame('Уважаемые сотрудники ООО Ромашка! ООО Ромашка, добро пожаловать.', $rendered);
    }

    public function testRenderBodyAllTokensCombined(): void
    {
        $campaign = new Campaign()
            ->setBody('{{greeting}}! {{organization_name}} предлагает {{contact_name}} скидку.');

        $org = new Organization()->setName('ООО Закат');
        $contact = new Contact()->setOrganization($org)->setName('Ольга');

        $rendered = $campaign->renderBody($contact, $org);

        self::assertSame('Уважаемый(ая) Ольга! ООО Закат предлагает Ольга скидку.', $rendered);
    }

    public function testLaunchSetsStatusAndLaunchedAt(): void
    {
        $campaign = new Campaign()->setName('Тест');

        self::assertSame(CampaignStatus::Draft, $campaign->status);
        self::assertNull($campaign->launchedAt);

        $campaign->launch();

        self::assertSame(CampaignStatus::Launched, $campaign->status);
        self::assertNotNull($campaign->launchedAt);
        self::assertTrue($campaign->isLaunched());
    }

    public function testLaunchDoesNotOverwriteLaunchedAt(): void
    {
        $campaign = new Campaign()->setName('Тест');
        $campaign->launch();
        $first = $campaign->launchedAt;

        $campaign->launch();

        self::assertSame($first, $campaign->launchedAt);
    }

    public function testFailSetsStatusAndFailedAt(): void
    {
        $campaign = new Campaign()->setName('Тест');

        self::assertNull($campaign->failedAt);

        $campaign->fail('Проверьте MAILER_DSN.');

        self::assertSame(CampaignStatus::Failed, $campaign->status);
        self::assertNotNull($campaign->failedAt);
        self::assertSame('Проверьте MAILER_DSN.', $campaign->failureReason);
    }

    public function testLaunchClearsFailureReason(): void
    {
        $campaign = new Campaign()->setName('Тест');
        $campaign->fail('Сбой SMTP');

        $campaign->launch();

        self::assertSame(CampaignStatus::Launched, $campaign->status);
        self::assertNull($campaign->failureReason);
    }

    public function testRenderSubjectAndPreviewFillTokens(): void
    {
        $campaign = new Campaign()
            ->setSubject('Для {{organization_name}}')
            ->setPreviewText('{{greeting}}');

        $org = new Organization()->setName('ООО Ромашка');
        $contact = new Contact()->setOrganization($org)->setName('Иван Петров');

        self::assertSame('Для ООО Ромашка', $campaign->renderSubject($contact, $org));
        self::assertSame('Уважаемый(ая) Иван Петров', $campaign->renderPreviewText($contact, $org));
        self::assertNull(new Campaign()->renderPreviewText($contact, $org));
    }

    public function testCloneFromCopiesFieldsAndSuffix(): void
    {
        $source = new Campaign()
            ->setName('Оригинал')
            ->setSubject('Тема')
            ->setPreviewText('Превью')
            ->setBody('Текст');

        $clone = Campaign::cloneFrom($source, false);

        self::assertSame('Оригинал (копия)', $clone->name);
        self::assertSame('Тема', $clone->subject);
        self::assertSame('Превью', $clone->previewText);
        self::assertSame('Текст', $clone->body);
        self::assertSame(CampaignStatus::Draft, $clone->status);
        self::assertNull($clone->launchedAt);
    }

    public function testCloneFromCopiesAttachmentsMetadata(): void
    {
        $source = new Campaign()->setName('Источник');
        $source->addAttachment(
            new CampaignAttachment($source, 'file.pdf', 'key-123')
                ->setMimeType('application/pdf')
                ->setSize(1024)
        );

        $clone = Campaign::cloneFrom($source, false);

        self::assertCount(1, $clone->attachments);
        $cloneAttachment = $clone->attachments->first();
        self::assertSame('file.pdf', $cloneAttachment->filename);
        self::assertSame('key-123', $cloneAttachment->storageKey);
        self::assertSame('application/pdf', $cloneAttachment->mimeType);
        self::assertSame(1024, $cloneAttachment->size);
        // Same storage key — files are shared, not duplicated.
        self::assertSame($source->attachments->first()->storageKey, $cloneAttachment->storageKey);
    }

    public function testCloneFromWithRecipients(): void
    {
        $source = new Campaign()->setName('Источник');
        $org = new Organization()->setName('ООО Ромашка');
        $source->addRecipient(new CampaignRecipient($source, $org));

        $clone = Campaign::cloneFrom($source, true);

        self::assertCount(1, $clone->recipients);
        self::assertSame('ООО Ромашка', $clone->recipients->first()->organization->name);
    }

    public function testCloneFromWithoutRecipients(): void
    {
        $source = new Campaign()->setName('Источник');
        $org = new Organization()->setName('ООО Ромашка');
        $source->addRecipient(new CampaignRecipient($source, $org));

        $clone = Campaign::cloneFrom($source, false);

        self::assertCount(0, $clone->recipients);
    }
}
