## ADDED Requirements

### Requirement: Реестр скрытых организаций показывает дату создания и отрасль
The system SHALL render in the hidden-organizations registry table, for
each hide row, the organization's created date («Дата создания») and the
organization's industry («Отрасль»). Industry SHALL be optional and render
as «—» when empty. Created date SHALL render as a date (or «—» when
absent). The registry SHALL keep the existing columns (name, hide creator,
hidden-at, show action) and SHALL NOT remove them.

#### Scenario: Админ видит дату создания и отрасль в реестре
- **WHEN** в системе существуют записи скрытия для организаций с заполненными created_at и industry
- **AND** администратор открывает раздел «Скрытые организации»
- **THEN** в таблице для каждой записи отображается колонка «Дата создания» со значением даты создания организации
- **AND** в таблице для каждой записи отображается колонка «Отрасль» со значением отрасли организации

#### Scenario: Организация без отрасли в реестре
- **WHEN** скрытая организация не имеет отрасли
- **AND** администратор открывает раздел «Скрытые организации»
- **THEN** в колонке «Отрасль» для этой записи отображается «—»
