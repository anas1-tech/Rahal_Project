FROM php:8.3-apache

# PostgreSQL PDO driver (Neon)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Render sends traffic to $PORT (default 10000)
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

COPY rahal.conf /etc/apache2/conf-available/rahal.conf
RUN a2enconf rahal

COPY public/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000
# Load the database on first start (no-op once tables exist), then start Apache
CMD ["sh", "-c", "php /var/www/html/assets/php/db_init.php; exec apache2-foreground"]
