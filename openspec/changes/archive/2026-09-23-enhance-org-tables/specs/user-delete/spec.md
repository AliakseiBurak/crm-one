## MODIFIED Requirements

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

## ADDED Requirements

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
