<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Pagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Канонизация URL списка (change organizations-pagination).
 *
 * Номер страницы приводится к открытой, а пустые значения параметров
 * отбрасываются. Без этого таблица с большим набором данных выглядела бы
 * пустой из-за забытой закладки (`?page=999`), а после очистки поиска или
 * сортировки в URL оставался бы мусорный след отправки GET-формы
 * (`?sort=&dir=asc&q=`).
 *
 * Два входа: `canonicalListRedirect()` для пагинированных списков и
 * `canonicalParamsRedirect()` для списков без постраничного вывода — там
 * `page` отбрасывается как незначащий.
 *
 * Чужие параметры (например `filter` со страницы статистики) в канонический
 * URL не попадают: переход по пагинации сохраняет условия списка, а не всё
 * подряд.
 */
trait CanonicalListUrl
{
    /**
     * Канонизация URL списка, который пагинируется: номер страницы приводится к
     * открытой, пустые значения параметров отбрасываются.
     *
     * @param string[]                $queryParams имена query-параметров списка
     * @param array<string, mixed>    $routeParams параметры маршрута (id группы и т.п.)
     */
    private function canonicalListRedirect(
        Request $request,
        Pagination $pagination,
        string $route,
        array $routeParams,
        array $queryParams,
    ): ?Response {
        $requested = self::requestedListParams($request, $queryParams);

        $canonical = array_filter($requested, static fn(string $value): bool => '' !== $value);
        if ($request->query->has('page') || 1 !== $pagination->page) {
            $canonical['page'] = (string) $pagination->page;
        }

        if ($canonical === $requested) {
            return null;
        }

        return $this->redirectToRoute($route, $routeParams + $canonical);
    }

    /**
     * Канонизация URL списка, который не пагинируется (список групп, состав
     * группы): пустые значения параметров отбрасываются, а сам `page` не
     * значит здесь ничего и тоже отбрасывается — `/groups?page=999` открывает
     * `/groups`, а не страницу под номером 999.
     *
     * @param string[]                $queryParams имена query-параметров списка
     * @param array<string, mixed>    $routeParams параметры маршрута (id группы и т.п.)
     */
    private function canonicalParamsRedirect(
        Request $request,
        string $route,
        array $routeParams,
        array $queryParams,
    ): ?Response {
        $requested = self::requestedListParams($request, $queryParams);

        // `page` не входит в условия списка, который не пагинируется, поэтому
        // в канонический URL не возвращается: `/groups?page=999` → `/groups`.
        $canonical = array_filter($requested, static fn(string $value): bool => '' !== $value);
        unset($canonical['page']);

        if ($canonical === $requested) {
            return null;
        }

        return $this->redirectToRoute($route, $routeParams + $canonical);
    }

    /**
     * Параметры списка ровно в том виде, в каком они пришли в URL, включая
     * пустые значения: с ними сравнивается канонический URL.
     *
     * @param string[] $queryParams
     *
     * @return array<string, string>
     */
    private static function requestedListParams(Request $request, array $queryParams): array
    {
        $params = [];
        foreach ([...$queryParams, 'page'] as $name) {
            if ($request->query->has($name)) {
                $params[$name] = (string) $request->query->get($name);
            }
        }

        return $params;
    }
}
