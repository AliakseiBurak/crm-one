# Organization Groups

Группы организаций распределяют организации между менеджерами. Менеджеры
создают собственные группы; администратор видит и управляет всеми группами. Организация может
состоять в нескольких группах одновременно (`OrgGroupMembership`, таблица `org_group_membership`).

## Purpose

Группы организаций распределяют организации между менеджерами: менеджеры
создают собственные группы, администратор видит и управляет всеми группами,
членство организации в группах — many-to-many.

## Requirements

### Requirement: Администратор не имеет собственной группы
The administrator SHALL NOT have a personal `user-<id>-group`; the access check
for an administrator SHALL skip groups entirely.

#### Scenario: Админу группа не создаётся
- **WHEN** администратор создаёт пользователя с ролью admin
- **THEN** для этого пользователя группа `user-<id>-group` не создаётся

#### Scenario: Проверка доступа администратора не обращается к группам
- **WHEN** администратор открывает список организаций
- **THEN** он видит все организации без проверки групп

### Requirement: Администратор управляет custom-группами
The administrator SHALL be able to create, modify, and delete ALL custom groups (including manager-owned groups), with a name, an optional description, and an optional hex color, and add organizations to groups. The administrator SHALL assign groups to managers through the assignment interface. Adding an organization to a group SHALL NOT remove it from other groups: an organization MAY belong to several groups at once.

#### Scenario: Админ создаёт custom-группу
- **WHEN** аутентифицированный администратор создаёт custom-группу "Минский регион" с описанием "Организации Минской области" и цветом "#3b82f6"
- **THEN** группа сохраняется с `created_by = admin`
- **AND** группа видна в списке групп администратора

#### Scenario: Админ видит создателя группы
- **WHEN** администратор открывает список групп
- **THEN** он видит колонку "Создатель" с указанием имени создателя группы

#### Scenario: Админ добавляет организацию в несколько групп
- **WHEN** организация "ООО Ромашка" состоит в группе "Минский регион"
- **AND** администратор добавляет её в группу "Южный регион"
- **THEN** организация "ООО Ромашка" состоит в группах "Минский регион" и "Южный регион"

#### Scenario: Удаление организации из группы не влияет на другие группы
- **WHEN** организация "ООО Ромашка" состоит в группах "Минский регион" и "Южный регион"
- **AND** администратор удаляет её из группы "Минский регион"
- **THEN** организация "ООО Ромашка" продолжает состоять в группе "Южный регион"

#### Scenario: Администратор видит все группы
- **WHEN** администратор открывает список групп
- **THEN** он видит все группы всех менеджеров

#### Scenario: Администратор может редактировать группу менеджера
- **WHEN** администратор редактирует группу менеджера
- **THEN** изменения сохраняются

#### Scenario: Назначение группы менеджеру
- **WHEN** администратор открывает страницу назначения группы
- **THEN** он видит список всех менеджеров с флажками
- **AND** флажок менеджера установлен, если группа уже назначена ему (GroupAssignment)

#### Scenario: Сохранение назначений
- **WHEN** администратор изменяет флажки менеджеров и нажимает «Сохранить»
- **THEN** состав менеджеров группы обновляется
- **AND** менеджеры получают доступ к организациям группы

### Requirement: Членство организации в группах является many-to-many
An organization SHALL be able to belong to several groups at once through `OrgGroupMembership`, and one group MAY be assigned to several managers through `GroupAssignment`.

#### Scenario: Одна группа назначена нескольким менеджерам
- **WHEN** группа "Минский регион" создана менеджером "Иван Петров" и назначена (`GroupAssignment`) менеджеру "Мария Смирнова"
- **THEN** обоим менеджерам доступны организации группы "Минский регион"

### Requirement: Менеджер управляет своими custom-группами
The system SHALL allow managers to create, edit, and delete custom groups. Managers SHALL have full CRUD access to groups they created (`created_by = self`). Managers SHALL NOT be able to edit or delete groups created by other managers or by the administrator.

#### Scenario: Менеджер создаёт custom-группу
- **WHEN** менеджер "Иван Петров" создаёт группу "Минский регион" с описанием и цветом
- **THEN** группа создаётся с `created_by = current_manager`
- **AND** группа видна менеджеру "Иван Петров" в списке групп

#### Scenario: Менеджер редактирует свою группу
- **WHEN** менеджер "Иван Петров" редактирует свою группу "Минский регион"
- **THEN** изменения сохраняются
- **AND** группа остаётся доступной другим менеджерам, которым она назначена

#### Scenario: Менеджер удаляет свою группу
- **WHEN** менеджер "Иван Петров" удаляет свою группу "Минский регион"
- **THEN** группа удаляется
- **AND** членство организаций в группе удаляется
- **AND** назначения группы другим менеджерам удаляются
- **AND** сами организации остаются в системе

#### Scenario: Менеджер не может редактировать чужую группу
- **WHEN** менеджер "Иван Петров" пытается отредактировать группу "Южный регион", созданную другим менеджером
- **THEN** система отклоняет запрос с ошибкой 403

#### Scenario: Менеджер не может удалить чужую группу
- **WHEN** менеджер "Иван Петров" пытается удалить группу "Южный регион", созданную другим менеджером
- **THEN** система отклоняет запрос с ошибкой 403

