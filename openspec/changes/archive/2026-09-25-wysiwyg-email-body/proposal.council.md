# Council Notes: proposal.md

## Author Summary

Proposal переписан под утверждённый объём: TipTap 2 вместо textarea, HTML-тело с санитизацией на сохранении, единый `CampaignEmailRenderer` (шелл 600px + inline CSS + текстовая часть) для отправки и трёх предпросмотров, изображения только внешним https-URL, таблицы через режим исходного HTML, без миграции данных (фикстуры пересоздаются). Capabilities: New — нет, Modified — `campaigns`.

## Reviewer Challenges

- Кросс-модельное ревью не выполнено: council-агенты недоступны в этой среде (см. ниже). Черновик проверен primary-агентом вручную по чек-листу adversarial-authoring: полнота требований, отсутствие scope creep, соответствие шаблону, неоднозначности, риски.

## Resolutions

- Deferred: независимое кросс-модельное ревью proposal. Причина: `adversarial-author` использует `opencode/big-pickle`, недоступный субагентам («free tier can only be used from within OpenCode»); `adversarial-reviewer` использует `openai/gpt-5.5` с невалидным API-ключом. Пользователь подтвердил продолжение без council.
- Accepted: убрано утверждение «столбец TEXT — тип не меняется» (фактически `LONGTEXT`, миграция Version20260826120000).
- Accepted: убрана формулировка «санитизация … или доверие вводу»; санитизация обязательна.
- Accepted: загрузка/хранение картинок, CID, табличный UI и выбор реального получателя в предпросмотре явно исключены из объёма.
- Accepted: терминологическое разграничение — `preview_text` = прехедер, новая функция = предпросмотр письма.

## Remaining Risks

- Кросс-модельная проверка proposal не проводилась; при появлении рабочих council-агентов ревью стоит повторить на этапе specs/design.
- Полнота allowlist CSS-свойств определится в specs/design и тестах санитайзера.
