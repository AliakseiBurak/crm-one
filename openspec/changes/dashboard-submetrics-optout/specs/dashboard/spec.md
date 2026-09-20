## MODIFIED Requirements

### Requirement: Индикаторы статистики по организациям
The system SHALL render on the dashboard twelve statistics figures: nine call
figures and three optout figures. Under each of the nine call figures the
system SHALL render a «По организациям: N» indicator link, where N SHALL be the
number of distinct organizations of the user's access scope having calls of
that figure's category. For the called figures the category SHALL be determined
by the call date (`made_at`), for the waiting figures — by the scheduled date
(`scheduled_at`), within the same periods as the figure. For the overdue
figures the category SHALL be determined by the scheduled date (`scheduled_at`)
where `made_at IS NULL`, within the same periods as the figure. The indicator
SHALL be rendered as an anchor element linking to the organizations panel with
a `filter` query parameter identifying the bucket. The indicator SHALL be
rendered even when N equals zero. The three optout figures SHALL NOT have
by-organization indicators.

#### Scenario: Индикатор по организациям под показателем
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** под каждым из девяти показателей звонков отображается ссылка «По организациям: N»
- **AND** в качестве N отображается число уникальных организаций области доступа со звонками этой категории
- **AND** ссылка содержит параметр `filter` с ключом категории

#### Scenario: Пустая категория
- **WHEN** в области доступа пользователя нет организаций со звонками категории
- **AND** пользователь открывает домашнюю страницу
- **THEN** под показателем отображается «По организациям: 0»
- **AND** ссылка присутствует и ведёт на панель организаций с параметром filter

#### Scenario: Навигация на панель организаций
- **WHEN** пользователь кликает по индикатору категории
- **THEN** происходит переход на `/dashboard?filter=<bucket>`, где `<bucket>` — ключ категории (called1, called7, called30, waiting1, waiting7, waiting30, overdue1, overdue7, overdue30)

#### Scenario: У показателей отписок нет индикатора «По организациям»
- **WHEN** пользователь открывает домашнюю страницу
- **THEN** под показателями «Отписки: сегодня», «Отписки: 7 дней» и «Отписки: за 30 дней» ссылки «По организациям» не отображаются

### Requirement: Статистика отписок на дашборде
The system SHALL show an «Отписки» block on the dashboard (home page) with
three time-period metrics computed from Organization data: «Отписки: сегодня»
(organizations whose optedOutAt falls within today), «Отписки: 7 дней»
(optedOutAt within the last 7 days), and «Отписки: за 30 дней» (optedOutAt
within the last 30 days). Each time-period metric SHALL display a sub-metric
«Из письма» counting organizations with isOptedOut = true, optedOutAt within
the same period, and optOutReason = «Отписка из письма» (opted out via the
campaign email unsubscribe link). The block SHALL render exactly these three
figures: a separate all-time «Из письма» figure SHALL NOT be rendered. The
metrics and their sub-metrics SHALL respect the user access scope (ADR-0007/0008,
ADR-0011): an administrator SHALL see all organizations, a manager SHALL see
only organizations in their access scope. Metrics SHALL be computed from data,
not hardcoded.

#### Scenario: Отписки за сегодня
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся сегодня
- **THEN** показатель «Отписки: сегодня» учитывает эту организацию

#### Scenario: Отписки за 7 дней
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся в последние 7 дней
- **THEN** показатель «Отписки: 7 дней» учитывает эту организацию

#### Scenario: Отписки за 30 дней
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** в его области доступа есть организация, отписавшаяся в последние 30 дней
- **THEN** показатель «Отписки: за 30 дней» учитывает эту организацию

#### Scenario: Подметрика «Из письма» под каждым периодом
- **WHEN** пользователь открывает главную страницу дашборда
- **THEN** под показателем «Отписки: сегодня» отображается подметрика «Из письма: N»
- **AND** под показателем «Отписки: 7 дней» отображается подметрика «Из письма: N»
- **AND** под показателем «Отписки: за 30 дней» отображается подметрика «Из письма: N»

#### Scenario: Отписка из письма учитывается в подметрике «Из письма»
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация отписалась по ссылке из письма (optOutReason «Отписка из письма»)
- **AND** optedOutAt попадает в период (сегодня / 7 дней / 30 дней)
- **THEN** подметрика «Из письма» соответствующего периода учитывает эту организацию

#### Scenario: Отписка не по ссылке не учитывается в подметрике «Из письма»
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация отписалась с другой причиной (optOutReason ≠ «Отписка из письма»)
- **THEN** подметрика «Из письма» не учитывает эту организацию
- **AND** основная метрика периода учитывает её

#### Scenario: Отдельной цифры «Из письма» нет
- **WHEN** пользователь открывает главную страницу дашборда
- **THEN** в блоке «Отписки» отображаются ровно три цифры: «Отписки: сегодня», «Отписки: 7 дней» и «Отписки: за 30 дней»
- **AND** отдельная цифра «Из письма» (без периода) не отображается

#### Scenario: Администратор видит отписки всех организаций
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация с isOptedOut = true существует в системе
- **THEN** показатели блока «Отписки» вычисляются по всем организациям системы

#### Scenario: Менеджер не видит отписки вне своей области доступа
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** организация с isOptedOut = true находится вне его области доступа
- **THEN** показатели блока «Отписки» не учитывают эту организацию

### Requirement: Статистика на домашней странице
The system SHALL render the total organizations card, the dashboard statistics
in four sections and the by-organization indicators on the home page (`/`)
below the hero banner for authenticated users. The four sections SHALL be
«Звонков», «Ожидают», «Просроченные» and «Отписки», twelve figures in total:
nine call figures and three optout figures, each optout figure with the
sub-metric «Из письма». The by-organization indicators SHALL be rendered under
the nine call figures. The statistics SHALL NOT be rendered on the home page
for guests (unauthenticated visitors). The organizations panel (`/dashboard`)
SHALL NOT duplicate the statistics figures; it SHALL only render the
organizations table. After login, the user SHALL be redirected to the home page
(`/`) where statistics are visible immediately.

#### Scenario: Вошедший пользователь видит статистику на домашней
- **WHEN** вошедший пользователь открывает домашнюю страницу `/`
- **THEN** под hero-баннером отображается карточка «Доступно организаций: Y»
- **AND** под карточкой отображаются четыре секции статистики: «Звонков», «Ожидают», «Просроченные» и «Отписки»
- **AND** в секциях отображается двенадцать показателей
- **AND** под девятью показателями звонков отображается индикатор «По организациям: N»
- **AND** под тремя показателями отписок отображается подметрика «Из письма: N»

#### Scenario: Гость не видит статистику
- **WHEN** неаутентифицированный посетитель открывает домашнюю страницу `/`
- **THEN** отображается только hero-баннер
- **AND** карточка и секции статистики не отображаются

#### Scenario: Редирект после логина
- **WHEN** пользователь проходит аутентификацию
- **THEN** происходит редирект на домашнюю страницу `/`
- **AND** на домашней странице отображается карточка и статистика

#### Scenario: Таблица организаций не дублируется
- **WHEN** пользователь открывает `/dashboard`
- **THEN** отображается таблица организаций с поиском и сортировкой
- **AND** карточка и секции статистики (двенадцать показателей) НЕ отображаются на `/dashboard`
