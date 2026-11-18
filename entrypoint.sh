#!/bin/bash
set -e

# Adapter le port Apache selon la variable PORT fournie par Render (par défaut 80)
PORT="${PORT:-80}"
sed -i "s/80/$PORT/g" /etc/apache2/ports.conf
sed -i "s/:80/:$PORT/g" /etc/apache2/sites-available/000-default.conf

# Permissions sur les dossiers d'écriture
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Optimisation et migrations Laravel
echo "Lancement des migrations et du seeder de base de données..."
php artisan migrate --force --seed || echo "Attention: les migrations n'ont pas pu s'exécuter immédiatement."
php artisan storage:link || true

echo "Mise en cache des configurations Laravel..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Démarrage du serveur Apache sur le port $PORT..."
exec apache2-foreground
