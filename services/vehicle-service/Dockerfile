# syntax=docker/dockerfile:1
#
# Image de vehicle-service (Next.js, Prisma, Node 22).
# Lancement normal : infra/docker-compose.yml, profil "services" (variables d'environnement fournies par compose).
# Le contrat docs/openapi/vehicle-service.yaml (servi par /openapi.yaml, affiché par /docs) est hors du dossier du
# service : il arrive par le contexte de build nommé "docs" (additional_contexts dans compose, ou à la main depuis
# services/vehicle-service :  docker build --build-context docs=../../docs -t location-voitures/vehicle-service .).
# Le code est dans /srv/services/vehicle-service et le contrat dans /srv/docs : le chemin ../../docs reste valable.
#
# Une seule image, deux conteneurs (commande du conteneur) :
#   (par défaut)              prisma migrate deploy (nouvel essai tant que MySQL ne répond pas), puis next start ;
#                             instrumentation.ts lit le Config Server et inscrit RENTAL-VEHICLE dans Eureka
#   ./node_modules/.bin/tsx consumer/index.ts   consommateur RabbitMQ (vehicle.contract_events)
FROM node:22-bookworm-slim

# openssl : requis par le moteur Prisma
RUN apt-get update && apt-get install -y --no-install-recommends openssl && rm -rf /var/lib/apt/lists/*
ENV NEXT_TELEMETRY_DISABLED=1 \
    SERVICE_PORT=8102
WORKDIR /srv/services/vehicle-service

# Dépendances d'abord (couche en cache tant que package-lock.json ne change pas).
# Toutes les dépendances : prisma (migrations) et tsx (consommateur) sont des devDependencies.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --from=docs openapi/vehicle-service.yaml /srv/docs/openapi/vehicle-service.yaml
COPY . .
RUN npx prisma generate && npm run build \
    && chown -R node:node /srv/services/vehicle-service
# NEXT_MANUAL_SIG_HANDLE : Next.js ne quitte pas lui-même sur SIGTERM (docker stop) ; lib/eureka.ts retire
# d'abord l'instance d'Eureka, puis arrête le processus.
ENV NODE_ENV=production \
    NEXT_MANUAL_SIG_HANDLE=true
USER node

EXPOSE 8102
CMD ["sh", "-c", "for i in $(seq 1 20); do npx prisma migrate deploy && break; echo 'MySQL pas encore prêt, nouvel essai dans 3 s' >&2; sleep 3; done && exec ./node_modules/.bin/next start -H 0.0.0.0 -p ${SERVICE_PORT}"]
