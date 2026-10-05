<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Границы постраничного вывода (change organizations-pagination, spec
 * web-interface «Пагинация списков»).
 *
 * Здесь решаются две вещи, на которых держится вся пагинация: размер
 * страницы ровно 50 строк (при 50 строках страница одна и блок навигации не
 * выводится) и зажим номера страницы. Зажим важен потому, что редирект после
 * сохранения организации ведёт на произвольный список: без него таблица с
 * 137 организациями выглядела бы пустой из-за забытой закладки.
 */
final class PaginationTest extends TestCase
{
    public function testPageSizeIsFifty(): void
    {
        self::assertSame(50, Pagination::PER_PAGE);
    }

    public function testExactlyFiftyRowsIsASinglePageWithoutNavigation(): void
    {
        $pagination = new Pagination(50, Pagination::PER_PAGE, 1);

        self::assertTrue($pagination->isSinglePage);
        self::assertSame(1, $pagination->pages);
        self::assertSame(1, $pagination->page);
        self::assertSame(0, $pagination->offset);
        self::assertSame(1, $pagination->firstPage);
        self::assertSame(1, $pagination->lastPage);
    }

    public function testFiftyOneRowsStartsASecondPage(): void
    {
        $pagination = new Pagination(51, Pagination::PER_PAGE, 1);

        self::assertFalse($pagination->isSinglePage);
        self::assertSame(2, $pagination->pages);
        self::assertSame(2, $pagination->lastPage);
    }

    public function testOffsetOfTheSecondPage(): void
    {
        $pagination = new Pagination(137, Pagination::PER_PAGE, 2);

        self::assertSame(3, $pagination->pages);
        self::assertSame(2, $pagination->page);
        self::assertSame(50, $pagination->offset);
        self::assertSame(1, $pagination->firstPage);
        self::assertSame(3, $pagination->lastPage);
    }

    public function testEmptyListIsASinglePage(): void
    {
        $pagination = new Pagination(0, Pagination::PER_PAGE, 1);

        self::assertTrue($pagination->isSinglePage);
        self::assertSame(1, $pagination->pages);
        self::assertSame(0, $pagination->offset);
    }

    #[DataProvider('providePageNumberOutsideTheRangeIsClampedCases')]
    public function testPageNumberOutsideTheRangeIsClamped(int|string $requestedPage, int $expectedPage): void
    {
        $pagination = new Pagination(137, Pagination::PER_PAGE, $requestedPage);

        self::assertSame($expectedPage, $pagination->page);
        self::assertSame(($expectedPage - 1) * Pagination::PER_PAGE, $pagination->offset);
    }

    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function providePageNumberOutsideTheRangeIsClampedCases(): iterable
    {
        yield 'нулевая страница — первая' => [0, 1];
        yield 'нечисловой номер — первая' => ['abc', 1];
        yield 'мусор в номере — первая' => ['3a', 1];
        yield 'отрицательный номер — первая' => [-5, 1];
        yield 'номер за диапазоном — последняя' => [999, 3];
        yield 'номер на страницу дальше последней — последняя' => [4, 3];
        yield 'номер в диапазоне сохраняется' => [2, 2];
        yield 'последняя страница' => ['3', 3];
    }

    /**
     * Переходов «Назад»/«Вперёд» в DTO нет: соседние страницы и так видны в
     * окне номеров, поэтому дублировать их стрелками незачем.
     */
    public function testWindowOnBoundaryPagesStillReachesNeighbours(): void
    {
        $first = new Pagination(137, Pagination::PER_PAGE, 1);
        $last = new Pagination(137, Pagination::PER_PAGE, 3);

        self::assertSame([1, 2, 3], $first->window);
        self::assertSame([1, 2, 3], $last->window);
        self::assertSame(1, $first->firstPage);
        self::assertSame(3, $last->lastPage);
    }

    public function testWindowIsNotCollapsedWhileThereAreTenPages(): void
    {
        $pagination = new Pagination(500, Pagination::PER_PAGE, 5);

        self::assertSame(10, $pagination->pages);
        self::assertSame(range(1, 10), $pagination->window);
    }

    public function testWindowIsCollapsedWithEllipsesNearTheStartOfALongList(): void
    {
        $pagination = new Pagination(1370, Pagination::PER_PAGE, 1);

        self::assertSame(28, $pagination->pages);
        self::assertSame([1, 2, 3, Pagination::ELLIPSIS, 28], $pagination->window);
    }

    public function testWindowIsCollapsedWithEllipsesNearTheEndOfALongList(): void
    {
        $pagination = new Pagination(1370, Pagination::PER_PAGE, 28);

        self::assertSame([1, Pagination::ELLIPSIS, 26, 27, 28], $pagination->window);
    }

    public function testWindowAroundTheCurrentPageKeepsFirstAndLast(): void
    {
        $pagination = new Pagination(1370, Pagination::PER_PAGE, 10);

        self::assertSame([1, Pagination::ELLIPSIS, 8, 9, 10, 11, 12, Pagination::ELLIPSIS, 28], $pagination->window);
    }

    public function testWindowOfAShortListIsTheWholeList(): void
    {
        $pagination = new Pagination(137, Pagination::PER_PAGE, 2);

        self::assertSame(3, $pagination->pages);
        self::assertSame([1, 2, 3], $pagination->window);
    }

    public function testExplicitPerPageIsHonored(): void
    {
        $pagination = new Pagination(10, 4, 3);

        self::assertSame(4, $pagination->perPage);
        self::assertSame(3, $pagination->pages);
        self::assertSame(3, $pagination->page);
        self::assertSame(8, $pagination->offset);
    }
}
