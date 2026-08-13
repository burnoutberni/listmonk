#!/usr/bin/env bash
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sL "$1" > "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "curl or wget is required" >&2
		exit 1
	fi
}

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"
	local archive="${TMPDIR}/wordpress.tar.gz"
	download "https://wordpress.org/${WP_VERSION}.tar.gz" "$archive"
	tar --strip-components=1 -zxmf "$archive" -C "$WP_CORE_DIR"
}

install_test_suite() {
	if [ -f "$WP_TESTS_DIR/includes/functions.php" ]; then
		return
	fi

	rm -rf "$WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"

	if command -v svn >/dev/null 2>&1; then
		svn checkout --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn checkout --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	else
		local archive="${TMPDIR}/wordpress-develop.zip"
		local extract_dir="${TMPDIR}/wordpress-develop-extract"
		local source_dir
		download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip" "$archive"
		rm -rf "$extract_dir"
		mkdir -p "$extract_dir"
		unzip -q "$archive" -d "$extract_dir"
		source_dir=$(find "$extract_dir" -mindepth 1 -maxdepth 1 -type d -name 'wordpress-develop-*' -print -quit)
		mv "${source_dir}/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		mv "${source_dir}/tests/phpunit/data" "$WP_TESTS_DIR/data"
		rm -rf "$extract_dir"
	fi

	write_config
	return
}

write_config() {
	download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

	perl -i -pe "s:dirname\( __FILE__ \) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
	perl -i -pe "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	perl -i -pe "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	perl -i -pe "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	perl -i -pe "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_wp
install_test_suite
write_config
create_db

echo "WordPress tests installed in $WP_TESTS_DIR using WordPress core at $WP_CORE_DIR"
