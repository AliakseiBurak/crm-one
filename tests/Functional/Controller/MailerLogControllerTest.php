<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Service\MailerLogFileLister;
use App\Tests\DatabaseWebTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Функциональные тесты страницы файлов журнала отправки (change
 * email-send-logging, D8–D9): доступ администратора, перечень с размерами и
 * порядком, скачивание месяца и архива года, отказ по несуществующему и
 * невалидному периоду. Каталог журнала подменяется на временный, поэтому тест
 * не зависит от реальных файлов в var/log.
 */
final class MailerLogControllerTest extends DatabaseWebTestCase
{
    private const string SECRET_LINE = 'mailer.INFO: Campaign send delivered {"recipient":"a***@example.ru"}';

    private string $logDir;

    /** Записи дефолтного канала `app`: туда пишется и удаление файла. */
    private TestHandler $appHandler;

    /** Записи канала `mailer`: туда попадают только отправки. */
    private TestHandler $mailerHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/mailer-log-page-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->logDir, 0777, true));
        // Каталог журнала подменяется на временный: тест не должен зависеть
        // от реальных файлов в var/log. Параметр `mailer.log_dir` в
        // скомпилированном контейнере менять нельзя (ParameterBag заморожен),
        // поэтому подставляется сервис перечня с нужным каталогом.
        static::getContainer()->set(MailerLogFileLister::class, new MailerLogFileLister($this->logDir));

        $this->appHandler = new TestHandler();
        $this->mailerHandler = new TestHandler();
        static::getContainer()->set(LoggerInterface::class, new Logger('app', [$this->appHandler]));
        static::getContainer()->set('monolog.logger.mailer', new Logger('mailer', [$this->mailerHandler]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ((array) scandir($this->logDir) as $entry) {
            if ('.' !== $entry && '..' !== $entry && \is_string($entry)) {
                @unlink($this->logDir . '/' . $entry);
            }
        }
        @rmdir($this->logDir);
    }

    public function testAdminSeesListOfFilesWithSizesNewestFirst(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', str_repeat('x', 1024));
        $this->writeLog('mailer-2026-03.log', '');
        $this->writeLog('mailer-2025-12.log', 'y');

        $crawler = $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseIsSuccessful();

        self::assertSame(
            ['2026-11', '2026-03', '2025-12'],
            $crawler->filter('tr[data-mailer-log-row]')->each(
                static fn(Crawler $row): string => (string) $row->attr('data-mailer-log-row'),
            ),
        );
        // Размер у каждой позиции, пустой файл показывается как `0 Б`.
        $this->assertSelectorTextContains('tr[data-mailer-log-row="2026-11"] .mailer-logs__size', '1.0 КБ');
        $this->assertSelectorTextContains('tr[data-mailer-log-row="2026-03"] .mailer-logs__size', '0 Б');
        self::assertSame(3, $crawler->filter('tr[data-mailer-log-row] a[href$="/download"]')->count());
    }

    public function testYearArchiveGoesAfterMonthsOfSameYear(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026.zip', 'zip');
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-2026-01.log', 'x');
        $this->writeLog('mailer-2025.zip', 'zip');

        $crawler = $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseIsSuccessful();

        self::assertSame(
            ['2026-11', '2026-01', '2026', '2025'],
            $crawler->filter('tr[data-mailer-log-row]')->each(
                static fn(Crawler $row): string => (string) $row->attr('data-mailer-log-row'),
            ),
        );
        $this->assertSelectorTextContains('tr[data-mailer-log-row="2026"]', 'архив года');
    }

    public function testEmptyDirectoryShowsMessageAboutMissingFiles(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorTextContains('body', 'Файлов журнала отправки писем нет.');
        self::assertSame(0, $this->client->getCrawler()->filter('tr[data-mailer-log-row]')->count());
    }

    public function testPageDoesNotContainLogRecords(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', self::SECRET_LINE . "\n");

        $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseIsSuccessful();

        self::assertStringNotContainsString(self::SECRET_LINE, $this->client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Campaign send delivered', $this->client->getResponse()->getContent() ?: '');
    }

    public function testDownloadsMonthFileAsPlainTextAttachment(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', self::SECRET_LINE . "\n");

        $this->client->request('GET', '/admin/mailer-logs/month/2026-11/download');
        $this->assertResponseIsSuccessful();

        self::assertSame(self::SECRET_LINE . "\n", $this->streamedContent());
        // Symfony дописывает кодировку к text/*: тип содержимого остаётся
        // text/plain, а браузеру запрещено определять его самостоятельно.
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString(
            'attachment; filename=mailer-2026-11.log',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testDownloadsYearArchiveAsPlainTextAttachment(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026.zip', 'PK zip bytes');

        $this->client->request('GET', '/admin/mailer-logs/year/2026/download');
        $this->assertResponseIsSuccessful();

        self::assertSame('PK zip bytes', $this->streamedContent());
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString(
            'attachment; filename=mailer-2026.zip',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testDownloadRejectsUnknownAndInvalidPeriods(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');

        $this->client->request('GET', '/admin/mailer-logs/month/2026-12/download');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/month/not-a-month/download');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/year/2025/download');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/year/2026-11/download');
        $this->assertResponseStatusCodeSame(404);

        // Обход каталога невозможен по построению: путь собирается из
        // проверенного периода.
        $this->client->request('GET', '/admin/mailer-logs/month/..%2F..%2Fetc%2Fpasswd/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testManagerHasNoAccessToLogFilesPage(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));

        $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/mailer-logs/month/2026-11/download');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEveryPositionHasDeleteExceptCurrentMonth(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-' . date('Y-m') . '.log', 'current');
        $this->writeLog('mailer-2026.zip', 'zip');

        $crawler = $this->client->request('GET', '/admin/mailer-logs');
        $this->assertResponseIsSuccessful();

        // Надпись у обоих видов одна — «Удалить»: период, вид и имя файла
        // стоят в своих колонках строки.
        $this->assertSelectorTextSame(
            'tr[data-mailer-log-row="2026-11"] .mailer-logs__delete',
            'Удалить',
        );
        $this->assertSelectorTextSame(
            'tr[data-mailer-log-row="2026"] .mailer-logs__delete',
            'Удалить',
        );
        // Кнопка ведёт на подтверждение удаления своего вида файла.
        $this->assertSelectorExists(
            'tr[data-mailer-log-row="2026-11"] a[href="/admin/mailer-logs/month/2026-11/delete"]',
        );
        $this->assertSelectorExists(
            'tr[data-mailer-log-row="2026"] a[href="/admin/mailer-logs/year/2026/delete"]',
        );
        self::assertSame(
            0,
            $crawler->filter('tr[data-mailer-log-row="' . date('Y-m') . '"] .mailer-logs__delete')->count(),
            'У файла текущего месяца элемент удаления не отображается',
        );
        // Файл текущего месяца остаётся в перечне.
        self::assertSame(
            1,
            $crawler->filter('tr[data-mailer-log-row="' . date('Y-m') . '"]')->count(),
        );
    }

    public function testConfirmationNamesFileAndStatesOthersAreKept(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-2026.zip', 'zip');

        $this->client->request('GET', '/admin/mailer-logs/month/2026-11/delete');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'mailer-2026-11.log');
        $this->assertSelectorTextContains('body', 'Остальные файлы журнала отправки');
        self::assertSame('mailer-2026-11.log', $this->client->getCrawler()->filter('strong')->text());
        // Файл до подтверждения на месте.
        self::assertFileExists($this->logDir . '/mailer-2026-11.log');

        $this->client->request('GET', '/admin/mailer-logs/year/2026/delete');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'mailer-2026.zip');
        $this->assertSelectorTextContains('body', 'mailer-2026-*.log');
    }

    public function testDeletesMonthFileAfterConfirmationAndKeepsOthers(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-2026.zip', 'zip');
        $this->writeLog('mailer-2025-12.log', 'x');

        $this->submitDelete('/admin/mailer-logs/month/2026-11/delete');

        $this->assertResponseRedirects('/admin/mailer-logs');
        self::assertFileDoesNotExist($this->logDir . '/mailer-2026-11.log');
        // Архив года и другие месяцы не тронуты.
        self::assertFileExists($this->logDir . '/mailer-2026.zip');
        self::assertFileExists($this->logDir . '/mailer-2025-12.log');

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['2026', '2025-12'],
            $crawler->filter('tr[data-mailer-log-row]')->each(
                static fn(Crawler $row): string => (string) $row->attr('data-mailer-log-row'),
            ),
        );
        $this->assertSelectorTextContains('.alert--success', 'Файл журнала 2026-11 удалён.');
    }

    public function testDeletesYearArchiveAndKeepsMonthsOfSameYear(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026.zip', 'zip');
        $this->writeLog('mailer-2026-05.log', 'x');

        $this->submitDelete('/admin/mailer-logs/year/2026/delete');

        $this->assertResponseRedirects('/admin/mailer-logs');
        self::assertFileDoesNotExist($this->logDir . '/mailer-2026.zip');
        self::assertFileExists($this->logDir . '/mailer-2026-05.log');

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['2026-05'],
            $crawler->filter('tr[data-mailer-log-row]')->each(
                static fn(Crawler $row): string => (string) $row->attr('data-mailer-log-row'),
            ),
        );
    }

    public function testDeletingMonthDoesNotPurgeItFromExistingYearArchive(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026.zip', 'PK zip bytes');
        $this->writeLog('mailer-2026-05.log', 'x');

        $this->submitDelete('/admin/mailer-logs/month/2026-05/delete');

        $this->assertResponseRedirects('/admin/mailer-logs');
        // Удаление месяца не вычищает этот месяц из уже существующего архива
        // года: там он остаётся (D17).
        self::assertFileExists($this->logDir . '/mailer-2026.zip');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('tr[data-mailer-log-row="2026"]');
    }

    public function testCurrentMonthIsRejectedOnConfirmationAndRemoval(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-' . date('Y-m') . '.log', 'current');

        $this->client->request('GET', '/admin/mailer-logs/month/' . date('Y-m') . '/delete');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/mailer-logs/month/' . date('Y-m') . '/delete', [
            '_csrf_token' => 'any-token',
        ]);
        $this->assertResponseStatusCodeSame(404);

        self::assertFileExists($this->logDir . '/mailer-' . date('Y-m') . '.log');
    }

    public function testRejectsUnknownAndInvalidDeletePeriods(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-2026.zip', 'zip');

        $this->client->request('GET', '/admin/mailer-logs/month/2026-12/delete');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/month/2026-1/delete');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/year/2025/delete');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/mailer-logs/year/2026-11/delete');
        $this->assertResponseStatusCodeSame(404);

        // Ничего не удалено и по POST с валидным периодом и валидным
        // CSRF-токеном, но без файла.
        $this->client->request('GET', '/admin/mailer-logs/year/2026/delete');
        $token = $this->csrfTokenFromForm();
        $this->client->request('POST', '/admin/mailer-logs/year/2025/delete', ['_csrf_token' => $token]);
        $this->assertResponseStatusCodeSame(404);

        self::assertFileExists($this->logDir . '/mailer-2026-11.log');
        self::assertFileExists($this->logDir . '/mailer-2026.zip');
    }

    public function testRejectsRemovalWithInvalidCsrfToken(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-2026-11.log', 'x');

        $this->client->request('POST', '/admin/mailer-logs/month/2026-11/delete', [
            '_csrf_token' => 'wrong-token',
        ]);

        $this->assertResponseStatusCodeSame(403);
        self::assertFileExists($this->logDir . '/mailer-2026-11.log');
    }

    public function testManagerCannotDeleteLogFiles(): void
    {
        $this->login($this->makeUser('manager', 'manager@b2b-crm.loc', UserRole::Manager));
        $this->writeLog('mailer-2026-11.log', 'x');

        $this->client->request('GET', '/admin/mailer-logs/month/2026-11/delete');
        $this->assertResponseStatusCodeSame(403);

        // Роль проверяется до тела маршрута, поэтому токен здесь любой.
        $this->client->request('POST', '/admin/mailer-logs/month/2026-11/delete', ['_csrf_token' => 'any-token']);
        $this->assertResponseStatusCodeSame(403);

        self::assertFileExists($this->logDir . '/mailer-2026-11.log');
    }

    /**
     * Запись об удалении — единственный след действия во всей системе, и он
     * принадлежит общему журналу: служебная запись в `mailer-*.log` нарушила бы
     * требование «в журнале только результаты фактической отправки» (D11, D18).
     */
    public function testDeletionIsLoggedToAppChannelOnly(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->login($admin);
        $this->writeLog('mailer-2026-11.log', 'x');
        $this->writeLog('mailer-2026.zip', 'zip');

        $this->submitDelete('/admin/mailer-logs/month/2026-11/delete');

        $this->assertResponseRedirects('/admin/mailer-logs');
        // Контроль подмены: если канал `mailer` в контейнере действительно
        // подменён, пробная запись в него доходит до обработчика — значит
        // пустой перечень записей после удаления не пуст «всегда».
        $this->mailerLogger()->info('проба канала mailer');
        $mailerRecords = array_values($this->mailerHandler->getRecords());
        self::assertCount(1, $mailerRecords);
        self::assertSame('проба канала mailer', $mailerRecords[0]->message);
        self::assertNotContains(
            'Файл журнала отправки удалён: {kind} {period} ({filename}) администратором {login}',
            array_map(static fn($record): string => $record->message, $mailerRecords),
            'В журнал отправки запись об удалении попасть не должна',
        );

        $records = array_values($this->appHandler->getRecords());
        self::assertCount(1, $records);
        self::assertSame('Файл журнала отправки удалён: {kind} {period} ({filename}) администратором {login}', $records[0]->message);
        self::assertSame([
            'kind' => 'month',
            'period' => '2026-11',
            'filename' => 'mailer-2026-11.log',
            'user_id' => $admin->id,
            'login' => 'admin',
        ], $records[0]->context);
    }

    public function testYearArchiveDeletionIsLoggedToAppChannel(): void
    {
        $admin = $this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin);
        $this->login($admin);
        $this->writeLog('mailer-2026.zip', 'zip');

        $this->submitDelete('/admin/mailer-logs/year/2026/delete');

        $this->assertResponseRedirects('/admin/mailer-logs');
        self::assertSame([], $this->mailerHandler->getRecords());

        $records = array_values($this->appHandler->getRecords());
        self::assertCount(1, $records);
        self::assertSame('year', $records[0]->context['kind']);
        self::assertSame('2026', $records[0]->context['period']);
        self::assertSame('mailer-2026.zip', $records[0]->context['filename']);
        self::assertSame($admin->id, $records[0]->context['user_id']);
    }

    /**
     * Отказ по текущему месяцу удалением не является, поэтому в канале `app`
     * он не фиксируется (D18).
     */
    public function testCurrentMonthRejectionIsNotLoggedAsDeletion(): void
    {
        $this->loginAdmin();
        $this->writeLog('mailer-' . date('Y-m') . '.log', 'current');

        $this->client->request('GET', '/admin/mailer-logs/month/' . date('Y-m') . '/delete');
        $this->assertResponseStatusCodeSame(404);

        self::assertSame([], $this->appHandler->getRecords());
        self::assertSame([], $this->mailerHandler->getRecords());
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/mailer-logs');

        $this->assertResponseRedirects('/login');
    }

    private function loginAdmin(): void
    {
        $this->login($this->makeUser('admin', 'admin@b2b-crm.loc', UserRole::Admin));
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = (new User())->setLogin($login)->setEmail($email)->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * Подтверждение открывает страницу с CSRF-токеном, затем форма
     * отправляется POST-ом на маршрут удаления.
     */
    private function submitDelete(string $confirmationUrl): void
    {
        $this->client->request('GET', $confirmationUrl);
        $this->assertResponseIsSuccessful();

        $this->client->submit($this->client->getCrawler()->filter('form')->first()->form());
    }

    private function mailerLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = static::getContainer()->get('monolog.logger.mailer');

        return $logger;
    }

    private function csrfTokenFromForm(): string
    {
        return (string) $this->client->getCrawler()
            ->filter('input[name="_csrf_token"]')
            ->attr('value');
    }

    /**
     * BinaryFileResponse отдаёт файл потоком, поэтому тело ответа читается
     * отправкой содержимого, а не через getContent().
     */
    private function streamedContent(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    private function writeLog(string $name, string $contents): void
    {
        file_put_contents($this->logDir . '/' . $name, $contents);
    }
}
