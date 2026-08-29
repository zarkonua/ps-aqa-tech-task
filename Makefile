# this targets aren't file-dependent so always out-of date
.PHONY: clean composer cs-check cs-fix destroy down install migrate shell stan test up

# If you're changing these versions, don't forget to update Dockerfile
COMPOSER_VER := 2.9.3

# just a shortcut for long docker command string
DOCKER := docker run --rm -it -v $(shell pwd):/app -w /app

LOCAL_IP := $(shell hostname -I 2>/dev/null | awk '{print $$1}') # Ubuntu
# LOCAL_IP := $$(ipconfig getifaddr en0)   - MacOs

clean:
	rm -rf vendor
	rm -rf var/cache/*

composer:
	${DOCKER} composer:${COMPOSER_VER} bash

cs-check:
	docker compose run --rm -e PHP_CS_FIXER_IGNORE_ENV=1 php-cli vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	docker compose run --rm -e PHP_CS_FIXER_IGNORE_ENV=1 php-cli vendor/bin/php-cs-fixer fix --diff

destroy: clean
	docker compose down -v --rmi local

down:
	docker compose down

install:
	${DOCKER} composer:${COMPOSER_VER} install

migrate:
	docker compose run --rm php-cli /bin/sh -c " \
		php bin/console --no-interaction doctrine:migrations:migrate; \
		php bin/console --env=test --no-interaction doctrine:migrations:migrate \
	"

shell:
	docker compose exec php-web /bin/bash

stan:
	docker compose run --rm php-cli bin/phpstan

test:
	docker compose run --rm -e APP_ENV=test php-cli bin/phpunit

up:
	LOCAL_IP=${LOCAL_IP} docker compose up -d
