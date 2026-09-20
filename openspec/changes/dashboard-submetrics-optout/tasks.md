## 1. UI: подметрики «Из письма» и удаление 4-й цифры

- [ ] 1.1 В `templates/home/index.html.twig` добавить трём цифрам блока «Отписки» поля `subFigure` (`optOutStats.optedOutTodayByEmail`, `optedOutWeekByEmail`, `optedOutMonthByEmail`) и `subCaption: 'Из письма'`; проверить `docker compose exec --user app php php bin/console lint:twig templates`
- [ ] 1.2 Удалить из блока «Отписки» 4-ю цифру «Из письма» (item с `statsByOrg['optoutEmail']`) и её ссылку «По организациям»; проверить `lint:twig` и что в секции остаётся ровно три item (проверяется тестом 3.1)
- [ ] 1.3 Добавить стиль `.stats__sub` в `assets/scss/pages/welcome.scss` рядом с `.stats__orgs`; проверить `make styles` без ошибок сборки

## 2. Репозиторий: докблок и задел под фильтрацию

- [ ] 2.1 Поправить докблок `CallRepository::ORGANIZATION_BUCKETS` («Ключи девяти категорий» → девять категорий звонков + `optoutEmail` как задел под фильтрацию), не меняя состав ключей и SQL; проверить `composer stan`

## 3. Тесты

- [ ] 3.1 Обновить `tests/Functional/Controller/HomeControllerTest.php`: проверки подметрик «Из письма» по каждому периоду (email-причина учитывается и в основной цифре, и в подметрике; другая причина — только в основной; даты отписок брать с запасом от границ окон, напр. −3 и −15 дней), отсутствие отдельной all-time цифры «Из письма», усилить/переименовать `testDashboardOptOutByEmailCount` (сейчас проверяет только наличие строки); проверить `make test`
- [ ] 3.2 Обновить `e2e/tests/home-stats.spec.ts`: ожидать 12 цифр вместо 9, поправить устаревший комментарий в шапке файла («9 показателей» → 12), проверить подметрики «Из письма» под тремя показателями отписок, отсутствие отдельной цифры «Из письма» и ссылки `filter=optoutEmail`; проверить `make e2e`

## 4. Верификация изменения

- [ ] 4.1 `openspec validate dashboard-submetrics-optout` — valid
- [ ] 4.2 `composer cs:check && composer stan` — без новых ошибок
- [ ] 4.3 `make test` — все PHPUnit-тесты зелёные
