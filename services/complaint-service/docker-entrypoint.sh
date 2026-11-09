#!/bin/sh
# Démarrage du conteneur complaint-service.
#   serve (par défaut) : migrations Doctrine (en attendant MySQL), puis serveur web sur 0.0.0.0:$SERVICE_PORT.
#   toute autre commande : exécutée telle quelle (ex. php bin/console app:eureka:run pour le conteneur Eureka).
set -e

if [ "$1" = "serve" ]; then
    tries=0
    until php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Migrations impossibles : MySQL toujours injoignable après 60 s." >&2
            exit 1
        fi
        echo "MySQL pas encore prêt, nouvel essai dans 2 s ($tries/30)" >&2
        sleep 2
    done
    echo "complaint-service écoute sur 0.0.0.0:${SERVICE_PORT:-8106}"
    exec php -S "0.0.0.0:${SERVICE_PORT:-8106}" -t public
fi

exec "$@"
