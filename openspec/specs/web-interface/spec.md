# Web Interface

Единый визуальный язык интерфейса B2B Call CRM как рабочего инструмента:
мягкая палитра (та же цветовая гамма, приглушённые тона), типографика для
продолжительной работы, адаптивная компоновка и компоненты (welcome page,
формы, кнопки, контактные карточки, таблицы) с
проверяемыми значениями стилей. Цвета имеют фиксированные семантические
роли. Доменные сущности и правила доступа не затрагиваются.

## Purpose

Единый визуальный язык интерфейса B2B Call CRM: мягкая палитра,
типографика, адаптивная компоновка и компоненты (welcome page, формы,
кнопки, контактные карточки, таблицы) с проверяемыми
значениями стилей.

## Requirements

### Requirement: Палитра интерфейса и семантика цветов
The system SHALL render the interface in a light theme using the softened
palette with fixed semantic roles: orange `#d66a2b` is the action color
(primary action buttons, callable phones, CTA accents) with the gradient
`#d66a2b → #e09a68` for primary action buttons; green `#5e9e47` is the
success and status color (successful results, active states) and SHALL NOT
be used for headings; blue `#20799e` is the navigation and information
color (headings, card titles, links) with the gradient
`#20799e → #419cbe` for blue section bands; the green gradient
`#55964a → #478540` SHALL be used for section bands, the statistics band,
footer and price badges. Body text SHALL be gray `#5a5a5a`, secondary text
`#b5b5b5`, table stripes `#e3f1f6` and `#e5f5fb`, card section background
`#f5f6f6`, discount and error red gradient `#d9554f → #c13f3a`. The design
SHALL NOT use dark theme variants and SHALL NOT use box shadows anywhere.

#### Scenario: Оранжевый градиент на первичной кнопке
- **WHEN** пользователь открывает welcome page
- **THEN** первичная кнопка призыва к действию имеет вычисленный фон
  линейного градиента от `#d66a2b` к `#e09a68`
- **AND** текст кнопки белый

#### Scenario: Семантика зелёного — только статусы
- **WHEN** результат звонка «Договорились» отображается в списке звонков
- **THEN** значение статуса окрашено в зелёный `#5e9e47`
- **AND** заголовки секций и карточек зелёным не окрашиваются

#### Scenario: Отсутствие теней
- **WHEN** пользователь просматривает любую страницу интерфейса
- **THEN** ни один элемент не имеет CSS box-shadow

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

### Requirement: Адаптивная компоновка
The system SHALL lay out content in a centered container with maximum
width 1360px on viewports of 1400px and wider, and SHALL support the
breakpoints 576px, 768px, 992px, 1200px and 1400px. Full-width sections
SHALL be rendered as colored bands spanning the entire viewport, while
their content stays inside the container. Working sections (lists, tables,
forms) SHALL sit on a white background; card sections SHALL sit on the
light background `#f5f6f6`; colored bands SHALL be reserved for the
welcome page hero, the dashboard statistics band and the
footer.

#### Scenario: Ширина контейнера на широком экране
- **WHEN** пользователь открывает страницу на экране шириной 1440px
- **THEN** основное содержимое страницы занимает не более 1360px по центру

#### Scenario: Рабочая секция на белом фоне
- **WHEN** пользователь открывает список контактов
- **THEN** список отображается на белом фоне
- **AND** ни одна рабочая секция списка не имеет градиентной заливки

### Requirement: Welcome page
The system SHALL render the welcome page with a hero banner: background
image, primary headline, an uppercase accent slogan on a colored band, and
a call-to-action button «Начать работу» that opens the dashboard.

#### Scenario: Герой-баннер welcome page
- **WHEN** пользователь открывает welcome page
- **THEN** в верхней части отображается баннер с фоновым изображением
- **AND** под заголовком отображается слоган прописными буквами на цветной ленте
- **AND** ниже отображается кнопка «Начать работу» с оранжевым градиентом

