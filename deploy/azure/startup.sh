#!/usr/bin/env bash
# Azure App Service startup command. Set this as the "Startup Command" under
# App Service -> Configuration -> General settings:
#     /home/site/wwwroot/deploy/azure/startup.sh
#
# It swaps in the public/ docroot nginx config, raises the PHP upload limits to
# match, and reloads nginx on every container start.
set -e

CONF_SRC=/home/site/wwwroot/deploy/azure/nginx-root-public.conf
CONF_DST=/etc/nginx/sites-available/default

if [ -f "$CONF_SRC" ]; then
    cp "$CONF_SRC" "$CONF_DST"
    echo "startup.sh: installed public/ docroot nginx config"
    nginx -t && (nginx -s reload 2>/dev/null || service nginx reload 2>/dev/null || service nginx restart 2>/dev/null || true)
else
    echo "startup.sh: WARNING $CONF_SRC not found — using default docroot"
fi

# Room photo uploads are capped at 5 MB by RoomPhotoManager, but PHP ships with
# upload_max_filesize=2M — so a 3 MB phone photo was rejected as
# UPLOAD_ERR_INI_SIZE and reported to the admin as "must be 5 MB or smaller",
# which is not what went wrong. Kept above 5 MB (and under nginx's 8m) so the
# application limit stays the one that decides. Written into the image's own
# scanned conf.d, so no PHP_INI_SCAN_DIR app setting is needed.
for PHP_CONF_DIR in /usr/local/etc/php/conf.d /etc/php/8.2/fpm/conf.d; do
    if [ -d "$PHP_CONF_DIR" ]; then
        cat > "$PHP_CONF_DIR/belive-uploads.ini" <<'INI'
upload_max_filesize = 8M
post_max_size = 10M
max_file_uploads = 20
INI
        echo "startup.sh: raised PHP upload limits in $PHP_CONF_DIR"
        break
    fi
done

# Only meaningful if php-fpm is already up; on a cold start the ini above is
# read when it launches. Never allowed to fail the startup command.
(kill -USR2 "$(cat /var/run/php-fpm.pid 2>/dev/null)" 2>/dev/null \
    || service php8.2-fpm reload 2>/dev/null \
    || true) >/dev/null 2>&1
