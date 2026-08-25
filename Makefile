export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)

DC := docker compose
RUN := $(DC) run --rm php

.PHONY: build up down shell install update test test-coverage cs stan

build:
	$(DC) build

start:
	$(DC) up -d

stop:
	$(DC) stop

down:
	$(DC) down

shell:
	$(RUN) sh

install:
	$(RUN) composer install

update:
	$(RUN) composer update

test:
	$(RUN) vendor/bin/phpunit

test-coverage:
	$(DC) run --rm -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-text

cs:
	$(RUN) composer cs

stan:
	$(RUN) composer stan
