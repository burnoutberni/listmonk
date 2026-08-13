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

tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3307 latest true
composer test
