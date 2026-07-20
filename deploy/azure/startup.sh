#!/usr/bin/env bash
# Azure App Service startup command. Set this as the "Startup Command" under
# App Service -> Configuration -> General settings:
#     /home/site/wwwroot/deploy/azure/startup.sh
#
# It swaps in the public/ docroot nginx config and reloads nginx on every
# container start.
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
