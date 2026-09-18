## ADDED Requirements

### Requirement: Контакт может быть основным (isMain)
The system SHALL store a boolean flag `isMain` on the contact, defaulting to false. Only one contact per organization SHALL have `isMain = true`. Setting `isMain = true` on a contact SHALL automatically set `isMain = false` on the previous main contact of the same organization. The `isMain` flag SHALL be editable by administrators and managers with access to the organization. The `isMain` flag SHALL be optional: an organization MAY have no main contact. The effective main contact of an organization SHALL be the contact with `isMain = true`, or — when the organization has no such contact, or when several contacts have it (invalid state) — the contact with the smallest ID. When any contact of an organization is saved while multiple contacts have `isMain = true`, the system SHALL automatically set `isMain = false` on all such contacts except the one with the smallest ID.

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

#### Scenario: Аномалия: несколько основных контактов — автосброс
- **WHEN** у организации "ООО Ромашка" два контакта с isMain = true: "Иван Петров" (ID 5) и "Мария Смирнова" (ID 9)
- **AND** администратор сохраняет любого контакта этой организации
- **THEN** isMain = true сохраняется только у "Ивана Петрова" (минимальный ID)
- **AND** isMain контакта "Мария Смирнова" становится false

#### Scenario: Отображение основного контакта в списке
- **WHEN** администратор открывает список контактов организации "ООО Ромашка"
- **AND** контакт "Иван Петров" отмечен как isMain
- **THEN** рядом с именем контакта "Иван Петров" отображается метка "Основной"

### Requirement: Основной контакт отображается первым в списке
The system SHALL display the effective main contact of the organization — the contact with `isMain = true`, or the contact with the smallest ID when no main contact is set — first in all contact lists (dashboard, organization edit form). Remaining contacts SHALL be sorted alphabetically by name. The effective main contact SHALL be visually highlighted with a light red background tint: on the dashboard — the contact card background, on the organization edit page — the contact name background. The «Основной» badge SHALL be shown only for a contact with `isMain = true`, and SHALL NOT be shown for the ID-based fallback contact.

#### Scenario: Основной контакт первый на дашборде
- **WHEN** у организации "ООО Ромашка" есть контакты "Мария Смирнова" (isMain) и "Иван Петров"
- **AND** пользователь открывает дашборд
- **THEN** контакт "Мария Смирнова" отображается первым в списке контактов организации
- **AND** карточка контакта "Мария Смирнова" имеет светло-красный оттенок фона

#### Scenario: Основной контакт первый в форме организации
- **WHEN** администратор открывает форму редактирования организации "ООО Ромашка"
- **AND** у организации есть контакты "Алексей Сидоров", "Мария Смирнова" (isMain), "Иван Петров"
- **THEN** в списке контактов "Мария Смирнова" отображается первой с меткой "Основной"
- **AND** остальные контакты отображаются в алфавитном порядке
- **AND** фон имени "Марии Смирновой" имеет светло-красный оттенок

#### Scenario: Без флага главным отображается минимальный по ID
- **WHEN** пользователь открывает дашборд или форму редактирования организации "ООО Ромашка"
- **AND** у организации нет ни одного контакта с isMain
- **AND** минимальный ID среди контактов организации — у "Алексея Сидорова"
- **THEN** "Алексей Сидоров" отображается первым в списке контактов
- **AND** он подсвечен светло-красным оттенком как главный контакт
- **AND** метка "Основной" у него отсутствует (флаг isMain не установлен)