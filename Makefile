.PHONY: up down logs backup restore build

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f

backup:
	bash scripts/backup.sh

restore:
	@test -n "$(TS)" || (echo "Usage: make restore TS=<timestamp>"; exit 1)
	bash scripts/restore.sh $(TS)

build:
	docker compose build --no-cache
