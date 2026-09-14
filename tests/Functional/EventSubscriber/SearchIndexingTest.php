<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventSubscriber;

use App\Tests\DatabaseWebTestCase;

/**
 * Функциональные тесты search-indexing: заголовок X-Robots-Tag и
 * мета-тег robots для запрета индексации поисковыми системами.
 */
final class SearchIndexingTest extends DatabaseWebTestCase
{
    public function testRobotsHeaderOnLogin(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testRobotsMetaTagOnLogin(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('head meta[name="robots"][content="noindex, nofollow"]'));
    }

    public function testRobotsHeaderOnJsonEndpoint(): void
    {
        $this->client->request('GET', '/organizations/1/contacts.json');

        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }
}