### Requirement: Менеджер управляет членством организаций в своих группах
The system SHALL allow managers to add and remove organizations from groups they created. Managers SHALL be able to add organizations they have access to into their groups. Groups assigned to a manager by the administrator SHALL be visible to the manager in read-only mode: the composition of such a group is displayed, but the manager SHALL NOT be able to change it.

#### Scenario: Менеджер добавляет организацию в свою группу
- **WHEN** менеджер "Иван Петров" открывает страницу своей группы "Минский регион"
- **AND** добавляет организацию "ООО Ромашка"
- **THEN** организация "ООО Ромашка" добавляется в группу "Минский регион"

#### Scenario: Менеджер удаляет организацию из своей группы
- **WHEN** менеджер "Иван Петров" удаляет организацию "ООО Ромашка" из группы "Минский регион"
- **THEN** организация "ООО Ромашка" больше не состоит в группе "Минский регион"
- **AND** организация остаётся в других группах

#### Scenario: Менеджер просматривает состав назначенной группы
- **WHEN** менеджер "Иван Петров" открывает страницу участников группы "Южный регион", созданной администратором и назначенной ему
- **THEN** система показывает организации этой группы
- **AND** форма изменения состава не отображается

#### Scenario: Менеджер не может изменить состав назначенной группы
- **WHEN** менеджер "Иван Петров" отправляет запрос на изменение состава группы "Южный регион", созданной администратором
- **THEN** система отклоняет запрос с ошибкой 403
- **AND** членство организаций в группе не меняется

### Requirement: Группы имеют метаданные для использования в рассылках
Each organization group SHALL support optional metadata fields: a description field (text, nullable) and a color field (hex string, VARCHAR(7), nullable). These fields SHALL be manageable by administrators and by managers for their own groups.

#### Scenario: Менеджер задаёт описание и цвет для своей группы
- **WHEN** менеджер "Иван Петров" редактирует свою группу "Минский регион" и заполняет описание "Организации Минской области" и цвет "#3b82f6"
- **THEN** описание и цвет сохраняются
- **AND** менеджер видит группу с описанием и цветом в интерфейсе выбора для рассылки

### Requirement: При создании организации можно добавить её в группы
When creating an organization, the system SHALL display checkboxes for available groups. For managers: groups where `created_by = self` OR assigned. For admin: all groups. The organization SHALL be added to checked groups. If no groups checked, the organization exists ungrouped.

#### Scenario: Менеджер создаёт организацию и выбирает группы
- **WHEN** менеджер "Иван Петров" создаёт организацию "ООО Ромашка"
- **AND** отмечает группы "Минский регион" и "Южный регион"
- **THEN** организация "ООО Ромашка" добавляется в обе группы

#### Scenario: Менеджер создаёт организацию без выбора группы
- **WHEN** менеджер "Иван Петров" создаёт организацию "ООО Ромашка" без выбора групп
- **THEN** организация создаётся без групповой принадлежности

#### Scenario: Админ видит все группы при создании организации
- **WHEN** администратор создаёт организацию
- **THEN** он видит все доступные группы в списке чекбоксов

### Requirement: При удалении менеджера администратор выбирает судьбу его групп
When deleting a manager, the system SHALL display a warning listing all groups created by that manager, with the number of organizations in each group and the managers it is assigned to. The administrator SHALL be forced to choose per-group: "Reassign to Admin" (changes `created_by` to admin, keeps group and assignments) or "Delete group" (removes group, membership, and assignments; organizations themselves are not deleted).

#### Scenario: Администратор видит контекст групп перед удалением менеджера
- **WHEN** администратор открывает страницу удаления менеджера "Иван Петров"
- **AND** тот создал группу "Минский регион" с двумя организациями, назначенную менеджеру "Мария Смирнова"
- **THEN** в предупреждении отображается название группы, число организаций и имя менеджера, которому она назначена

#### Scenario: Администратор переназначает группы при удалении менеджера
- **WHEN** администратор удаляет менеджера "Иван Петров", создавшего группы "Минский регион" и "Южный регион"
- **AND** выбирает "Переназначить администратору" для группы "Минский регион"
- **AND** выбирает "Удалить группу" для группы "Южный регион"
- **THEN** группа "Минский регион" переназначается администратору (`created_by = admin`)
- **AND** группа "Южный регион" удаляется вместе с членством и назначениями

#### Scenario: Администратор не может удалить менеджера без выбора для каждой группы
- **WHEN** администратор пытается подтвердить удаление менеджера без выбора действия для группы
- **THEN** система отклоняет запрос с ошибкой

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

### Requirement: Управление группами доступно менеджерам и администратору
The group management UI SHALL be visible to both administrators and managers. Managers SHALL see "Мои группы" menu item with CRUD access to their own groups. Administrators SHALL see all groups with full management access.

#### Scenario: Менеджер видит раздел "Мои группы"
- **WHEN** менеджер открывает главное меню
- **THEN** он видит раздел "Мои группы"

#### Scenario: Менеджер не видит чужие группы в управлении
- **WHEN** менеджер "Иван Петров" открывает раздел "Мои группы"
- **THEN** он видит только группы, которые создал сам, и группы, назначенные ему администратором

#### Scenario: Назначенная группа отображается без правки
- **WHEN** менеджер "Иван Петров" открывает раздел "Мои группы"
- **AND** в списке есть назначенная ему группа "Южный регион", созданная администратором
- **THEN** для группы "Южный регион" отображается ссылка "Участники" с пометкой «только просмотр»
- **AND** ссылка "Редактировать" для этой группы не отображается