#!/bin/sh
# Démarrage d'un conteneur reservation-service.
#   serve (par défaut) : migrations (en attendant MySQL), données de démo si la base est vide,
#                        puis API sur 0.0.0.0:$SERVICE_PORT.
#   toute autre commande : exécutée telle quelle (php artisan eureka:run, php artisan rabbitmq:consume...).
set -e

# Clé Laravel : le service n'utilise ni session ni cookie chiffré, une clé générée au démarrage suffit
# si APP_KEY n'est pas fournie.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
fi

if [ "$1" = "serve" ]; then
    tries=0
    until php artisan migrate --force --no-ansi; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Migrations impossibles : MySQL toujours injoignable après 60 s." >&2
            exit 1
        fi
        echo "MySQL pas encore prêt, nouvel essai dans 2 s ($tries/30)" >&2
        sleep 2
    done
    php artisan demo:seed-if-empty --no-ansi
    echo "reservation-service écoute sur 0.0.0.0:${SERVICE_PORT:-8103}"
    # Routeur de Laravel (comme php artisan serve), lancé depuis public/.
    cd public
    exec php -S "0.0.0.0:${SERVICE_PORT:-8103}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
fi

exec "$@"
