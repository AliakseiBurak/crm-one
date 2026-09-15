## 1. Сущность и миграция

- [ ] 1.1 Добавить поле `isMain` (boolean, default false) в Doctrine-сущность `Contact`
- [ ] 1.2 Сгенерировать миграцию (`make:migration`) и проверить SQL
- [ ] 1.3 Накатить миграцию и проверить `diff`

## 2. Логика уникальности isMain

- [ ] 2.1 Добавить `ContactRepository::resetIsMainForOrganization(Organization, exclude: Contact)` — DQL UPDATE
- [ ] 2.2 В `ContactController::create` и `update`: после `applyRequest`, перед flush — вызвать `resetIsMainForOrganization` при `isMain = true`
- [ ] 2.3 В `ContactController::applyRequest`: читать чекбокс isMain из формы

## 3. Форма контакта

- [ ] 3.1 Добавить чекбокс «Основной контакт» (isMain) в ContactFormType
- [ ] 3.2 Обновить Twig-шаблон формы создания/редактирования контакта
- [ ] 3.3 Обновить отображение списка контактов на форме организации — метка «Основной» у isMain-контакта; isMain-контакт первым в списке (передать отсортированный список из контроллера)

## 4. Сортировка и отображение

- [ ] 4.1 Обновить `ContactRepository::findByOrganizations` — `ORDER BY c.isMain DESC, c.name ASC`
- [ ] 4.2 Добавить `ContactRepository::findByOrganization` (одна организация, сортировка isMain первый)
- [ ] 4.3 Обновить `HomeController` — передавать отсортированные контакты для формы организации
- [ ] 4.4 Добавить бейдж «Основной» в `contact/_card.html.twig`

## 5. Маршрутизация email в MailingService

- [ ] 5.1 Обновить MailingService: при выборе email для CampaignRecipient — определить TO и CC по правилам isMain
- [ ] 5.2 Если контакт не указан — TO на isMain-контакт, CC на остальные
- [ ] 5.3 Если контакт указан с email — только ему (TO)
- [ ] 5.4 Если контакт указан без email — fallback на isMain (TO) + CC остальных

## 6. Подстановка токенов в Campaign

- [ ] 6.1 Обновить `Campaign::fillTokens` — при `$contact = null` использовать isMain-контакт для `{{greeting}}` и `{{contact_name}}`

## 7. Тесты

- [ ] 7.1 Unit-тест: смена isMain сбрасывает предыдущий основной контакт
- [ ] 7.2 Функциональный тест: создание контакта с isMain = true
- [ ] 7.3 Функциональный тест: MailingService — письмо isMain контакту
- [ ] 7.4 Функциональный тест: MailingService — письмо указанному контакту
- [ ] 7.5 Функциональный тест: MailingService — fallback при пустом email у контакта
- [ ] 7.6 Функциональный тест: fillTokens — приветствие с isMain-контактом
- [ ] 7.7 Запустить все тесты

## 8. Документация

- [ ] 8.1 Обновить ER-диаграмму — isMain на Contact
- [ ] 8.2 Запустить `openspec validate contact-ismain-email-routing`