### Requirement: Кнопки
The system SHALL render primary action buttons as pills with border-radius
30px: filled with the orange gradient `#d66a2b → #e09a68`, white bold text
at 18px, insensitive to hover. Secondary buttons SHALL be white pills with
orange text `#d66a2b`, a 2px orange border and a hover state that inverts
them (orange background, white text). The «all items» link SHALL be bold
18px with a trailing `>>>`. Clickable `tel:` phone links SHALL work when
the interface is opened in mobile browsers and in-app WebViews by
triggering the system dialer. Contact email SHALL be a clickable `mailto:` link that opens the system mail client. There SHALL be NO dedicated «Позвонить» button — a phone call SHALL be initiated by clicking the phone number link itself.

#### Scenario: Звонок кликом по номеру телефона
- **WHEN** пользователь нажимает на номер телефона на карточке контакта
- **THEN** открывается системный звонильщик с этим номером
- **AND** на карточке нет отдельной кнопки «Позвонить»

#### Scenario: Нажатие на email открывает почтовый клиент
- **WHEN** пользователь нажимает на email на карточке контакта
- **THEN** открывается системный почтовый клиент с адресом в поле получателя

### Requirement: Формы
The system SHALL render form inputs without borders or background, with a
bottom underline of 2px and transparent background; the underline color
SHALL be `#d66a2b` on light backgrounds and white on colored bands, and
the underline SHALL thicken to 4px while the field is focused. Input text
and placeholders SHALL use the underline color variant, font Roboto
Condensed 16px, full field width. Submit buttons SHALL be pills with
border-radius 30px: primary — orange gradient with white text; secondary —
light gray background `#e8e8e7` with bold text. Forms SHALL render inline
on their page and SHALL NOT open in a modal window.

#### Scenario: Поле формы с нижним подчёркиванием
- **WHEN** пользователь открывает форму на светлом фоне
- **THEN** каждое текстовое поле отображается без рамки и фона
- **AND** под полем — линия толщиной 2px цвета `#d66a2b`
- **AND** текст и плейсхолдер поля окрашены в `#d66a2b`

#### Scenario: Фокус поля утолщает линию
- **WHEN** пользователь устанавливает курсор в поле формы
- **THEN** линия под полем утолщается с 2px до 4px
- **AND** цвет линии не меняется

### Requirement: Контактные карточки
The system SHALL render contact cards on the `#f5f6f6` section background
as white cards without borders, rounding or shadows, stretching to the
column width with a minimum width of 300px and content-defined height.
Each card SHALL highlight only the essential data: the contact name in
Roboto Condensed 20px bold in blue `#20799e` with a left border of 3px,
and the phone number in bold orange `#d66a2b` as a clickable `tel:` link.
Cards SHALL NOT contain icons or images. Secondary data (position, email,
notes) SHALL be rendered in gray `#5a5a5a` at 15px; the email SHALL be a
clickable `mailto:` link. The card SHALL NOT contain a call or
«Позвонить» action button — phone and email are opened by clicking the data
itself; a placeholder «Изменить» action button MAY be placed within the
card boundaries (bottom-left) for the dashboard contacts section.
The card MAY be used via Twig embed with a `card_footer` block.

#### Scenario: Карточка контакта выделяет имя, телефон и email
- **WHEN** пользователь открывает карточку контакта
- **THEN** карточка белая, без рамки, без скругления и без тени
- **AND** имя контакта — синее `#20799e` bold с синей левой полосой 3px
- **AND** телефон — оранжевый `#d66a2b` bold и является ссылкой для звонка
- **AND** вторичные данные (должность, email) — серые `#5a5a5a`
- **AND** email является ссылкой, открывающей почтовый клиент
- **AND** внизу карточки нет кнопки «Позвонить» и других кнопок звонка; кнопка-заглушка «Изменить» допустима в границах карточки (слева внизу)

