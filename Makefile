.PHONY: help up build down migrate fixtures styles exec e2e test logs app-send app-scheduler dev-log mysql-log-config mysql-log-drop mysql-log-tail stan stan-baseline cs cs-dry infection infection-coverage quality

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Docker:"
	@echo "  up                Start containers"
	@echo "  build             Build containers"
	@echo "  down              Stop containers"
	@echo "  exec              Open shell in php container"
	@echo "  logs              Tail container logs"
	@echo ""
	@echo "Application:"
	@echo "  migrate           Run Doctrine migrations"
	@echo "  fixtures          Load fixtures"
	@echo "  styles            Build frontend assets"
	@echo "  app-send          Run campaign send command"
	@echo "  app-scheduler     Run Messenger scheduler"
	@echo ""
	@echo "Testing & Quality:"
	@echo "  test              Run PHPUnit tests"
	@echo "  e2e               Run E2E tests"
	@echo "  stan              Run PHPStan"
	@echo "  stan-baseline     Regenerate PHPStan baseline"
	@echo "  cs                Fix code style (PHP CS Fixer)"
	@echo "  cs-dry            Check code style (dry-run)"
	@echo "  infection         Run Infection mutation testing"
	@echo "  infection-coverage Run Infection with coverage"
	@echo "  quality           Run all quality checks (cs-dry + stan + infection)"
	@echo ""
	@echo "MySQL logging:"
	@echo "  mysql-log-config  Enable general query log"
	@echo "  mysql-log-drop    Clear query log"
	@echo "  mysql-log-tail    Tail query log"
	@echo ""
	@echo "Production Deploy"
	@echo "  prod-deploy       Run deployment in production server"

up:
	docker compose up -d

build:
	docker compose build

down:
	docker compose down

migrate:
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	docker compose exec php php bin/console doctrine:fixtures:load --no-interaction

styles:
	npm run build

exec:
	docker compose exec --user app php bash

e2e:
	docker compose --profile e2e run --rm e2e

test:
	docker compose exec --user app php php bin/phpunit

logs:
	docker compose logs -f

app-send:
	docker compose exec php php bin/console app:campaign:send

app-scheduler:
	docker compose exec php php bin/console messenger:consume scheduler_default --time-limit=60 -vv

dev-log:
	docker compose exec php tail -f var/log/dev.log

mysql-log-config:
	docker compose exec mysql touch /var/log/query.log
	docker compose exec mysql chown mysql:mysql /var/log/query.log
	docker compose exec mysql mysql -uroot -p$${MYSQL_ROOT_PASSWORD} -e "SET global log_output = 'FILE'; SET global general_log_file='/var/log/query.log'; SET global general_log = 1;"

mysql-log-drop:
	docker compose exec mysql sh -c '> /var/log/query.log'

mysql-log-tail:
	docker compose exec mysql tail -f /var/log/query.log

# Статический анализ
stan:
	docker compose exec --user app php vendor/bin/phpstan analyse --no-progress

stan-baseline:
	docker compose exec --user app php vendor/bin/phpstan analyse --generate-baseline

cs:
	docker compose exec --user app php vendor/bin/php-cs-fixer fix --diff

cs-dry:
	docker compose exec --user app php vendor/bin/php-cs-fixer fix --dry-run --diff

infection:
	docker compose exec --user app php vendor/bin/infection --threads=max --no-progress

infection-coverage:
	docker compose exec --user app php vendor/bin/phpunit --coverage-xml=var/coverage/coverage-xml --log-junit=var/coverage/junit.xml
	docker compose exec --user app php vendor/bin/infection --coverage=var/coverage --threads=max

quality: cs-dry stan infection

prod-deploy:
	git pull
	/opt/alt/php85/usr/bin/php bin/console doctrine:migrations:migrate
	npm run build
	/opt/alt/php85/usr/bin/php bin/console cache:clear
