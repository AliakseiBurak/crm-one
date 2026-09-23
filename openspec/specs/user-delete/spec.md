## Purpose

Определяет процедуру удаления пользователя системы администратором: какие
условия должны быть соблюдены, что происходит с персональной группой
при удалении менеджера и как обеспечивается доступ к операции удаления.

## Requirements

### Requirement: Удалять пользователя может только администратор
The system SHALL allow deleting a user only to an authenticated
administrator. Managers and anonymous users SHALL NOT be permitted to
delete users.

#### Scenario: Администратор удаляет пользователя
- **WHEN** аутентифицированный администратор отправляет запрос на удаление пользователя
- **THEN** система удаляет пользователя

#### Scenario: Менеджер не может удалять пользователей
- **WHEN** аутентифицированный менеджер пытается выполнить запрос удаления пользователя
- **THEN** система отклоняет операцию с ошибкой доступа

#### Scenario: Анонимный пользователь не может удалять пользователей
- **WHEN** неаутентифицированный пользователь пытается выполнить запрос удаления пользователя
- **THEN** система возвращает ошибку аутентификации

### Requirement: Администратор не может удалить самого себя
The system SHALL reject an administrator's request to delete their own
account; the administrator SHALL remain authenticated and the system
SHALL return an error indicating self-deletion is not permitted.

#### Scenario: Администратор пытается удалить себя
- **WHEN** аутентифицированный администратор пытается удалить собственную учётную запись
- **THEN** система отклоняет операцию с ошибкой валидации и не удаляет учётную запись

### Requirement: Судьба групп при удалении менеджера
The system SHALL present a single choice for all groups created by the
deleted manager: reassign all groups to the current administrator
(`created_by = admin`) or delete all groups. The choice SHALL be required
before the deletion confirm is accepted when the manager has created any
groups. When a user with role `admin` is deleted, no group ownership
change SHALL be required beyond the normal deletion flow.

#### Scenario: Удаление менеджера требует выбора для его групп
- **WHEN** администратор удаляет пользователя с ролью manager, создавшего группы
- **THEN** система требует выбрать действие для всех его групп (переназначить администратору или удалить)
- **AND** пользователь удаляется только после выбора

#### Scenario: Переназначение групп администратору
- **WHEN** администратор удаляет менеджера "Иван Петров", создавшего группу "Минский регион"
- **AND** выбирает переназначение групп администратору
- **THEN** created_by группы "Минский регион" становится равным администратору
- **AND** группа "Минский регион" остаётся в системе

#### Scenario: Удаление групп
- **WHEN** администратор удаляет менеджера "Иван Петров", создавшего группу "Минский регион"
- **AND** выбирает удаление групп
- **THEN** группа "Минский регион" удаляется из системы

#### Scenario: Нельзя удалить менеджера без выбора по его группам
- **WHEN** администратор пытается подтвердить удаление менеджера, создавшего группы, без выбора действия
- **THEN** система отклоняет запрос с ошибкой

#### Scenario: Удаление администратора не требует выбора по группам
- **WHEN** администратор удаляет пользователя с ролью admin
- **THEN** система удаляет пользователя; выбор судьбы групп не запрашивается

### Requirement: Организации, созданные менеджером, переназначаются администратору при удалении
The system SHALL automatically reassign all organizations created by the
deleted manager (`created_by = deleted user`) to the current administrator
(`created_by = admin`) without requiring a per-organization choice. The
deletion confirmation page SHALL display an informational note listing the
count of organizations that will be reassigned. Organizations themselves
SHALL NOT be deleted as part of user deletion. When a user with role
`admin` is deleted, no organization ownership change SHALL be required
beyond the normal deletion flow.

#### Scenario: Удаление менеджера — примечание о переназначении организаций
- **WHEN** администратор удаляет пользователя с ролью manager, создавшего организации
- **THEN** на странице подтверждения отображается примечание о том, что организации будут переназначены администратору
- **AND** количество указанных организаций отображается в примечании

#### Scenario: Автоматическое переназначение организаций
- **WHEN** администратор подтверждает удаление менеджера "Иван Петров", создавшего организацию "ООО Ромашка"
- **THEN** created_by организации "ООО Ромашка" становится равным администратору
- **AND** организация "ООО Ромашка" остаётся в системе

#### Scenario: Удаление администратора не требует выбора по организациям
- **WHEN** администратор удаляет пользователя с ролью admin
- **THEN** система удаляет пользователя; переназначение организаций по created_by не запрашивается

### Requirement: Удаление каскадно — связанные данные обрабатываются
The system SHALL handle all related data when deleting a user. Calls,
campaigns, deals and contacts belonging to the deleted user SHALL be
reassigned or removed according to the data model rules (ADR-0004,
ADR-0010). The deletion SHALL execute within a transaction.

#### Scenario: Удаление пользователя с историей звонков
- **WHEN** администратор удаляет пользователя, имеющего связанные
  звонки и кампании
- **THEN** система удаляет пользователя и обрабатывает связанные
  сущности согласно модели данных (ADR-0004, ADR-0010)

#### Scenario: Удаление пользователя в транзакции
- **WHEN** администратор удаляет пользователя
- **THEN** удаление пользователя и связанных данных выполняется в
  одной транзакции; при ошибке все изменения откатываются
