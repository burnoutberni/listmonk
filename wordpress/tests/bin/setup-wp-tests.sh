#!/usr/bin/env bash
set -euo pipefail

TMPDIR=${TMPDIR-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress}

DB_NAME=${WP_TESTS_DB_NAME-wordpress_test}
DB_USER=${WP_TESTS_DB_USER-root}
DB_PASS=${WP_TESTS_DB_PASS-}
DB_HOST=${WP_TESTS_DB_HOST-localhost}
WP_VERSION=${WP_VERSION-latest}
SKIP_DB_CREATE=${WP_TESTS_SKIP_DB_CREATE-false}

if [ -f "${WP_TESTS_DIR}/includes/functions.php" ] && [ -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
	echo "WordPress tests already installed in ${WP_TESTS_DIR}"
	exit 0
fi

WP_TESTS_DIR="${WP_TESTS_DIR}" WP_CORE_DIR="${WP_CORE_DIR}" tests/bin/install-wp-tests.sh "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" "${SKIP_DB_CREATE}"
