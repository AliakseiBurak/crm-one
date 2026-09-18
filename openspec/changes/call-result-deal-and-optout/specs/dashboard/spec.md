## ADDED Requirements

### Requirement: Статистика отписок на дашборде
The system SHALL show an «Отписки» block on the dashboard (home page) with three time-period metrics computed from Organization data: «Отписки: сегодня» (organizations whose optedOutAt falls within today), «Отписки: 7 дней» (optedOutAt within the last 7 days), and «Отписки: за 30 дней» (optedOutAt within the last 30 days). Each time-period metric SHALL also display a sub-metric «Из письма» counting organizations with isOptedOut = true, optedOutAt within the period, and optOutReason = «Отписка из письма» (opted out via the campaign email unsubscribe link). The metrics SHALL respect the user access scope (ADR-0007/0008, ADR-0011): an administrator SHALL see all organizations, a manager SHALL see only organizations in their access scope. Metrics SHALL be computed from data, not hardcoded.

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

#### Scenario: Администратор видит отписки всех организаций
- **WHEN** администратор открывает главную страницу дашборда
- **AND** организация с isOptedOut = true существует в системе
- **THEN** показатели блока «Отписки» вычисляются по всем организациям системы

#### Scenario: Менеджер не видит отписки вне своей области доступа
- **WHEN** менеджер открывает главную страницу дашборда
- **AND** организация с isOptedOut = true находится вне его области доступа
- **THEN** показатели блока «Отписки» не учитывают эту организацию
