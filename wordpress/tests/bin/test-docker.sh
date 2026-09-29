#!/usr/bin/env bash
set -euo pipefail

cleanup() {
	docker compose down -v
}

trap cleanup EXIT

docker compose up -d mysql

for attempt in $(seq 1 60); do
	if docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -uroot -proot >/dev/null 2>&1; then
		break
	fi

	if [ "$attempt" -eq 60 ]; then
		echo "MySQL did not become ready in time." >&2
		exit 1
	fi

	sleep 2
done

WP_TESTS_DB_NAME=wordpress_test \
	WP_TESTS_DB_USER=root \
	WP_TESTS_DB_PASS=root \
	WP_TESTS_DB_HOST=127.0.0.1:3307 \
	WP_TESTS_SKIP_DB_CREATE=true \
	composer test
