<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * База функциональных тестов: SQLite в памяти (.env.test), схема создаётся
 * из метаданных Doctrine (SchemaTool), вход — loginUser().
 *
 * Тот же набор можно гнать на MySQL (DATABASE_URL через внешний bootstrap):
 * тогда схема приходит миграциями, а таблицы очищаются перед каждым тестом,
 * что даёт ту же изоляцию, что и свежая in-memory база на SQLite. Такую базу
 * после прогона нужно перезагрузить фикстурами.
 */
abstract class DatabaseWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Без этого клиент перезагружает ядро после каждого запроса: новое
        // соединение SQLite :memory: остаётся без созданной схемы.
        $this->client->disableReboot();

        $em = $this->em();
        $connection = $em->getConnection();

        // SQLite по умолчанию не включает внешние ключи: без PRAGMA каскадное
        // удаление контактов (ON DELETE CASCADE) не срабатывает. На MySQL
        // внешние ключи включены всегда, а PRAGMA — синтаксическая ошибка,
        // поэтому набор тестов гоняется и на обеих БД.
        //
        // Схему создаёт SchemaTool только для in-memory SQLite: в MySQL она
        // приходит миграциями, а updateSchema() пытается привести её к
        // метаданным (там id — bigint, в метаданных int) и падает на
        // существующем внешнем ключе.
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->truncateAllTables($connection);

            return;
        }

        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $schemaTool = new SchemaTool($em);
        // updateSchema идемпотентен: создаёт схему в пустой in-memory БД.
        $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Чистая база перед каждым тестом на MySQL: аналог свежей in-memory базы
     * на SQLite, без которой тесты видят данные предыдущих.
     */
    private function truncateAllTables(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $schema = (string) $connection->getDatabase();

        $tables = $connection->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\'',
            [$schema],
        );

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $connection->executeStatement($platform->getTruncateTableSQL($table, true));
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    /**
     * Открывает страницу с формой организации перед отправкой.
     */
    protected function open(string $url): Crawler
    {
        return $this->client->request('GET', $url);
    }

    /**
     * Отправляет форму страницы кнопкой (CSRF-токен берётся из разметки).
     *
     * Кнопка ищется только внутри форм, чтобы не конфликтовать с кнопками
     * шапки («Создать», «⚙ Админ»), которые лежат вне форм.
     *
     * @param array<string, string> $fields
     */
    protected function submitFormByButton(string $buttonText, array $fields): void
    {
        $buttons = $this->client->getCrawler()->filter('form button');
        $match = $buttons->reduce(
            static fn(Crawler $button) => trim($button->text()) === trim($buttonText),
        );

        if ($match->count() === 0) {
            throw new \LogicException(\sprintf('Форма с кнопкой «%s» не найдена.', $buttonText));
        }

        $this->client->submit($match->first()->form(), $fields);
    }

    /**
     * AJAX-POST формы организации с CSRF-токеном открытой страницы.
     *
     * @param array<string, string> $fields
     */
    protected function submitOrganizationAjax(string $url, string $tokenPageUrl, array $fields): void
    {
        $token = $this->open($tokenPageUrl)->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request(
            'POST',
            $url,
            $fields + ['_csrf_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    protected function login(User $user): void
    {
        $this->client->loginUser($user);
    }
}
