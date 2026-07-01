# Go-Live Checklist — limen.com.br

Gerado na Fase 12. Itens de **Código** já executados pelos agentes; os demais são
ações manuais de Robson.

## Código (feito nesta fase)
- [x] i18n PT-BR completo (`lang/pt_BR/auth|pagination|passwords|validation.php`)
- [x] `config/app.php` locale/fallback/faker → `pt_BR`
- [x] Zero strings em inglês visíveis ao usuário (Vue e Blade auditados)
- [x] Páginas de erro customizadas PT-BR no design Limen (404/403/500/419)
- [x] Middleware `SecurityHeaders` criado e registrado em `bootstrap/app.php`
- [x] `config/cors.php` restrito às origens do app
- [x] 161/161 testes verdes
- [x] Bug "senha errada exibe inglês" corrigido (mensagem `confirmed` em PT-BR)
- [x] `.github/workflows/deploy.yml` (CI: testes + build; CD: SSH deploy)
- [x] `deploy.sh` (deploy manual) e `docs/backup.sh` (template de backup)
- [x] `.env.production.example` (template sem segredos)

## Infraestrutura (Robson faz)
- [ ] Criar servidor Hetzner Cloud CX22 (Nuremberg), Ubuntu 24.04
- [ ] Anotar IP público
- [ ] DNS: `A limen.com.br → IP`
- [ ] DNS: `A www.limen.com.br → IP`
- [ ] SSH no servidor; instalar PHP 8.3+, MySQL 8.4, Redis, Nginx, Node 20, Composer, Supervisor
- [ ] Criar usuário `deploy` e chave SSH dedicada
- [ ] Clonar repo em `/var/www/limen`
- [ ] `cp .env.production.example .env` e preencher TODOS os segredos
- [ ] `php artisan key:generate`
- [ ] `php artisan migrate --force`
- [ ] `npm ci && npm run build`
- [ ] `php artisan config:cache route:cache view:cache event:cache`
- [ ] Configurar Nginx (root em `public/`) + Certbot (Let's Encrypt) para SSL
- [ ] Configurar Supervisor para `limen-worker` (fila Redis)
- [ ] Instalar `docs/backup.sh` em `/home/deploy/backup.sh` e agendar no cron
- [ ] GitHub Secrets: `HETZNER_HOST` (IP), `HETZNER_SSH_KEY` (chave privada do usuário deploy)
- [ ] Primeiro `git push origin main` → verificar CI/CD verde

## Contas externas (Robson faz)
- [ ] Conta Asaas em PRODUÇÃO (não sandbox) → `ASAAS_API_KEY`, `ASAAS_WEBHOOK_TOKEN`
- [ ] Conta Unico ativada para produção → `KYC_API_KEY`, `KYC_WEBHOOK_SECRET`
- [ ] Webhook Asaas (cobrança) → `https://limen.com.br/api/v1/webhooks/asaas`
- [ ] Webhook Asaas (transferência/payout) → `https://limen.com.br/api/webhooks/asaas/transfer`
- [ ] Webhook KYC Unico → `https://limen.com.br/api/v1/webhooks/kyc`
- [ ] Zoho Mail (`contato@limen.com.br`) → `MAIL_*`
- [ ] Uptime Robot monitorando `https://limen.com.br`

## Verificação final (após subir)
- [ ] `https://limen.com.br` carrega (200)
- [ ] SSL válido (cadeado verde) e redirect HTTP→HTTPS
- [ ] `APP_DEBUG=false` e `APP_ENV=production` confirmados
- [ ] `.env` NÃO está no Git
- [ ] Registro de usuário funciona (gate 18+)
- [ ] Login funciona; senha errada exibe mensagem em PT-BR
- [ ] Catálogo carrega
- [ ] Compra de tokens (PIX) funciona
- [ ] Dashboard do performer carrega
- [ ] Página 404 exibe design Limen em PT-BR
