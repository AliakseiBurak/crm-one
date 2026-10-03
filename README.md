# B2B Call CRM — локальное окружение (Docker)

Docker-окружение для разработки: PHP 8.5 FPM, nginx (TLS), MySQL 8.4, Mailpit,
PHPMyAdmin, Playwright e2e. Приложение доступно только по HTTPS: `https://b2b-crm.local`.
БД удобно смотреть в PHPMyAdmin: `http://localhost:8080`.

Учётные данные fixtures:

| Роль      | Email                    | Пароль      |
|-----------|--------------------------|-------------|
| Админ     | `admin@b2b-crm.loc`      | `admin123`  |
| Менеджер  | `manager@b2b-crm.loc`    | `manager123`|

## Требования

- Linux, Docker с Compose v2 (`docker compose version`), `make`
- Порты: 443 (HTTPS), 3306 (MySQL), 8025 (Mailpit UI), 8080 (PHPMyAdmin) —
  свободны

## Первый запуск

1. Настроить окружение (один раз):

   ```bash
   cp .env.example .env
   echo "127.0.0.1 b2b-crm.local" | sudo tee -a /etc/hosts
   ```

2. Поднять контейнеры:

   ```bash
   make up
   ```

   При первом старте контейнер `php` сам выполнит: `composer install`,
   генерацию CA и серверного сертификата в volume `/certs`, миграции
   (`doctrine:migrations:migrate`) и загрузку fixtures (`ENABLE_FIXTURES=1`).

3. Довериться CA в системном хранилище (Linux, один раз):

   ```bash
   docker compose exec php cat /certs/ca.crt > /tmp/b2b-crm-ca.crt
   sudo mv /tmp/b2b-crm-ca.crt /usr/local/share/ca-certificates/b2b-crm-ca.crt
   sudo update-ca-certificates
   ```

   Если браузер всё равно не доверяет сертификату — импортируйте `ca.crt`
   вручную в хранилище браузера (Chrome: `chrome://settings/security` →
   «Управление сертификатами»).

4. Открыть `https://b2b-crm.local` (логин см. выше). Mailpit UI —
   `http://localhost:8025`. PHPMyAdmin — `http://localhost:8080` (логин:
   `MYSQL_USER`/`MYSQL_PASSWORD` из `.env`).

   > Про `.local`: Chrome/Edge используют mDNS-резолвер для `*.local`; на
   > Linux запись в `/etc/hosts` имеет приоритет, но если браузер не
   > открывает имя — очистите кэш DNS браузера или откройте с другим
   > браузером (Firefox).

## Повседневные команды

| Команда                 | Действие                                           |
|-------------------------|----------------------------------------------------|
| `make up`               | Поднять все сервисы (`php`, `nginx`, `mysql`, `mailpit`, `phpmyadmin`) |
| `make down`             | Остановить сервисы (данные БД сохраняются)         |
| `make migrate`          | Применить миграции                                 |
| `make fixtures`         | Перезагрузить fixtures                             |
| `make e2e`              | Запустить Playwright smoke-тесты (профиль `e2e`)   |
| `make exec`             | Войти в контейнер `php` пользователем `app` (`docker compose exec --user app php bash`) |
| `make app-send`         | Один прогон отправки рассылок (`app:campaign:send`) |
| `make app-archive-logs` | Архивирование журнала отправки писем по годам (`app:mailer-logs:archive`) |
| `make app-scheduler`    | Локально подержать Symfony Scheduler ~60 с (`messenger:consume scheduler_default`) |

## E2E-тесты

Сервис `e2e` (образ Playwright) запускается в сети compose и открывает
`https://b2b-crm.local` (внутри сети имя резолвится Docker DNS):

```bash
make e2e
```

URL по умолчанию можно переопределить переменной `BASE_URL` — shell-переменная
перекрывает значение из `.env`:

```bash
BASE_URL=https://host.docker.internal make e2e
```

Этот вариант нужен, когда у хоста нет записи `b2b-crm.local` в `/etc/hosts`
(например, docker поднят на другом хосте; стандартный HTTPS-порт проброшен
через `host.docker.internal`).

### Запуск из контейнера OpenCode

В контейнере OpenCode docker обычно недоступен, поэтому `make e2e` выполняется
на хосте. Если в OpenCode-контейнере есть Node и доступен `host.docker.internal`:

```bash
cd e2e
npm install
npx playwright install chromium
BASE_URL=https://host.docker.internal npm test
```

## Отправка рассылок (worker)

- **A.** systemd / Supervisord → `messenger:consume scheduler_default` → раз в минуту `SendCampaignBatch`
- **B.** crontab `* * * * *` → `php bin/console app:campaign:send`

### Локально (Docker)

```bash
# or run app:campaign:send
make app-send
# or run app:mailer-logs:archive
make app-archive-logs
# or run messenger:consume scheduler_default
make app-scheduler
```

### Сервер без Docker

Нужен **один** из вариантов.

**A. Оставить Scheduler** — процесс-менеджер (systemd или Supervisord), не cron:

```bash
php bin/console messenger:consume scheduler_default --time-limit=3600
```

**B. Проще по эксплуатации** — cron раз в минуту вызывает команду (lock не даст наложиться, если прогон длится дольше минуты):

```cron
* * * * * cd /var/www/b2b-call-crm && /usr/bin/php bin/console app:campaign:send
5 0 * * * cd /var/www/b2b-call-crm && /usr/bin/php bin/console app:mailer-logs:archive
```

Тогда `messenger:consume scheduler_default` на сервере не запускайте.

Вторая строка — архивирование журнала отправки писем по годам. При варианте
**А** она выполняется самим Scheduler (`messenger:consume scheduler_default`),
поэтому в cron добавляется только при варианте **B**. Команда идемпотентна и
безвредна, если запускать её чаще.

В окружении CLI задайте `DEFAULT_URI` (абсолютный URL для tracking-pixel в письмах; без HTTP-запроса Symfony его не угадает).

## Пересоздание окружения (сброс)

Данные БД и сертификаты живут в named volumes (`mysql-data`, `certs`).

⚠️ **`docker compose down -v` удаляет БД И сертификаты.** После него:

- БД и fixtures будут пересозданы автоматически при старте;
- CA станет новым — нужно заново повторить шаг 3 из «Первого запуска»
  (иначе браузеры и curl будут ругаться на сертификат).

> При смене имени сайта (например, `b2b-crm.loc` → `b2b-crm.local`)
> обязательно удалите volume `certs`: скрипт генерации идемпотентен и не
> пересоздаст сертификат со старым SAN, пока файлы в volume существуют.

Полный сброс:

```bash
make down
docker volume rm b2b-crm_certs b2b-crm_mysql-data
make up
```

## Полезное

- Логи: `docker compose logs -f php nginx`
- Оболочка в контейнере (пользователь `app`, uid/gid 1000 — как на хосте): `make exec`
- Включить/выключить fixtures при старте: `ENABLE_FIXTURES=0/1` в `.env`
  (после изменения — `docker compose up -d --force-recreate php`)
