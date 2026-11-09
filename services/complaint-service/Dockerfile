# syntax=docker/dockerfile:1
#
# Image de complaint-service (PHP 8.5, comme en local ; composer.lock demande PHP >= 8.4).
# Le client Eureka partagé (../../shared/php-eureka) est hors du dossier du service : il arrive par le contexte
# de build nommé "shared" (additional_contexts dans infra/docker-compose.yml, ou à la main :
#   docker build --build-context shared=../../shared -t complaint-service .
# depuis services/complaint-service).
#
# Le code est placé dans /srv/services/complaint-service et le client partagé dans /srv/shared, pour que le chemin
# PSR-4 "../../shared/php-eureka/src" de composer.json reste valable dans l'image.

FROM php:8.5-cli-alpine AS base
# curl est déjà compilé dans l'image officielle ; pdo_mysql pour Doctrine, pcntl pour que docker stop
# (SIGTERM) retire l'instance d'Eureka.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl \
    && { echo 'opcache.enable_cli=1'; echo 'opcache.validate_timestamps=0'; echo 'memory_limit=256M'; } > /usr/local/etc/php/conf.d/complaint.ini
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVICE_PORT=8106
WORKDIR /srv/services/complaint-service

FROM base AS build
RUN apk add --no-cache unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=shared php-eureka /srv/shared/php-eureka
# Dépendances d'abord (couche en cache tant que composer.lock ne change pas).
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && php bin/console assets:install public --no-interaction \
    && php bin/console cache:warmup \
    && sed -i 's/\r$//' docker-entrypoint.sh && chmod +x docker-entrypoint.sh

FROM base
COPY --from=build /srv /srv
RUN chown -R www-data:www-data var
USER www-data
# php -S traite plusieurs requêtes en parallèle avec ce nombre de processus (Linux).
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8106
ENTRYPOINT ["./docker-entrypoint.sh"]
# serve : migrations puis serveur web. Autre commande (ex. php bin/console app:eureka:run) : lancée telle quelle.
CMD ["serve"]
