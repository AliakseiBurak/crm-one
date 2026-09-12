.PHONY: help up build down migrate fixtures styles exec e2e test logs app-send app-scheduler dev-log mysql-log-config mysql-log-drop mysql-log-tail stan stan-baseline cs cs-dry infection infection-coverage quality

help:
	@echo "help up build down migrate fixtures styles exec e2e test logs app-send app-scheduler mysql-log-config mysql-log-drop mysql-log-tail"

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
