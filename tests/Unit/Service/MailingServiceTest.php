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
use App\Service\MailingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        self::assertSame('Для ООО Ромашка', $this->sent[0]->getSubject());
        $html = (string) $this->sent[0]->getHtmlBody();
        self::assertStringContainsString('Уважаемый(ая) Алиса', $html);
        self::assertStringContainsString('mso-hide:all', $html);
        self::assertStringContainsString($this->generated['app_tracking_pixel'][0], $html);
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
        $admin = new User()->setEmail('admin@b2b-crm.loc');
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

    private function createService(
        EntityManagerInterface $em,
        ?CampaignAttachmentStorage $storage = null,
    ): MailingService {
        $storage ??= new CampaignAttachmentStorage(sys_get_temp_dir());

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
            $urls,
            new NullLogger(),
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
