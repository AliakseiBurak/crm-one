<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\RecipientStatus;
use App\Entity\Organization;
use App\Tests\DatabaseWebTestCase;
use Psr\Log\LoggerInterface;

/**
 * Канал `mailer` в файловой системе (change email-send-logging, D4a, D14).
 *
 * В `test` канал исключён из обработчика `main`, поэтому у него собственный
 * обработчик: запись обязана попасть ровно в один файл — `test-mailer-YYYY-MM.log`
 * с префиксом `test-` — и не должна попасть ни в `test.log`, ни в
 * `mailer-YYYY-MM.log`, то есть проверка обходится без подмены
 * `mailer.log_dir`. Проверяется и то, что статус `opened` из tracking-pixel не
 * порождает записи журнала отправки.
 */
final class MailerLogChannelTest extends DatabaseWebTestCase
{
    private const string MARKER = 'mailer-channel-probe';

    public function testMailerChannelWritesOnlyItsOwnMonthlyTestFile(): void
    {
        $logDir = (string) static::getContainer()->getParameter('kernel.logs_dir');
        $testFile = $logDir . '/test-mailer-' . date('Y-m') . '.log';
        $prodFile = $logDir . '/mailer-' . date('Y-m') . '.log';

        $before = $this->readIfExists($testFile);
        $prodBefore = $this->readIfExists($prodFile);
        $appLogBefore = $this->readIfExists($logDir . '/test.log');

        $this->mailerLogger()->info('Проверка канала отправки', ['probe' => self::MARKER]);

        self::assertStringContainsString(
            self::MARKER,
            substr($this->readIfExists($testFile), \strlen($before)),
            'Запись канала mailer обязана попасть в test-mailer-YYYY-MM.log',
        );
        // Настоящий месячный файл может существовать и до теста — его создаёт
        // работающее приложение, — поэтому проверяется не отсутствие файла, а
        // отсутствие в нём записи этого вызова.
        self::assertStringNotContainsString(
            self::MARKER,
            substr($this->readIfExists($prodFile), \strlen($prodBefore)),
            'Канал mailer не должен писать в настоящий месячный файл журнала',
        );
        self::assertSame(
            $appLogBefore,
            $this->readIfExists($logDir . '/test.log'),
            'Канал mailer исключён из обработчика main, поэтому в test.log записи быть не должно',
        );
    }

    public function testOpenedStatusWritesNoSendLogRecord(): void
    {
        $logDir = (string) static::getContainer()->getParameter('kernel.logs_dir');
        $testFile = $logDir . '/test-mailer-' . date('Y-m') . '.log';
        $before = $this->readIfExists($testFile);

        $campaign = (new Campaign())
            ->setName('Акция')
            ->setSubject('Письмо')
            ->setBody('Текст')
            ->launch();
        $organization = (new Organization())->setName('ООО Ромашка');
        $contact = (new Contact())->setOrganization($organization)->setName('Алиса')->setEmail('anna@example.ru');
        $organization->contacts->add($contact);
        $recipient = (new CampaignRecipient($campaign, $organization))->markDelivered();

        $em = $this->em();
        $em->persist($campaign);
        $em->persist($organization);
        $em->persist($contact);
        $em->flush();

        self::assertNotNull($recipient->trackingToken);
        $this->client->request('GET', '/t/' . $recipient->trackingToken . '.png');

        $this->assertResponseIsSuccessful();
        self::assertSame(RecipientStatus::Opened, $recipient->status);
        self::assertSame(
            $before,
            $this->readIfExists($testFile),
            'Статус opened не относится к SMTP и в журнал отправки не попадает',
        );
    }

    private function mailerLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = static::getContainer()->get('monolog.logger.mailer');

        return $logger;
    }

    private function readIfExists(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
