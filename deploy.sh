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

# `composer install --no-dev` remove os pacotes de dev de vendor/. Se algum
# arquivo em vendor/ ficou com dono != deploy (ex.: www-data de um deploy
# anterior, ou root de um seed rodado como root), o composer não consegue
# apagá-lo e o deploy quebra ("Could not delete .../vendor/..."). Reassumir a
# posse de vendor/ ANTES do composer torna o passo idempotente.
echo "▶ Normalizando posse de vendor/"
sudo chown -R deploy:deploy "$APP_DIR/vendor"

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

# Garante o cron do scheduler do Laravel (idempotente). Sem ele o
# payments:reconcile não roda — e ele é o plano B do crédito de tokens
# (webhook perdido/inalcançável). O guard evita duplicar a linha.
echo "▶ Garantindo cron do scheduler"
crontab -l 2>/dev/null | grep -Fq 'artisan schedule:run' || \
  ( crontab -l 2>/dev/null; echo "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -

echo "▶ Reiniciando workers de fila"
sudo supervisorctl restart limen-worker:*

echo "✅ Deploy manual concluído: $(date)"
