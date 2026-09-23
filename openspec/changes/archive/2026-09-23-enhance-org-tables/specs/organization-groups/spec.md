## ADDED Requirements

### Requirement: Таблица состава группы — колонки и сортировка
The system SHALL render the group-composition (members) table with columns
for checkbox/selection (in edit mode), organization name («Название»),
industry («Отрасль»), organization created date («Дата создания») and
organization creator («Создатель», the user referenced by
`Organization.created_by`). Header cells for «Название», «Отрасль»,
«Дата создания» and «Создатель» SHALL be sortable. The default sort SHALL
be organization name ascending. Clicking a sortable header SHALL toggle
ascending then descending order for that column. Checkbox and action
columns SHALL NOT be sortable. Industry and creator SHALL render as «—»
when empty; created date SHALL render as a date (or «—» when absent).

#### Scenario: Состав группы показывает дату создания и создателя
- **WHEN** администратор открывает страницу участников группы "Минский регион"
- **THEN** в таблице состава отображаются колонки «Название», «Отрасль», «Дата создания» и «Создатель»
- **AND** в колонке «Дата создания» отображается дата создания каждой организации
- **AND** в колонке «Создатель» отображается имя пользователя, создавшего организацию

#### Scenario: Заголовки состава группы сортируемые
- **WHEN** администратор открывает страницу участников группы
- **THEN** заголовки «Название», «Отрасль», «Дата создания» и «Создатель» являются кликабельными заголовками сортировки
- **AND** чекбокс и колонка действий не сортируются

#### Scenario: Сортировка по умолчанию — название по возрастанию
- **WHEN** администратор открывает страницу участников группы без параметров сортировки
- **THEN** организации отсортированы по названию (А–Я)

#### Scenario: Сортировка по дате создания
- **WHEN** пользователь кликает по заголовку «Дата создания»
- **THEN** организации сортируются по дате создания
- **AND** направление сортировки отражается в заголовке

#### Scenario: Сортировка по создателю
- **WHEN** пользователь кликает по заголовку «Создатель»
- **THEN** организации сортируются по создателю организации
- **AND** направление сортировки отражается в заголовке

#### Scenario: Пустые значения отрасли и создателя
- **WHEN** организация в составе группы не имеет отрасли или создателя
- **THEN** в соответствующих ячейках отображается «—»
