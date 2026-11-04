#!/usr/bin/env bash

# Campus Lost & Found deployment setup
# Ubuntu / Debian
# Usage: sudo bash deploy/setup.sh

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run with sudo: sudo bash deploy/setup.sh"
    exit 1
fi

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="/var/www/campus-lost-found"

echo "==> Installing required packages"

apt-get update

DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    libapache2-mod-php \
    php \
    php-mysql \
    php-mbstring \
    mariadb-server

echo "==> Starting MariaDB"

systemctl enable --now mariadb

echo "==> Copying application files"

mkdir -p "$DEST"

if [ "$SRC" != "$DEST" ]; then
    cp -a "$SRC/." "$DEST/"
fi

echo "==> Creating required directories"

mkdir -p "$DEST/public/uploads/items"
mkdir -p "$DEST/storage/evidence"

echo "==> Setting file permissions"

chown -R root:root "$DEST"

find "$DEST" -type d -exec chmod 755 {} \;
find "$DEST" -type f -exec chmod 644 {} \;

chown -R www-data:www-data \
    "$DEST/public/uploads/items" \
    "$DEST/storage/evidence"

chmod 750 "$DEST/storage/evidence"

echo "==> Checking application configuration"

if [ ! -f "$DEST/config/config.php" ]; then
    cp "$DEST/config/config.example.php" "$DEST/config/config.php"
    chown root:www-data "$DEST/config/config.php"
    chmod 640 "$DEST/config/config.php"

    echo
    echo "IMPORTANT: Edit $DEST/config/config.php"
    echo "Set your database password before using the application."
fi

echo "==> Enabling Apache site"

cp "$DEST/deploy/campus-lost-found.conf" \
    /etc/apache2/sites-available/campus-lost-found.conf

a2enmod rewrite

a2dissite 000-default >/dev/null 2>&1 || true
a2ensite campus-lost-found

apache2ctl configtest

systemctl restart apache2

echo
echo "=========================================="
echo "Deployment setup completed."
echo "=========================================="
echo
echo "Application directory: $DEST"
echo "Web directory: $DEST/public"
echo
echo "Next steps:"
echo "1. Configure config/config.php."
echo "2. Create the database and import database/schema.sql."
echo "3. Configure the database user."
echo "4. Change the default admin and custodian passwords."
echo
echo "Open: http://localhost/"
