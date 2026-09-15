## 1. Сущность и миграция

- [ ] 1.1 Добавить поля `annualPlan` (string, nullable), `description` (text, nullable), `hasUsedServices` (boolean, default false) в Doctrine-сущность `Organization`
- [ ] 1.2 Сделать `industry` nullable — изменить атрибут с `nullable=false` на `nullable=true`
- [ ] 1.3 Сгенерировать миграцию (`make:migration`) и проверить SQL — `ALTER TABLE` для всех полей
- [ ] 1.4 Накатить миграцию (`doctrine:migrations:migrate`) и проверить `diff` — чистый

## 2. Формы и валидация

- [ ] 2.1 Добавить поля `annualPlan` (text), `description` (textarea), `hasUsedServices` (checkbox) в Symfony FormType организации
- [ ] 2.2 Убрать `NotBlank`/`NotNull` с поля `industry` в FormType; оставить required только на `name`
- [ ] 2.3 Обновить Twig-шаблон формы создания/редактирования — новые поля

## 3. Тесты

- [ ] 3.1 Написать функциональный тест создания организации без отрасли — 200, поле `industry` = null
- [ ] 3.2 Написать функциональный тест создания организации со всеми новыми полями — все сохраняются
- [ ] 3.3 Запустить все тесты и убедиться в зелёном статусе

## 4. Фикстуры и документация

- [ ] 4.1 Обновить датафикстуры Organization — добавить примеры с новыми полями
- [ ] 4.2 Обновить ER-диаграмму (`openspec/design/er.md`) — блок Organization: новые поля (`annual_plan`, `description`, `has_used_services`), `industry` nullable; заодно устранить устаревшие упоминания `user-<id>-group` (связь в диаграмме и правило 3) по ADR-0011
- [ ] 4.3 Обновить ADR-0001 (`adr/0001-organization-core-model.md`) — перечислить новые поля
- [ ] 4.4 Запустить `openspec validate` для дельт change — проверить валидность спецификаций
