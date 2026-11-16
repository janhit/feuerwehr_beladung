#!/bin/sh
# Führt die Tests auf dem Test-Server aus (Docker-Projekt /opt/fwb-test).
# Nutzt eine eigene Datenbank "wordpress_tests", nie die Daten der Testseite.
set -eu
cd /opt/fwb-test
NETZ=fwb-test_default
. ./.env
docker compose exec -T db mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS wordpress_tests; GRANT ALL ON wordpress_tests.* TO '$MARIADB_USER'@'%';"
WP="docker compose run --rm -T -e WORDPRESS_DB_NAME=wordpress_tests wpcli wp"
$WP core is-installed 2>/dev/null || $WP core install --url=https://fwb-test.hittig.tech --title=Tests --admin_user=testadmin --admin_password="$(openssl rand -hex 16)" --admin_email=test@fwb-test.hittig.tech --skip-email
echo "== JavaScript"
docker run --rm -v /opt/fwb-test/plugin:/app:ro -w /app node:22-alpine node --test 'tests/js/*.test.js'
echo "== PHP-Syntax"
docker run --rm -v /opt/fwb-test/plugin:/app:ro -w /app php:8.1-cli sh -c "find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l" | grep -v "^No syntax errors" || true
echo "== PHPUnit"
# Kopie außerhalb des Web-Ordners, damit vendor/ nie öffentlich erreichbar ist.
rm -rf /opt/fwb-test/testlauf && cp -a /opt/fwb-test/plugin /opt/fwb-test/testlauf
docker run --rm -v /opt/fwb-test/testlauf:/app -w /app composer:2 composer install --no-interaction --no-progress -q
# Als WordPress-Benutzer (www-data) testen, damit angelegte Ordner der Testseite gehören.
chown -R 33:33 /opt/fwb-test/testlauf
docker run --rm --user 33:33 --network "$NETZ" \
  -v /opt/fwb-test/wordpress:/var/www/html -v /opt/fwb-test/testlauf:/app -w /app \
  -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_NAME=wordpress_tests -e WORDPRESS_DB_USER="$MARIADB_USER" -e WORDPRESS_DB_PASSWORD="$MARIADB_PASSWORD" \
  -e WP_ROOT=/var/www/html wordpress:php8.3-fpm php vendor/bin/phpunit "$@"
