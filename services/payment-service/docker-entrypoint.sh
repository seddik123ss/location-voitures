#!/bin/sh
# serve (par défaut) : migrations (nouvel essai tant que PostgreSQL ne répond pas), puis gunicorn.
# Toute autre commande est lancée telle quelle (ex. python manage.py consume_events).
set -e

if [ "$1" = "serve" ]; then
    tries=0
    until python manage.py migrate --noinput; do
        tries=$((tries + 1))
        if [ "$tries" -ge 20 ]; then
            echo "PostgreSQL injoignable : abandon" >&2
            exit 1
        fi
        echo "PostgreSQL pas encore prêt, nouvel essai dans 3 s ($tries/20)" >&2
        sleep 3
    done
    # Un seul worker : l'inscription Eureka se fait une fois (payments/eureka.py) ; les threads servent les requêtes.
    # exec : gunicorn reçoit SIGTERM (docker stop) et le worker retire l'instance d'Eureka en quittant.
    exec gunicorn config.wsgi:application --bind "0.0.0.0:${SERVICE_PORT:-8104}" \
        --workers 1 --threads 8 --timeout 60 --access-logfile -
fi

exec "$@"
