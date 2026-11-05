#!/bin/sh
set -u

cd /var/www/html

while :; do
    php console/cli.php notifications:run
    exit_code=$?
    if [ "$exit_code" -ne 0 ]; then
        echo "SakayLokal notification worker exited with status ${exit_code}." >&2
    fi
    sleep 60
done
