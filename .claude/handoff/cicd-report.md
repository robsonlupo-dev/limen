# Relatório CI/CD — Fase 12

**Status:** ✅ Workflow criado e validado (sintaxe/estrutura).

## `.github/workflows/deploy.yml`
- Triggers: `push` e `pull_request` na `main`.
- `concurrency` evita deploys sobrepostos.
- Job **test**: checkout → setup PHP 8.3 (extensões incl. `pdo_sqlite`, `redis`) →
  setup Node 20 → cache Composer → `composer install` → `npm ci` → `npm run build` →
  `.env` a partir de `.env.example` + `key:generate` → `php artisan test`.
- Job **deploy** (`needs: test`, só push na `main`): `appleboy/ssh-action` roda o
  pipeline de deploy no servidor.

## Passo a passo para Robson
1. **Criar servidor** Hetzner CX22 (Nuremberg), Ubuntu 24.04; anotar IP.
2. **DNS:** `A limen.com.br → IP` e `A www.limen.com.br → IP`.
3. **Provisionar** PHP 8.3+, MySQL 8.4, Redis, Nginx, Node 20, Composer, Supervisor,
   Certbot. Criar usuário `deploy` + par de chaves SSH.
4. **App:** clonar em `/var/www/limen`, `cp .env.production.example .env`, preencher
   segredos, `key:generate`, `migrate --force`, `npm ci && npm run build`, caches.
5. **Nginx + SSL:** root em `public/`; `certbot --nginx` para limen.com.br e www.
6. **Supervisor:** worker `limen-worker` para a fila Redis.
7. **GitHub Secrets:** `HETZNER_HOST`, `HETZNER_SSH_KEY`.
8. **Primeiro deploy:** rodar `deploy.sh` manualmente uma vez; depois `git push` na
   `main` deve disparar o CI/CD e concluir o deploy automático.
9. **Verificar:** Actions verde + `curl -I https://limen.com.br` retorna 200 com SSL.