#### Scenario: Карточка контакта выделяет имя и телефон
- **WHEN** пользователь открывает карточку контакта
- **THEN** карточка белая, без рамки, без скругления и без тени
- **AND** имя контакта — синее `#20799e` bold с синей левой полосой 3px
- **AND** телефон — оранжевый `#d66a2b` bold и является ссылкой для звонка
- **AND** вторичные данные (должность, email, заметка) — серые `#5a5a5a`
- **AND** электронная почта является ссылкой, открывающей почтовый клиент
- **AND** внизу карточки нет кнопки «Позвонить» и других кнопок звонка; звонок инициируется кликом по номеру телефона; кнопка-заглушка «Изменить» допустима в границах карточки (слева внизу)

#### Scenario: Отсутствие иконок в карточке
- **WHEN** пользователь просматривает карточку контакта
- **THEN** карточка не содержит иконок и изображений
- **AND** акцент сделан только на имени, телефоне и email

#### Scenario: Бейдж цены только на карточках предложений
- **WHEN** карточка контакта отображается рядом с карточкой предложения курса
- **THEN** контактная карточка не содержит бейдж цены
- **AND** бейдж цены с белым текстом на зелёном градиенте `#55964a → #478540`
  отображается только на карточке предложения курса

### Requirement: Таблицы
The system SHALL render data tables (organizations, contacts, calls) with
width 100%, text at 16px Roboto Condensed, zebra striping in `#e3f1f6` for
odd rows and `#e5f5fb` for even rows, without cell borders. Hovering a data
row SHALL highlight it with a shade of the same blue family distinct from
both zebra stripes (`#cfe6f2`), instead of removing the row color. Table headers SHALL be bold 16px in `#5a5a5a` with bottom
padding, and the table SHALL have a bottom margin of 3rem. The contact
name column SHALL be bold, and the phone column SHALL be rendered in
orange `#d66a2b` as a clickable link.

#### Scenario: Зебра-таблица списка контактов
- **WHEN** пользователь открывает список контактов
- **THEN** строки таблицы окрашены попеременно в `#e3f1f6` и `#e5f5fb`
- **AND** между строками и ячейками нет линий рамок
- **AND** текст ячеек выполнен шрифтом Roboto Condensed 16px

#### Scenario: Наведение подсвечивает строку
- **WHEN** пользователь наводит курсор на строку данных таблицы
- **THEN** строка подсвечивается оттенком `#cfe6f2` того же голубого семейства, что и полосы зебры
- **AND** базовый цвет строки не исчезает и заменяется на оттенок семейства (а не на прозрачный)
- **AND** аккордеонная строка раскрытия (вторая строка организации) при наведении не подсвечивается

#### Scenario: Выделение ключевых данных в таблице
- **WHEN** в таблице отображаются контакты
- **THEN** имя контакта в первом столбце — жирное
- **AND** телефон — оранжевая `#d66a2b` кликабельная ссылка
- **AND** остальные столбцы — обычный серый текст `#5a5a5a`

### Requirement: Шапка и подвал
The system SHALL render a white header: the logo «B2B Call CRM» on the left
as a link to the home page, navigation links in the center-right section,
and — for authenticated users — action buttons on the far right: «Создать ▾»
for all users, «⚙ Админ ▾» for admin, «Профиль ▾» user dropdown. The
dropdown buttons SHALL be styled like the navigation links, distinguished
only by a downward caret. The create dropdown SHALL show short labels:
Организацию, Контакт, Звонок, Рассылку; Группу for `ROLE_MANAGER`;
Пользователя for `ROLE_ADMIN`. A user dropdown SHALL show the label
«Профиль» with a caret; its menu SHALL contain as the first item the
user's login, name and surname (if present), and email (if present),
followed by the «Выйти» link. All dropdowns SHALL open on click and close
when clicking outside.
On screens ≤768px, the header SHALL collapse navigation into a hamburger
button (☰) that opens a slide-in sidebar from the left. The footer SHALL
render on the green gradient `#55964a → #478540` with white text: only the
copyright line «© YYYY B2B Call CRM» centered.

#### Scenario: Кнопка «Создать» с выпадающим списком
- **WHEN** вошедший пользователь открывает страницу с шапкой
- **THEN** справа отображается кнопка «Создать ▾»
- **AND** при нажатии на кнопку открывается выпадающий список
- **AND** в списке отображаются пункты: Организацию, Контакт, Звонок, Рассылку

