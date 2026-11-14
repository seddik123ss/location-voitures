# Image de payment-service (Django 5.2, Python 3.12 comme en local).
# Lancement normal : infra/docker-compose.yml, profil "services" (variables d'environnement fournies par compose).
#
# Une seule image, deux conteneurs (commande du conteneur) :
#   serve (par défaut)                    migrations puis API (gunicorn, 1 worker) ; s'inscrit dans Eureka (RENTAL-PAYMENT)
#   python manage.py consume_events       consommateur RabbitMQ (payment.reservation_cancelled, payment.return_settled)
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    SERVICE_PORT=8104
WORKDIR /app

# Dépendances d'abord (couche en cache tant que requirements.txt ne change pas) ; gunicorn remplace runserver.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt gunicorn==23.0.0

COPY manage.py ./
COPY config ./config
COPY payments ./payments
COPY docker-entrypoint.sh ./
RUN sed -i 's/\r$//' docker-entrypoint.sh && chmod +x docker-entrypoint.sh \
    && useradd --system --no-create-home payment && chown -R payment /app
USER payment

EXPOSE 8104
ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["serve"]
