FROM php:8.2-apache

# Dépendances système et extensions PHP requises pour Laravel,
# PostgreSQL (Supabase), MySQL et IBM DB2
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    git \
    build-essential \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        zip \
        bcmath \
        opcache \
    && a2enmod rewrite

# Configuration IBM DB2 CLI Driver
ENV IBM_DB_DIR=/opt/clidriver
ENV LD_LIBRARY_PATH=/opt/clidriver/lib:${LD_LIBRARY_PATH}

RUN mkdir -p /opt \
    && curl -fsSL https://public.dhe.ibm.com/ibmdl/export/pub/software/data/db2/drivers/odbc_cli/linuxx64_odbc_cli.tar.gz \
        -o /tmp/clidriver.tar.gz \
    && tar -xzf /tmp/clidriver.tar.gz -C /opt \
    && rm -f /tmp/clidriver.tar.gz \
    && echo "/opt/clidriver/lib" > /etc/ld.so.conf.d/clidriver.conf \
    && ldconfig \
    && echo "/opt/clidriver" | pecl install ibm_db2 \
    && docker-php-ext-enable ibm_db2 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Installation de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Configuration Apache (fichier à la racine du dépôt)
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Copie des fichiers backend
COPY backend/ /var/www/html

# Installation des dépendances PHP
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# Permissions Laravel
RUN chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && chmod -R 775 \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

# Script de démarrage (fichier à la racine du dépôt)
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
