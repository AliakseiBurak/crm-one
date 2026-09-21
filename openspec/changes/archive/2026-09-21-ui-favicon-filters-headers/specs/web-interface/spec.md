## ADDED Requirements

### Requirement: Favicon
The system SHALL serve a favicon at `/favicon.ico` and SHALL reference it
with a `<link rel="icon">` element in the `<head>` of every page rendered
from the base layout.

#### Scenario: Иконка вкладки браузера
- **WHEN** пользователь открывает любую страницу интерфейса
- **THEN** в `<head>` документа есть ссылка `<link rel="icon">` на `/favicon.ico`

### Requirement: Поисковый выпадающий список организаций
The system SHALL render organization selection fields with a searchable
combobox: the native `select[data-org-combobox]` SHALL remain the source of
the submitted value, while the interface SHALL show a toggle button opening
a menu with a search input that filters options by case-insensitive
substring. The combobox SHALL be applied to the organization fields of the
hidden organizations registry and the campaign recipients form, in addition
to the organization fields that already have it. When no option matches the
search input, the menu SHALL show «Ничего не найдено».

#### Scenario: Поиск организации в выпадающем списке
- **WHEN** пользователь открывает поисковый выпадающий список организаций и вводит часть названия
- **THEN** в меню остаются только организации, названия которых содержат введённую подстроку
- **AND** выбор организации подставляет её значение в поле формы

#### Scenario: Ничего не найдено
- **WHEN** введённая подстрока не встречается ни в одном названии организации
- **THEN** в меню отображается «Ничего не найдено»

#### Scenario: Поля организаций в реестре скрытий и у адресатов рассылки
- **WHEN** пользователь открывает реестр «Скрытые организации» или форму добавления адресата рассылки
- **THEN** поле выбора организации имеет поисковый выпадающий список

## MODIFIED Requirements

### Requirement: Типографика рабочего инструмента
The system SHALL use Roboto for body text at 15px with color `#5a5a5a` and
Roboto Condensed for headings, table text and emphasized figures. Headings
SHALL render in Roboto Condensed bold: `h1` 32px, `h2` 28px (24px below
576px viewport) with a blue left border of `0.19em` in color `#20799e` and
left padding `0.3em`, `h3` 22px (20px below 576px). Table text SHALL
render at 16px Roboto Condensed. Card titles SHALL render at 20px Roboto
Condensed bold. Dashboard statistics SHALL use figures of 56px bold.
Heading color modifiers SHALL be available: green `#5e9e47`, orange
`#d66a2b`, blue `#20799e`.

#### Scenario: Заголовок секции с синей полосой
- **WHEN** на странице отображается заголовок `h2` секции
- **THEN** он выполнен шрифтом Roboto Condensed 28px bold
- **AND** слева от текста — синяя полоса `#20799e` шириной `0.19em`
- **AND** текст заголовка окрашен в `#20799e`

#### Scenario: Заголовок первого уровня с синей полосой
- **WHEN** на странице отображается заголовок `h1`
- **THEN** он выполнен шрифтом Roboto Condensed 32px bold
- **AND** слева от текста — синяя полоса `#20799e` шириной `0.19em`
- **AND** текст заголовка окрашен в `#20799e`

#### Scenario: Крупные числа статистики
- **WHEN** на дашборде отображается блок статистики (например, «Сделано звонков», «Ожидают звонка»)
- **THEN** число набрано 56px bold белым цветом
- **AND** подпись к числу набрана 20px bold белым цветом
