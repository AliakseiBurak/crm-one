## ADDED Requirements

### Requirement: Контакт может быть основным (isMain)
The system SHALL store a boolean flag `isMain` on the contact, defaulting to false. Only one contact per organization SHALL have `isMain = true`. Setting `isMain = true` on a contact SHALL automatically set `isMain = false` on the previous main contact of the same organization. The `isMain` flag SHALL be editable by administrators and managers with access to the organization.

#### Scenario: Установка основного контакта
- **WHEN** администратор редактирует контакт "Иван Петров" организации "ООО Ромашка"
- **AND** отмечает "Основной контакт"
- **THEN** у контакта "Иван Петров" isMain установлен в true

#### Scenario: Только один основной контакт в организации
- **WHEN** у организации "ООО Ромашка" есть контакт "Мария Смирнова" с isMain = true
- **AND** администратор отмечает "Основной контакт" у контакта "Иван Петров"
- **THEN** isMain контакта "Мария Смирнова" становится false
- **AND** isMain контакта "Иван Петров" становится true

#### Scenario: Снятие основного контакта
- **WHEN** у контакта "Иван Петров" установлен isMain
- **AND** администратор снимает отметку "Основной контакт"
- **THEN** у контакта "Иван Петров" isMain становится false
- **AND** у организации "ООО Ромашка" нет основного контакта

#### Scenario: Отображение основного контакта в списке
- **WHEN** администратор открывает список контактов организации "ООО Ромашка"
- **AND** контакт "Иван Петров" отмечен как isMain
- **THEN** рядом с именем контакта "Иван Петров" отображается метка "Основной"

### Requirement: Основной контакт отображается первым в списке
The system SHALL display the contact with `isMain = true` first in all contact lists (dashboard, organization edit form). Remaining contacts SHALL be sorted alphabetically by name.

#### Scenario: Основной контакт первый на дашборде
- **WHEN** у организации "ООО Ромашка" есть контакты "Мария Смирнова" (isMain) и "Иван Петров"
- **AND** пользователь открывает дашборд
- **THEN** контакт "Мария Смирнова" отображается первым в списке контактов организации

#### Scenario: Основной контакт первый в форме организации
- **WHEN** администратор открывает форму редактирования организации "ООО Ромашка"
- **AND** у организации есть контакты "Алексей Сидоров", "Мария Смирнова" (isMain), "Иван Петров"
- **THEN** в списке контактов "Мария Смирнова" отображается первой с меткой "Основной"
- **AND** остальные контакты отображаются в алфавитном порядке