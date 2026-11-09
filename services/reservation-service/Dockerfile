# syntax=docker/dockerfile:1
#
# Image de reservation-service (Laravel 12, PHP 8.5 comme en local).
# Le client Eureka partagé (../../shared/php-eureka) est hors du dossier du service : il arrive par le contexte
# de build nommé "shared" (additional_contexts dans infra/docker-compose.yml, ou à la main depuis
# services/reservation-service :  docker build --build-context shared=../../shared -t location-voitures/reservation-service .).
#
# Le code est placé dans /srv/services/reservation-service et le client partagé dans /srv/shared, pour que le chemin
# PSR-4 "../../shared/php-eureka/src" de composer.json reste valable dans l'image.
#
# Une seule image, trois conteneurs (commande du conteneur) :
#   serve (par défaut)            migrations, données de démo si la base est vide, API sur 0.0.0.0:$SERVICE_PORT
#   php artisan eureka:run        enregistrement RENTAL-RESERVATION (docker stop le retire d'Eureka)
#   php artisan rabbitmq:consume  consommateur RabbitMQ

FROM php:8.5-cli-alpine AS base
# curl est déjà dans l'image officielle. pdo_mysql : Eloquent ; sockets : php-amqplib (RabbitMQ) ;
# bcmath : calcul des prix ; pcntl : docker stop (SIGTERM) arrête proprement eureka:run et rabbitmq:consume.
RUN apk add --no-cache --virtual .build-deps linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql sockets bcmath pcntl \
    && apk del .build-deps \
    && { echo 'opcache.enable_cli=1'; echo 'opcache.validate_timestamps=0'; echo 'memory_limit=256M'; } > /usr/local/etc/php/conf.d/reservation.ini
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=array \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    SERVICE_PORT=8103
WORKDIR /srv/services/reservation-service

FROM base AS build
RUN apk add --no-cache unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=shared php-eureka /srv/shared/php-eureka
# Dépendances d'abord (couche en cache tant que composer.lock ne change pas).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && php artisan l5-swagger:generate \
    && php artisan route:cache \
    && sed -i 's/\r$//' docker-entrypoint.sh && chmod +x docker-entrypoint.sh

FROM base
COPY --from=build /srv /srv
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data
# php -S traite plusieurs requêtes en parallèle avec ce nombre de processus (Linux seulement).
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8103
ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["serve"]
