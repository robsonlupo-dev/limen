#!/bin/bash
#
# Deploy manual do Limen (usar apenas se o CI/CD do GitHub Actions falhar).
# Executar no servidor de produção, a partir do usuário `deploy`.
#
set -euo pipefail

APP_DIR="/var/www/limen"

echo "▶ Iniciando deploy manual em $APP_DIR"
cd "$APP_DIR"

echo "▶ Atualizando código"
git pull origin main

echo "▶ Instalando dependências PHP (produção)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ Instalando dependências Node e compilando assets"
npm ci
npm run build

echo "▶ Rodando migrations"
php artisan migrate --force

echo "▶ Recriando caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "▶ Ajustando permissões"
sudo chown -R www-data:www-data storage bootstrap/cache

echo "▶ Reiniciando workers de fila"
sudo supervisorctl restart limen-worker:*

echo "✅ Deploy manual concluído: $(date)"
