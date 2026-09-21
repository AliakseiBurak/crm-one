## 1. UI: подметрики «Из письма» и удаление 4-й цифры

- [x] 1.1 В `templates/home/index.html.twig` добавить трём цифрам блока «Отписки» поля `subFigure` (`optOutStats.optedOutTodayByEmail`, `optedOutWeekByEmail`, `optedOutMonthByEmail`) и `subCaption: 'Из письма'`; проверить `docker compose exec --user app php php bin/console lint:twig templates`
- [x] 1.2 Удалить из блока «Отписки» 4-ю цифру «Из письма» (item с `statsByOrg['optoutEmail']`) и её ссылку «По организациям»; проверить `lint:twig` и что в секции остаётся ровно три item (проверяется тестом 3.1)
- [x] 1.3 Добавить стиль `.stats__sub` в `assets/scss/pages/welcome.scss` рядом с `.stats__orgs`; проверить `make styles` без ошибок сборки

## 2. Репозиторий: докблок и задел под фильтрацию

- [x] 2.1 Поправить докблок `CallRepository::ORGANIZATION_BUCKETS` («Ключи девяти категорий» → девять категорий звонков + `optoutEmail` как задел под фильтрацию), не меняя состав ключей и SQL; проверить `composer stan`

## 3. Тесты

- [x] 3.1 Обновить `tests/Functional/Controller/HomeControllerTest.php`: проверки подметрик «Из письма» по каждому периоду (email-причина учитывается и в основной цифре, и в подметрике; другая причина — только в основной; даты отписок брать с запасом от границ окон, напр. −3 и −15 дней), отсутствие отдельной all-time цифры «Из письма», усилить/переименовать `testDashboardOptOutByEmailCount` (сейчас проверяет только наличие строки); проверить `make test`
- [x] 3.2 Обновить `e2e/tests/home-stats.spec.ts`: ожидать 12 цифр вместо 9, поправить устаревшие комментарии («9 показателей» → 12), проверить подметрики-ссылки «Из письма» с `filter=optoutEmail1/7/30` под тремя показателями отписок, отсутствие отдельной all-time цифры «Из письма» и ссылки с точным `filter=optoutEmail`; проверить `make e2e`

## 4. Верификация изменения

- [x] 4.1 `openspec validate dashboard-submetrics-optout` — valid
- [x] 4.2 `composer cs:check && composer stan` — без новых ошибок
- [x] 4.3 `make test` — все PHPUnit-тесты зелёные

## 5. Доработка: filter `<category><days>` для подметрик

- [x] 5.1 Обновить дельту спеки и proposal: единый формат filter `<category><days>` (любое число дней, home рендерит 1/7/30), сценарии подметрик-ссылок, явные границы отписок (N календарных дней включая сегодня) и отметка расхождения с прежними границами `optOutStats()`; проверить `openspec validate dashboard-submetrics-optout --strict`
- [x] 5.2 `CallRepository::ORGANIZATION_BUCKETS`: заменить `optoutEmail` на `optoutEmail1/7/30`; в `organizationCounts()` считать три периодные категории отписок из письма с теми же границами, что `OrganizationRepository::optOutStats()`; проверить `composer stan`
- [x] 5.3 `OrganizationRepository::optOutStats()`: границы недели/месяца — N календарных дней включая сегодня (`today−6`/`today−29`), чтобы число подметрики совпадало со scope ссылки `optoutEmailN`
- [x] 5.4 `templates/home/index.html.twig`: подметрика «Из письма» — ссылка на `/dashboard?filter=optoutEmail1/7/30` (`subBucket`); `assets/scss/pages/welcome.scss`: `.stats__sub` как ссылка (hover underline); проверить `lint:twig` и `make styles`
- [x] 5.5 `tests/Functional/Controller/HomeControllerTest.php`: href'ы подметрик `optoutEmail1/7/30`, отсутствие точного `filter=optoutEmail` и `a.stats__orgs` в секции отписок; проверить `make test`
- [x] 5.6 `composer cs:check && composer stan` и `make test` — без новых ошибок, все PHPUnit-тесты зелёные
- [x] 5.7 Переименовать секцию «Отписки» → «Отписки организаций» и подписи цифр → «сегодня»/«за 7 дней»/«за 30 дней» (шаблон, дельта спеки, functional и e2e тесты); проверить `lint:twig`, `make test`
