<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Campaign;
use App\Entity\CampaignAttachment;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\CampaignRecipientRepository;
use App\Repository\CampaignRepository;
use App\Repository\UserRepository;
use App\Service\CampaignAttachmentStorage;
use App\Service\CampaignEmailRenderer;
use App\Service\CampaignTokenFiller;
use App\Service\EmailAddressMasker;
use App\Service\MailingService;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extra\CssInliner\CssInlinerExtension;
use Twig\Loader\FilesystemLoader;

final class MailingServiceTest extends TestCase
{
    private const BASE_URL = 'https://b2b-crm.local';

    /** @var list<Email> */
    private array $sent = [];

    /** @var array<string, list<string>> */
    private array $generated = [];

    private MailerInterface&MockObject $mailer;

    private CampaignRepository&MockObject $campaigns;

    private CampaignRecipientRepository&MockObject $recipients;

    private UserRepository&MockObject $users;

    private EntityManagerInterface&MockObject $em;

    /** Служебные сообщения MailingService остаются в дефолтном канале app. */
    private TestHandler $appHandler;

    /** Результаты фактической отправки — отдельный канал mailer. */
    private TestHandler $mailerHandler;

    private MailingService $service;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->generated = [];
        $this->mailer = $this->createMock(MailerInterface::class);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('contains')->willReturn(true);

        $this->campaigns = $this->createMock(CampaignRepository::class);
        $this->recipients = $this->createMock(CampaignRecipientRepository::class);
        $this->users = $this->createMock(UserRepository::class);

        $this->appHandler = new TestHandler();
        $this->mailerHandler = new TestHandler();

