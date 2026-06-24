#!/usr/bin/env bash
# Limen — sobe o ambiente de dev completo com um comando.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Subindo containers (MySQL, Redis, Adminer)..."
docker compose up -d

echo "==> Aguardando o MySQL ficar pronto..."
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -proot_dev_pw --silent 2>/dev/null; do
  printf '.'; sleep 2
done
echo " MySQL pronto."

if [ ! -d vendor ]; then
  echo "==> composer install..."
  composer install
fi

if [ ! -f .env ]; then
  echo "==> criando .env a partir do exemplo..."
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64' .env; then
  echo "==> gerando APP_KEY..."
  php artisan key:generate
fi

echo "==> Rodando migrations..."
php artisan migrate --force

if [ -f package.json ]; then
  echo "==> npm install..."
  npm install
fi

echo ""
echo "✅ Ambiente Limen pronto."
echo "   App:     php artisan serve   ->  http://localhost:8000"
echo "   Adminer: http://localhost:8080   (sistema: MySQL | servidor: mysql | usuário: limen | senha: limen_dev_pw | base: limen)"
