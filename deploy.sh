#!/bin/bash
#
# Deploy manual do Limen (usar apenas se o CI/CD do GitHub Actions falhar).
# Executar no servidor de produção, a partir do usuário `deploy`.
#
set -euo pipefail

APP_DIR="/var/www/limen"

echo "▶ Iniciando deploy manual em $APP_DIR"
cd "$APP_DIR"

# O storage tem 10 arquivos VERSIONADOS (os .gitignore que criam
# storage/app/private, storage/framework/views, storage/logs, etc). O php-fpm
# roda como www-data e reescreve esse subtree, então o `git pull` esbarra em
# arquivo que o deploy não consegue desatar/recriar ("unable to unlink
# storage/app/private/.gitignore: Permission denied"). Reassumir a posse ANTES
# torna o passo idempotente — mesma tática usada em vendor/ e public/build/.
# É -R porque qualquer um dos 10 pode ser o próximo, e o pull também precisa
# poder RECRIAR um que tenha sumido (escrita no diretório, não só no arquivo).
echo "▶ Normalizando posse de storage/ antes do git"
sudo chown -R deploy:deploy "$APP_DIR/storage"

echo "▶ Atualizando código"
git pull origin main

# Devolve o storage ao www-data IMEDIATAMENTE, e não só no chown do fim do
# script: entre o pull e aquele passo correm composer, npm ci, build e migrate
# (minutos), e nesse intervalo o php-fpm que serve o site não conseguiria
# escrever em storage/logs nem compilar view — 500 em request que logue. Com
# esta linha a janela é de ~1s. Comando idêntico ao do fim de propósito: o
# sudoers casa por string exata, então reusar a linha já autorizada não amplia
# a superfície de sudo.
echo "▶ Devolvendo posse de storage/ ao www-data"
sudo chown -R www-data:www-data storage bootstrap/cache

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
# O `npm run build` (Vite) reescreve public/build/. Se algum arquivo ali ficou
# com dono root (build manual rodado como root), o Vite não sobrescreve e o
# build quebra com EACCES. Reassumir a posse antes torna o passo idempotente
# (mesma tática do vendor/). O mkdir -p cobre o primeiro deploy, quando
# public/build/ ainda não existe — roda como deploy (public/ já é do deploy),
# sem sudo, porque o sudoers só libera chown/supervisorctl.
mkdir -p "$APP_DIR/public/build"
sudo chown -R deploy:deploy "$APP_DIR/public/build"
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