#### Scenario: Выпадающий список для менеджера
- **WHEN** пользователь с ролью `ROLE_MANAGER` открывает выпадающий список «Создать»
- **THEN** в списке отображается пункт «Группу»

#### Scenario: Выпадающий список для администратора
- **WHEN** пользователь с ролью `ROLE_ADMIN` открывает выпадающий список «Создать»
- **THEN** в списке отображается пункт «Пользователя»

#### Scenario: Закрытие выпадающего списка «Создать»
- **WHEN** выпадающий список «Создать» открыт и пользователь нажимает вне списка
- **THEN** выпадающий список закрывается

#### Scenario: Выпадающий список «Админ» для администратора
- **WHEN** пользователь с ролью `ROLE_ADMIN` открывает страницу с шапкой
- **THEN** справа отображается кнопка «⚙ Админ ▾»
- **AND** при нажатии на кнопку открывается выпадающий список
- **AND** в списке отображаются пункты: Пользователи, Скрытые организации

#### Scenario: Список «Админ» не виден другим ролям
- **WHEN** пользователь без роли `ROLE_ADMIN` открывает страницу с шапкой
- **THEN** кнопка «⚙ Админ ▾» не отображается

#### Scenario: Закрытие выпадающего списка «Админ»
- **WHEN** выпадающий список «Админ» открыт и пользователь нажимает вне списка
- **THEN** выпадающий список закрывается

#### Scenario: Выпадающий список пользователя
- **WHEN** вошедший пользователь открывает страницу с шапкой
- **THEN** справа отображается кнопка «Профиль» с символом ▾
- **AND** при нажатии на кнопку открывается выпадающий список
- **AND** первым пунктом списка отображается логин пользователя
- **AND** вторым пунктом отображаются имя и фамилия пользователя (если указаны)
- **AND** третьим пунктом отображается email пользователя (если указан)
- **AND** в списке отображается ссылка «Выйти»

#### Scenario: Закрытие выпадающего списка пользователя
- **WHEN** выпадающий список пользователя открыт и пользователь нажимает вне списка
- **THEN** выпадающий список закрывается

#### Scenario: Гамбургер-меню на мобильных
- **WHEN** пользователь открывает страницу на экране ≤768px
- **THEN** навигация и кнопки созданий скрыты
- **AND** отображается кнопка-гамбургер (☰)

#### Scenario: Боковая панель на мобильных
- **WHEN** пользователь нажимает на кнопку-гамбургер
- **THEN** слева выезжает боковая панель со всеми пунктами меню
- **AND** боковая панель содержит: пункты навигации, кнопку «Создать ▾», блок пользователя с «Выйти»
- **AND** за пределами панели отображается полупрозрачный оверлей

#### Scenario: Закрытие боковой панели
- **WHEN** боковая панель открыта и пользователь нажимает на оверлей
- **THEN** боковая панель закрывается

#### Scenario: Навигация в шапке
- **WHEN** пользователь открывает страницу с шапкой
- **THEN** логотип «B2B Call CRM» находится слева и ведёт на главную страницу
- **AND** пункты навигации отображаются в верхней строке на белом фоне

#### Scenario: Подвал на зелёном градиенте
- **WHEN** пользователь прокручивает страницу до подвала
- **THEN** подвал отображается на зелёном градиенте `#55964a → #478540`
- **AND** текст подвала белый
- **AND** в подвале отображается только строка копирайта «© YYYY B2B Call CRM» по центру

### Requirement: Единый стиль всех страниц
The system SHALL render every page — including the login page, welcome
page, dashboard, lists and forms — with the same base layout: shared
header, footer, palette, typography and components. No page SHALL receive
a distinct visual treatment.

#### Scenario: Страница входа в общем стиле
- **WHEN** пользователь открывает страницу входа
- **THEN** страница использует те же шапку и подвал, что и остальные страницы
- **AND** поля входа — поля с нижним подчёркиванием `#d66a2b`
- **AND** кнопка входа — оранжевая градиентная «пилюля»

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
