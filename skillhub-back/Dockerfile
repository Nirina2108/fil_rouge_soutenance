# ─── Backend Laravel — PHP 8.2 + ext-mongodb ──────────────────────────────────
FROM php:8.2-cli

WORKDIR /app

# ── Dépendances système ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
        git \
        curl \
        unzip \
        libzip-dev \
        libssl-dev \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

# ── Extensions PHP standard ────────────────────────────────────────────────────
RUN docker-php-ext-install pdo pdo_mysql zip

# ── Extension MongoDB via PECL ─────────────────────────────────────────────────
# Installe la version 1.21+ requise par mongodb/laravel-mongodb 5.7
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# ── Xdebug pour coverage tests ──────────────────────────────────────────────────
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# ── Composer ──────────────────────────────────────────────────────────────────
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ── Code source ───────────────────────────────────────────────────────────────
COPY . .

# ── Dépendances PHP ───────────────────────────────────────────────────────────
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# ── Permissions storage/cache ─────────────────────────────────────────────────
RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# OPcache active : compile PHP en bytecode et le garde en mémoire.
# Sans OPcache, chaque requête recompile tous les fichiers PHP (Laravel = 1500+ fichiers),
# ce qui ajoute 200-500ms par requete. Avec OPcache : compilation 1 fois au démarrage.
RUN docker-php-ext-install opcache
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.validate_timestamps=1"; \
    echo "opcache.revalidate_freq=0"; \
    echo "opcache.jit=1255"; \
    echo "opcache.jit_buffer_size=64M"; \
} > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 8001

# Multi-worker pour php artisan serve : 8 requêtes en parallèle au lieu d'une.
# Sans cela, une création de formation peut queuer 30+ sec derrière les polls
# /messages/non-lus de la Navbar (qui pinge toutes les 5s) et dépasser le
# timeout axios côté front. 8 workers = file vide en pratique.
ENV PHP_CLI_SERVER_WORKERS=8

# Au démarrage :
#  1. Cree un .env vide si absent (sinon Dotenv emet un warning sur chaque requete).
#  2. Cree le symlink public/storage (sinon fichiers uploades non servis).
#  3. Pre-compile les routes (premiere requete plus rapide). On NE met PAS
#     config:cache car il fige les env au build et casse les tests qui
#     surchargent les variables via phpunit.xml.
#  4. Lance le serveur multi-worker sans reload (production-like).
CMD ["sh", "-c", "touch /app/.env && php artisan storage:link --force && php artisan route:cache && php artisan serve --host=0.0.0.0 --port=8001 --no-reload"]
