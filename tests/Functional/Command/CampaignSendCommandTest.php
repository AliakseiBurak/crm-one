<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Фоновая команда app:campaign:send: wiring LockFactory и пустой прогон.
 */
final class CampaignSendCommandTest extends DatabaseWebTestCase
{
    public function testIdleRunSucceeds(): void
    {
        $application = new Application($this->client->getKernel());
        $command = $application->find('app:campaign:send');
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testLaunchedCampaignIsSentAndRecipientStatusIsPersisted(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (Email $email) use (&$sent): void {
                $sent[] = $email;
            },
        );
        static::getContainer()->set(MailerInterface::class, $mailer);

        $campaign = new Campaign()
            ->setName('Запущенная рассылка')
            ->setSubject('Предложение для {{organization_name}}')
            ->setBody('{{greeting}}!')
            ->launch();
        $organization = new Organization()
            ->setName('ООО Ромашка')
            ->setIndustry('IT');
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName('Алиса')
            ->setEmail('alice@example.ru');
        $recipient = new CampaignRecipient($campaign, $organization, $contact);

        $this->em()->persist($campaign);
        $this->em()->persist($organization);
        $this->em()->persist($contact);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $recipientId = $recipient->id;

        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:campaign:send'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em()->clear();
        $persisted = $this->em()->find(CampaignRecipient::class, $recipientId);
        self::assertNotNull($persisted);
        self::assertSame(RecipientStatus::Delivered, $persisted->status);
        self::assertSame(CampaignStatus::Launched, $persisted->campaign->status);
        self::assertCount(1, $sent);
        self::assertSame('Предложение для ООО Ромашка', $sent[0]->getSubject());
        self::assertStringContainsString('Обработано получателей: 1', $tester->getDisplay());
    }

    public function testDueTransientFailureIsRetriedByTheWorker(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (Email $email) use (&$sent): void {
                $sent[] = $email;
            },
        );
        static::getContainer()->set(MailerInterface::class, $mailer);

        $campaign = new Campaign()
            ->setName('Повторная попытка')
            ->setSubject('Повтор')
            ->setBody('{{greeting}}!')
            ->launch();
        $organization = new Organization()
            ->setName('ООО Повтор')
            ->setIndustry('IT');
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName('Борис')
            ->setEmail('boris@example.ru');
        $recipient = new CampaignRecipient($campaign, $organization, $contact);
        $recipient->markFailed('Connection timed out');
        new \ReflectionProperty($recipient, 'retryAt')
            ->setValue($recipient, new \DateTimeImmutable('-1 minute'));

        $this->em()->persist($campaign);
        $this->em()->persist($organization);
        $this->em()->persist($contact);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $recipientId = $recipient->id;

        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:campaign:send'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em()->clear();
        $persisted = $this->em()->find(CampaignRecipient::class, $recipientId);
        self::assertNotNull($persisted);
        self::assertSame(RecipientStatus::Delivered, $persisted->status);
        self::assertSame(1, $persisted->retryCount);
        self::assertCount(1, $sent);
    }

    public function testOptedOutOrganizationResultsInFailedRecipient(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (Email $email) use (&$sent): void {
                $sent[] = $email;
            },
        );
        static::getContainer()->set(MailerInterface::class, $mailer);

        $campaign = new Campaign()
            ->setName('Рассылка отписанным')
            ->setSubject('Предложение')
            ->setBody('{{greeting}}!')
            ->launch();
        $organization = new Organization()
            ->setName('ООО Отписана')
            ->setIndustry('IT');
        $organization->setIsOptedOut(true);
        $contact = new Contact()
            ->setOrganization($organization)
            ->setName('Ольга')
            ->setEmail('olga@example.ru');
        $recipient = new CampaignRecipient($campaign, $organization, $contact);

        $this->em()->persist($campaign);
        $this->em()->persist($organization);
        $this->em()->persist($contact);
        $this->em()->persist($recipient);
        $this->em()->flush();
        $recipientId = $recipient->id;

        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:campaign:send'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em()->clear();
        $persisted = $this->em()->find(CampaignRecipient::class, $recipientId);
        self::assertNotNull($persisted);
        self::assertSame(RecipientStatus::Failed, $persisted->status);
        self::assertSame('Организация отписана от рассылок', $persisted->errorMessage);
        self::assertCount(0, $sent);
    }
}
