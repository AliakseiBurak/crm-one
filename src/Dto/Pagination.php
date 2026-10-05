<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Границы постраничного вывода списка (change organizations-pagination).
 *
 * Единственное место, где считаются номер страницы, число страниц и окно
 * номеров: контроллер разбирает `page`, репозиторий режет выборку, макрос
 * components/pagination.html.twig только рисует то, что здесь посчитано.
 *
 * Номер страницы зажимается: нечисловое значение и `0` открывают первую
 * страницу, значение сверх диапазона — последнюю существующую. Пустые даты
 * в списках сортируются последними при любом направлении, поэтому страница
 * с большим номером всегда непустая, пока в выборке есть хоть одна строка.
 */
final readonly class Pagination
{
    /**
     * Размер страницы для всех пагинируемых списков (web-interface:
     * «Пагинация списков»). Пока строк не больше PER_PAGE, страница одна и
     * блок навигации не рендерится.
     */
    public const int PER_PAGE = 50;

    /**
     * Пока страниц не больше этого числа, окно номеров не сворачивается
     * многоточиями — весь список виден целиком.
     */
    private const int FULL_WINDOW_PAGES = 10;

    /**
     * Сколько номеров показывать с каждой стороны от текущей страницы.
     */
    private const int WINDOW_RADIUS = 2;

    /**
     * Многоточие в окне номеров.
     */
    public const string ELLIPSIS = '…';

    public int $perPage;

    public int $pages;

    public int $page;

    public int $offset;

    public bool $isSinglePage;

    /**
     * Номера страниц вокруг текущей с многоточиями по краям.
     *
     * @var list<int|string>
     */
    public array $window;

    public int $firstPage;

    public int $lastPage;

    /**
     * @param int         $total         всего строк выборки (уже по области доступа и фильтрам)
     * @param int         $perPage       размер страницы
     * @param int|string  $requestedPage запрошенный номер страницы: строка из query-параметра
     */
    public function __construct(
        public int $total,
        int $perPage = self::PER_PAGE,
        int|string $requestedPage = 1,
    ) {
        $this->perPage = max(1, $perPage);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = self::clamp($requestedPage, $this->pages);
        $this->offset = ($this->page - 1) * $this->perPage;
        $this->isSinglePage = $this->pages <= 1;
        $this->window = self::buildWindow($this->page, $this->pages);
        $this->firstPage = 1;
        $this->lastPage = $this->pages;
    }

    /**
     * Нечисловое и нулевое значение открывают первую страницу, значение
     * сверх диапазона — последнюю существующую.
     */
    private static function clamp(int|string $requestedPage, int $pages): int
    {
        if (!\is_int($requestedPage) && !is_numeric($requestedPage)) {
            return 1;
        }

        $requested = (int) $requestedPage;
        if ($requested < 1) {
            return 1;
        }

        return min($requested, $pages);
    }

    /**
     * @return list<int|string>
     */
    private static function buildWindow(int $page, int $pages): array
    {
        if ($pages <= self::FULL_WINDOW_PAGES) {
            return range(1, $pages);
        }

        $window = [1];

        $from = max(2, $page - self::WINDOW_RADIUS);
        $to = min($pages - 1, $page + self::WINDOW_RADIUS);

        if ($from > 2) {
            $window[] = self::ELLIPSIS;
        }
        for ($number = $from; $number <= $to; ++$number) {
            $window[] = $number;
        }
        if ($to < $pages - 1) {
            $window[] = self::ELLIPSIS;
        }
        $window[] = $pages;

        return $window;
    }
}
