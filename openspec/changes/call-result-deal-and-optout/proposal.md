## Why

Результат звонка «сделка» (`is_deal`) сейчас фиксируется без указания курса, что не позволяет отслеживать, какой именно курс выбрал клиент. Также нет механизма отметить звонок как «отказ» и автоматически проставить организации отказ от рассылки. Нужен эндпоинт для программной отписки организации. Кроме того, отписавшиеся организации не исключаются из рассылок, а дашборд не показывает статистику отписок.

## What Changes

- **Organization**: добавляются поля `currentCourse` (FK → Course) — текущий/актуальный курс организации, `isActive` (boolean, default true) — метка активности компании, `isOptedOut` (boolean) + `optOutReason` (text, nullable) + `optedOutAt` (datetime, nullable) — для отметки отказа от рассылки
- **Развязка (вариант C)** — opt-out пара `isOptedOut`/`optOutReason` принадлежит только этому change; из `organization-fields-expansion` она исключена, миграции не дублируются
- **Call**: добавляется поле `course` (FK → Course, nullable) — курс, выбранный при сделке; добавляется поле `is_refusal` (boolean) — отметка «отказ»
- **Форма результата звонка**: при отметке «сделка» появляется селект курса; при отметке «отказ» появляются чекбоксы «отметить как неактивную» (isActive = false) и/или «отказ от рассылок» (isOptedOut = true)
- **Действия при отказе**: при сохранении звонка с `is_refusal = true` система, в зависимости от отмеченных чекбоксов, устанавливает `Organization.isActive = false` и/или `Organization.isOptedOut = true` и опционально заполняет `optOutReason`
- **Форма создания/редактирования организации**: добавлен чекбокс `isActive`; поле «Причина отказа» отображается только при `isOptedOut = true` (условное отображение, поглощено из `organization-fields-expansion`); при снятии отказа причина и дата сбрасываются (сеттер `setIsOptedOut(false)` → `optOutReason = null`, `optedOutAt = null`)
- **Эндпоинт автоотписки**: `POST /organizations/{id}/opt-out` — принимает `{ reason: string }`, проставляет `isOptedOut = true`, возвращает обновлённую организацию. Доступ: администратор и менеджеры с областью доступа к организации
- **Исключение отписавшихся из рассылок** — организации с `isOptedOut = true` не добавляются адресатами (все пути создания) и пропускаются при отправке
- **Кнопка отписки в письме**: новый токен `{{unsubscribe_url}}` в шаблоне письма кампании, который резолвится в URL для отписки организации; на странице редактирования кампании появляется кнопка «Вставить отписку», вставляющая токен в тело письма
- **Статистика отписок на дашборде**: за прошлую неделю, за текущий месяц и всего отписанных

## Capabilities

### New Capabilities

Нет новых возможностей — все изменения внутри существующих capability.

### Modified Capabilities

- `organizations` — новые поля `currentCourse`, `isActive`, `isOptedOut`, `optOutReason`, `optedOutAt`
- `organizations/crud` — форма организации: чекбокс isActive, условное отображение optOutReason, сброс причины при снятии отказа
- `calls` — новое поле `course` на Call; новое поле `is_refusal` на Call; автоотписка при отказе
- `calls/results` — сценарий сделки с выбором курса; сценарий отказа с автоотпиской
- `calls/crud` — форма результата: селект курса при сделке; чекбокс «отказ»; эндпоинт opt-out
- `campaigns` — новый токен `{{unsubscribe_url}}`; кнопка «Вставить отписку»; исключение отписавшихся организаций
- `dashboard` — статистика отписок (прошлая неделя, текущий месяц, всего)

## Impact

- **Сущности**: Organization (5 новых полей: currentCourse, isActive, isOptedOut, optOutReason, optedOutAt), Call (2 новых поля — course FK, is_refusal)
- **Миграция**: ALTER TABLE organization ADD currentCourse_id, isActive, isOptedOut, optOutReason, optedOutAt; ALTER TABLE call ADD course_id, is_refusal
- **Формы**: форма результата звонка — условный селект курса при is_deal; при отказе — чекбоксы «неактивная» и/или «отказ от рассылок»; форма организации — чекбокс isActive + условное поле optOutReason
- **Новый эндпоинт**: POST /organizations/{id}/opt-out — контроллер + FormRequest + валидация
- **Рассылки**: фильтр отписавшихся при выборе адресатов (все пути) и повторная проверка при отправке
- **Дашборд**: блок статистики отписок с областью доступа
- **Спецификации**: organizations/spec.md, organizations/crud/spec.md, calls/spec.md, calls/results/spec.md, calls/crud/spec.md, campaigns/spec.md, dashboard/spec.md
- **ER-диаграмма**: обновить блоки Organization и Call