        $this->service = $this->createService($this->em);
    }

    public function testSpecifiedContactWithEmailIsToWithOthersInCcAndDelivered(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $alice = $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->contact($org, 'Борис', 'boris@example.ru');
        $recipient = $this->recipient($org, $alice);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame('Алиса', $this->sent[0]->getTo()[0]->getName());
        self::assertSame('Для ООО Ромашка', $this->sent[0]->getSubject());
        $html = (string) $this->sent[0]->getHtmlBody();
        self::assertStringContainsString('Уважаемый(ая) Алиса', $html);
        self::assertStringContainsString('mso-hide:all', $html);
        self::assertStringContainsString($this->generated['app_tracking_pixel'][0], $html);
        self::assertStringContainsString('Уважаемый(ая) Алиса', (string) $this->sent[0]->getTextBody());
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testWithoutSpecifiedContactEmailGoesToMainContactWithCcAndOrgDisplayName(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $alice = $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->setId($alice, 2);
        $alice->setIsMain(true);
        $boris = $this->contact($org, 'Борис', 'boris@example.ru');
        $this->setId($boris, 1);
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame('ООО Ромашка', $this->sent[0]->getTo()[0]->getName());
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testWithoutMainContactEmailGoesToSmallestIdContactWithOrgDisplayName(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $alice = $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->setId($alice, 3);
        $boris = $this->contact($org, 'Борис', 'boris@example.ru');
        $this->setId($boris, 1);
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame('ООО Ромашка', $this->sent[0]->getTo()[0]->getName());
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testSpecifiedContactWithoutEmailFallsBackToMainContactWithSpecifiedDisplayName(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $noEmail = $this->contact($org, 'Без почты', null);
        $this->setId($noEmail, 3);
        $main = $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->setId($main, 1);
        $main->setIsMain(true);
        $boris = $this->contact($org, 'Борис', 'boris@example.ru');
        $this->setId($boris, 2);
        $recipient = $this->recipient($org, $noEmail);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame('Без почты', $this->sent[0]->getTo()[0]->getName());
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testUnsubscribeUrlTokenIsResolvedPerRecipient(): void
    {
        $this->captureSentMail();
        $campaign = $this->campaign();
        $campaign->setBody('Отписаться: {{unsubscribe_url}}');
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $recipient = new CampaignRecipient($campaign, $org);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        $html = (string) $this->sent[0]->getHtmlBody();
        self::assertStringContainsString(
            $this->generated['app_unsubscribe'][0],
            $html,
        );
        self::assertStringNotContainsString('{{unsubscribe_url}}', $html);
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testOrganizationWithoutSpecifiedContactSendsOneEmailWithCc(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->contact($org, 'Борис', 'boris@example.ru');
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testSpecifiedContactWithoutEmailFallsBackToOrganizationAddresses(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $noEmail = $this->contact($org, 'Без почты', null);
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $this->contact($org, 'Борис', 'boris@example.ru');
        $recipient = $this->recipient($org, $noEmail);

        $this->service->processRecipient($recipient);

        self::assertCount(1, $this->sent);
        self::assertSame(['alice@example.ru'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame(['boris@example.ru'], $this->addresses($this->sent[0]->getCc()));
        self::assertSame(RecipientStatus::Delivered, $recipient->status);
    }

    public function testCampaignAttachmentsAreIncludedInEmail(): void
    {
        $this->captureSentMail();
        $campaign = $this->campaign();
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $recipient = new CampaignRecipient($campaign, $org);
        $tmpDir = sys_get_temp_dir() . '/mailing-service-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tmpDir, 0777, true));
        $storage = new CampaignAttachmentStorage($tmpDir);
        $storageKey = 'mailing-service-test-' . bin2hex(random_bytes(8));
        $path = $storage->path($storageKey);

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        self::assertNotFalse(file_put_contents($path, 'attachment body'));
        new CampaignAttachment($campaign, 'предложение.txt', $storageKey)
            ->setMimeType('text/plain')
            ->setSize(15);

        try {
            $this->createService($this->em, $storage)->processRecipient($recipient);

            self::assertCount(1, $this->sent);
            self::assertCount(1, $this->sent[0]->getAttachments());
            self::assertSame(
                'предложение.txt',
                $this->sent[0]->getAttachments()[0]->getFilename(),
            );
            self::assertSame(RecipientStatus::Delivered, $recipient->status);
        } finally {
            $storage->delete($storageKey);
            $this->removeDirectory($tmpDir);
        }
    }

    public function testMissingEmailMarksPermanentFailedWithoutSending(): void
    {
        $org = $this->organization();
        $this->contact($org, 'Без почты', null);
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertSame([], $this->sent);
        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame('Отсутствует email-адрес организации/контакта', $recipient->errorMessage);
        self::assertSame(0, $recipient->retryCount);
        self::assertNull($recipient->retryAt);
    }

    public function testSmtp5xxMarksBounced(): void
    {
        $this->mailer->method('send')->willThrowException(
            new \RuntimeException('got code "550" mailbox unavailable'),
        );
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertSame(RecipientStatus::Bounced, $recipient->status);
        self::assertSame('550', $recipient->errorMessage);
    }

    public function testSmtp4xxMarksRetriableFailed(): void
    {
        $this->mailer->method('send')->willThrowException(
            new \RuntimeException('got code "421" try again later'),
        );
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(1, $recipient->retryCount);
        self::assertNotNull($recipient->retryAt);
        self::assertSame(CampaignStatus::Launched, $recipient->campaign->status);
    }

    public function testSmtpTimeoutMarksRetriableFailed(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException('Connection timed out'));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $recipient = $this->recipient($org);

        $this->service->processRecipient($recipient);

        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(1, $recipient->retryCount);
        self::assertNotNull($recipient->retryAt);
        self::assertSame(CampaignStatus::Launched, $recipient->campaign->status);
    }

    public function testFailureForOneRecipientDoesNotBlockTheNextRecipient(): void
    {
        $campaign = $this->campaign();
        $this->setId($campaign, 31);
        $failedOrg = $this->organization();
        $this->contact($failedOrg, 'Ошибка', 'failed@example.ru');
        $successfulOrg = $this->organization();
        $this->contact($successfulOrg, 'Успех', 'success@example.ru');
        $failed = new CampaignRecipient($campaign, $failedOrg);
        $successful = new CampaignRecipient($campaign, $successfulOrg);
        $this->recipients->method('countStillProcessing')->with(31)->willReturn(1);
        $this->mailer->method('send')->willReturnCallback(function (Email $email): void {
            if ('failed@example.ru' === $email->getTo()[0]->getAddress()) {
                throw new \RuntimeException('got code "550" mailbox unavailable');
            }
            $this->sent[] = $email;
        });

        $this->service->processRecipient($failed);
        $this->service->processRecipient($successful);

        self::assertSame(RecipientStatus::Bounced, $failed->status);
        self::assertSame(RecipientStatus::Delivered, $successful->status);
        self::assertCount(1, $this->sent);
        self::assertSame(
            ['success@example.ru'],
            $this->addresses($this->sent[0]->getTo()),
        );
    }

    public function testReloadsRecipientWhenEntityIsDetached(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $managed = $this->recipient($org);
        $this->setId($managed, 41);
        $stale = $this->recipient($this->organization());
        $this->setId($stale, 41);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $this->recipients->method('find')->with(41)->willReturn($managed);

        $this->createService($em)->processRecipient($stale);

        self::assertSame(RecipientStatus::Pending, $stale->status);
        self::assertSame(RecipientStatus::Delivered, $managed->status);
        self::assertCount(1, $this->sent);
    }

    public function testSkipsWhenDetachedRecipientIsMissing(): void
    {
        $stale = $this->recipient($this->organization());
        $this->setId($stale, 42);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $this->recipients->method('find')->with(42)->willReturn(null);
        $this->mailer->expects(self::never())->method('send');

        $this->createService($em)->processRecipient($stale);

        self::assertSame(RecipientStatus::Pending, $stale->status);
    }

    public function testAllUndeliverableEscalatesCampaignAndNotifiesAdmin(): void
    {
        $this->captureSentMail();
        $admin = new User()->setLogin('admin')->setEmail('admin@b2b-crm.loc');
        $this->users->method('findAdmins')->willReturn([$admin]);

        $org = $this->organization();
        $campaign = $this->campaign();
        $this->setId($campaign, 22);
        $this->campaigns->method('find')->with(22)->willReturn($campaign);
        $this->recipients->method('countStillProcessing')->with(22)->willReturn(0);
        $this->recipients->method('countDeliveredOrOpened')->with(22)->willReturn(0);
        $this->recipients->method('countByCampaign')->with(22)->willReturn(1);
        $this->recipients->method('countNoEmailFailures')->with(22)->willReturn(1);

        $recipient = new CampaignRecipient($campaign, $org);
        $this->service->processRecipient($recipient);

        self::assertSame(CampaignStatus::Failed, $campaign->status);
        self::assertStringContainsString('отсутствует email-адрес', (string) $campaign->failureReason);
        self::assertCount(1, $this->sent);
        self::assertSame(['admin@b2b-crm.loc'], $this->addresses($this->sent[0]->getTo()));
        self::assertSame('Ошибка рассылки #22: Акция', $this->sent[0]->getSubject());
    }

    public function testRetriableFailuresDoNotEscalateCampaign(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException('Connection timed out'));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'alice@example.ru');
        $campaign = $this->campaign();
        $this->setId($campaign, 7);
        $this->recipients->method('countStillProcessing')->with(7)->willReturn(1);
        $recipient = new CampaignRecipient($campaign, $org);

        $this->service->processRecipient($recipient);

        self::assertSame(CampaignStatus::Launched, $campaign->status);
        self::assertNull($campaign->failureReason);
        self::assertSame([], $this->sent);
    }

    public function testDeliveredIsWrittenToMailerChannelWithMaskedAddresses(): void
    {
        $this->captureSentMail();
        $org = $this->organization();
        $this->setId($org, 3);
        $alice = $this->contact($org, 'Алиса', 'anna@example.ru');
        // Две копии: сценарий «Адреса в копии под маской» требует именно список.
        $this->contact($org, 'Борис', 'boris@example.org');
        $this->contact($org, 'Вера', 'vera@example.net');
        $campaign = $this->campaign();
        $this->setId($campaign, 5);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org, $alice));

        $records = $this->mailerRecords();
        self::assertCount(1, $records);
        self::assertSame('Campaign send delivered', $records[0]->message);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame([
            'campaign_id' => 5,
            'campaign_name' => 'Акция',
            'organization_id' => 3,
            'organization_name' => 'ООО Ромашка',
            'recipient' => 'a***@example.ru',
            'cc' => ['b***@example.org', 'v***@example.net'],
            'result' => 'delivered',
        ], $records[0]->context);
        // Маскирование относится к записи журнала, а не к письму: в письме
        // копии уходят полными адресами.
        self::assertSame(
            ['boris@example.org', 'vera@example.net'],
            $this->addresses($this->sent[0]->getCc()),
        );
        self::assertSame([], $this->appHandler->getRecords());
    }

    public function testBouncedIsWrittenToMailerChannel(): void
    {
        $this->mailer->method('send')->willThrowException(
            new \RuntimeException('got code "550" mailbox unavailable'),
        );
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $records = $this->mailerRecords();
        self::assertCount(1, $records);
        self::assertSame('Campaign send bounced', $records[0]->message);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('bounced', $records[0]->context['result']);
        self::assertSame('a***@example.ru', $records[0]->context['recipient']);
        self::assertSame([], $records[0]->context['cc']);
        // Ответ 5xx — это отказ сервера, а не сбой отправки: текст ошибки в
        // записи bounced не пишется.
        self::assertArrayNotHasKey('error', $records[0]->context);
    }

    public function testFailedRecordsCleanedSmtpErrorText(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException(
            "got code \"421\"\n4.7.0 <anna@example.ru> Temporary failure\n"
            . ' 4.7.0 Too many connections, try again later',
        ));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $records = $this->mailerRecords();
        self::assertCount(1, $records);
        self::assertSame('Campaign send failed', $records[0]->message);
        self::assertSame('failed', $records[0]->context['result']);
        self::assertStringNotContainsString('anna', $records[0]->context['error']);
        self::assertStringContainsString('example.ru', $records[0]->context['error']);
    }

    /**
     * Реальные ответы MTA: локальная часть адреса получателя не должна
     * встречаться **нигде** в собранной записи, а не только в поле `error`.
     * Перечисление полей проверяло бы лишь известные места и пропустило бы
     * текст ошибки — единственный путь утечки (D3, D14).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideRecipientLocalPartNeverLeaksIntoRecordCases(): iterable
    {
        yield 'Postfix 550 в угловых скобках' => [
            'got code "550" 550 5.1.1 <anna@example.ru>: Recipient address rejected: User unknown',
        ];

        yield 'многострочный ответ Postfix' => [
            "got code \"550\"\n550-5.1.1 The email account that you tried to reach\n"
            . ' 550 5.1.1 <anna@example.ru> does not exist',
        ];

        yield 'Exim 550' => [
            'got code "550" anna@example.ru: Rejected: address does not exist',
        ];

        yield 'Gmail 550' => [
            'got code "550" 550-5.2.1 The email account that you tried to reach does not exist. '
            . '550 5.2.1 <anna@example.ru> sender denied',
        ];

        yield 'Gmail 421' => [
            'got code "421" 4.7.0 <anna@example.ru> Temporary failure',
        ];

        yield 'таймаут' => ['Connection timed out'];

        yield 'отказ в соединении' => ['Connection refused'];

        yield 'ошибка аутентификации 535' => [
            'Failed to authenticate on SMTP server with username "user@b2b-crm.local": '
            . '535 5.7.8 Authentication credentials invalid',
        ];
    }

    #[DataProvider('provideRecipientLocalPartNeverLeaksIntoRecordCases')]
    public function testRecipientLocalPartNeverLeaksIntoRecord(string $error): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException($error));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $this->contact($org, 'Борис', 'boris@example.org');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $records = $this->mailerRecords();
        self::assertCount(1, $records);
        $line = $this->formatRecord($records[0]);
        self::assertStringNotContainsString('anna', $line);
        self::assertStringNotContainsString('boris', $line);
        self::assertStringContainsString('a***@example.ru', $line);
        self::assertStringContainsString('b***@example.org', $line);
    }

    public function testRetryAfterTransientFailureKeepsBothRecords(): void
    {
        $attempt = 0;
        $this->mailer->method('send')->willReturnCallback(function (Email $email) use (&$attempt): void {
            if (0 === $attempt++) {
                throw new \RuntimeException('Connection timed out');
            }
            $this->sent[] = $email;
        });
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);
        $recipient = new CampaignRecipient($campaign, $org);

        $this->service->processRecipient($recipient);
        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(1, $recipient->retryCount);

        $this->service->processRecipient($recipient);
        self::assertSame(RecipientStatus::Delivered, $recipient->status);

        $records = $this->mailerRecords();
        self::assertCount(2, $records);
        self::assertSame('failed', $records[0]->context['result']);
        self::assertSame('delivered', $records[1]->context['result']);
        self::assertSame('a***@example.ru', $records[1]->context['recipient']);
    }

    public function testRecordsCarryNoRetryIndicatorForTransientError(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException('Connection timed out'));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);
        $recipient = new CampaignRecipient($campaign, $org);

        $this->service->processRecipient($recipient);

        self::assertSame([], $this->retryIndicators($this->mailerRecords()[0]));
        // Сам прогноз повтора остаётся в БД: журнал его не дублирует (D15).
        self::assertSame(1, $recipient->retryCount);
        self::assertNotNull($recipient->retryAt);
    }

    public function testRecordsCarryNoRetryIndicatorForPermanentError(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException('Connection refused'));
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();
        $this->recipients->method('countStillProcessing')->willReturn(1);
        $recipient = new CampaignRecipient($campaign, $org);

        $this->service->processRecipient($recipient);

        $records = $this->mailerRecords();
        self::assertCount(1, $records);
        self::assertSame('Campaign send failed', $records[0]->message);
        // Постоянная ошибка — тоже `failed` и тоже несёт текст ошибки SMTP,
        // но повторная обработка в БД при этом не планируется.
        self::assertSame('failed', $records[0]->context['result']);
        self::assertSame('Connection refused', $records[0]->context['error']);
        self::assertSame(RecipientStatus::Failed, $recipient->status);
        self::assertSame(0, $recipient->retryCount);
        self::assertNull($recipient->retryAt);
        self::assertSame([], $this->retryIndicators($records[0]));
    }

    public function testOptedOutOrganizationWritesNoSendLogRecord(): void
    {
        $org = $this->organization();
        $org->setIsOptedOut(true);
        $this->contact($org, 'Алиса', 'anna@example.ru');
        $campaign = $this->campaign();

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        self::assertSame([], $this->mailerHandler->getRecords());
    }

    public function testRecipientWithoutEmailWritesNoSendLogRecord(): void
    {
        $org = $this->organization();
        $this->contact($org, 'Без почты', null);
        $campaign = $this->campaign();

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        self::assertSame([], $this->mailerHandler->getRecords());
    }

    public function testMissingAttachmentIsReportedInAppChannelOnly(): void
    {
        $this->captureSentMail();
        $campaign = $this->campaign();
        $org = $this->organization();
        $this->contact($org, 'Алиса', 'anna@example.ru');
        new CampaignAttachment($campaign, 'пропал.txt', 'missing-storage-key');

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $this->assertServiceMessageStaysInAppChannel('Вложение рассылки');
    }

    public function testNoAdminsToNotifyIsReportedInAppChannelOnly(): void
    {
        $this->users->method('findAdmins')->willReturn([]);
        $org = $this->organization();
        $this->contact($org, 'Без почты', null);
        $campaign = $this->campaign();
        $this->escalateFailedCampaign($campaign, 11);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $this->assertServiceMessageStaysInAppChannel('Нет администраторов');
    }

    public function testAdminWithoutEmailIsReportedInAppChannelOnly(): void
    {
        $this->users->method('findAdmins')->willReturn([(new User())->setLogin('admin')]);
        $org = $this->organization();
        $this->contact($org, 'Без почты', null);
        $campaign = $this->campaign();
        $this->escalateFailedCampaign($campaign, 12);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $this->assertServiceMessageStaysInAppChannel('без email');
    }

    public function testFailedAdminNotificationIsReportedInAppChannelOnly(): void
    {
        $this->users->method('findAdmins')->willReturn([
            (new User())->setLogin('admin')->setEmail('admin@b2b-crm.loc'),
        ]);
        $this->mailer->method('send')->willReturnCallback(static function (Email $email): void {
            if ('admin@b2b-crm.loc' === $email->getTo()[0]->getAddress()) {
                throw new \RuntimeException('Notification transport failed');
            }
        });
        $org = $this->organization();
        $this->contact($org, 'Без почты', null);
        $campaign = $this->campaign();
        $this->escalateFailedCampaign($campaign, 13);

        $this->service->processRecipient(new CampaignRecipient($campaign, $org));

        $this->assertServiceMessageStaysInAppChannel('Не удалось отправить уведомление');
    }

    /**
     * Рассылка, у которой ни одно письмо не доставлено, переходит в статус
     * «Ошибка» и пытается уведомить администратора — на этом пути пишутся
     * служебные сообщения.
     */
    private function escalateFailedCampaign(Campaign $campaign, int $id): void
    {
        $this->setId($campaign, $id);
        $this->campaigns->method('find')->with($id)->willReturn($campaign);
        $this->recipients->method('countStillProcessing')->with($id)->willReturn(0);
        $this->recipients->method('countDeliveredOrOpened')->with($id)->willReturn(0);
        $this->recipients->method('countByCampaign')->with($id)->willReturn(1);
        $this->recipients->method('countNoEmailFailures')->with($id)->willReturn(1);
    }

    /**
     * Служебное сообщение остаётся в дефолтном канале `app` и не попадает в
     * журнал отправки: в `mailer` лежат только результаты фактической отправки
     * письма получателю (D11).
     */
    private function assertServiceMessageStaysInAppChannel(string $needle): void
    {
        $appMessages = array_map(
            static fn(LogRecord $record): string => $record->message,
            $this->appHandler->getRecords(),
        );
        $mailerMessages = array_map(
            static fn(LogRecord $record): string => $record->message,
            $this->mailerHandler->getRecords(),
        );

        self::assertCount(1, $appMessages);
        self::assertStringContainsString($needle, $appMessages[0]);
        self::assertNotContains($appMessages[0], $mailerMessages);
    }

    /**
     * @return list<LogRecord>
     */
    private function mailerRecords(): array
    {
        return array_values($this->mailerHandler->getRecords());
    }

    /**
     * Формат строки, которую получит оператор: тот же LineFormatter, что у
     * обработчика канала `mailer` в приложении.
     */
    private function formatRecord(LogRecord $record): string
    {
        return (new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d\TH:i:sP',
            false,
            false,
        ))->format($record);
    }

    /**
     * Признаки того, что запись предсказывает повторную обработку (D15):
     * их в записи быть не должно ни для временной, ни для постоянной ошибки.
     *
     * @return list<string>
     */
    private function retryIndicators(LogRecord $record): array
    {
        $needle = ['retry', 'повтор', 'repeat', 'next_attempt'];

        $found = [];
        foreach (array_keys($record->context) as $key) {
            foreach ($needle as $word) {
                if (str_contains(strtolower((string) $key), $word)) {
                    $found[] = 'key: ' . $key;
                }
            }
        }
        foreach ($needle as $word) {
            if (str_contains(strtolower($this->formatRecord($record)), $word)) {
                $found[] = 'record: ' . $word;
            }
        }

        return $found;
    }

    private function createService(
        EntityManagerInterface $em,
        ?CampaignAttachmentStorage $storage = null,
    ): MailingService {
        $storage ??= new CampaignAttachmentStorage(sys_get_temp_dir());

        $twig = new Environment(
            new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'),
            ['strict_variables' => true],
        );
        $twig->addExtension(new CssInlinerExtension());
        $renderer = new CampaignEmailRenderer($twig, new CampaignTokenFiller());

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            function (string $name, array $params): string {
                $url = 'app_unsubscribe' === $name
                    ? self::BASE_URL . '/unsubscribe/' . $params['trackingToken']
                    : self::BASE_URL . '/t/' . $params['trackingToken'] . '.png';
                $this->generated[$name][] = $url;

                return $url;
            },
        );

        return new MailingService(
            $em,
            $this->mailer,
            $this->campaigns,
            $this->recipients,
            $this->users,
            $storage,
            $renderer,
            $urls,
            new Logger('app', [$this->appHandler]),
            new Logger('mailer', [$this->mailerHandler]),
            new EmailAddressMasker(),
            'user@b2b-crm.local',
            'B2B Call CRM',
        );
    }

    private function captureSentMail(): void
    {
        $this->mailer->method('send')->willReturnCallback(function (Email $email): void {
            $this->sent[] = $email;
        });
    }

    private function campaign(): Campaign
    {
        return new Campaign()
            ->setName('Акция')
            ->setSubject('Для {{organization_name}}')
            ->setPreviewText('{{greeting}}')
            ->setBody('{{greeting}}! Письмо для {{contact_name}}.')
            ->launch();
    }

    private function organization(): Organization
    {
        return new Organization()->setName('ООО Ромашка')->setIndustry('IT');
    }

    private function contact(Organization $org, string $name, ?string $email): Contact
    {
        $contact = new Contact()->setOrganization($org)->setName($name)->setEmail($email);
        $org->contacts->add($contact);

        return $contact;
    }

    private function recipient(Organization $org, ?Contact $contact = null): CampaignRecipient
    {
        return new CampaignRecipient($this->campaign(), $org, $contact);
    }

    private function setId(object $entity, int $id): void
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);
    }

    /**
     * @param Address[] $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn(Address $a): string => $a->getAddress(), $addresses));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
