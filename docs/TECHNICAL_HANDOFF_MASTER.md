# LIMEN — TECHNICAL HANDOFF MASTER

> Documento de transferência técnica para uma instância do Claude (ou dev humano) que nunca
> viu o projeto. Gerado a partir de inspeção do código real (`git log`, `route:list`,
> `migrate:status`, árvore de `app/`, `.env.example`, workflow de deploy) em **02/07/2026**.
> Não é resumo de memória — reflete o estado do repositório no commit `afa2e5e`.

---

## RESUMO EXECUTIVO

- **Nome:** Limen — plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
- **Objetivo:** performers verificados (KYC 18+) publicam/atendem; membros compram tokens via
  PIX e gastam em gorjetas/sessões; a plataforma retém um split por nível do performer.
- **Status atual:** Fases 1–12 entregues. Backend de auth, wallet, tokens, PIX (mockável),
  KYC (fake em dev), gorjetas, catálogo, follows, dashboard de performer e payout implementados
  e cobertos por testes. Frontend Inertia+Vue3+Tailwind com as telas públicas e de área logada.
- **Ambiente atual:** `https://limen.dev.br` (staging) rodando em VPS Hetzner, com **deploy
  automático funcionando** (push na `main` → testes → deploy SSH). `limen.com.br` reservado para
  produção futura.
- **Próxima tarefa imediata:** (1) validação manual tela a tela dos FIXes de UX da Fase 12;
  (2) rodar a Operação de QA (popular 50 performers + 100 membros e rodar a bateria). Ver
  `docs/QA_HANDOFF_MASTER.md` e `docs/CURRENT_ISSUES_AND_NEXT_ACTIONS.md`.

**Stack real (composer.json):** PHP `^8.3` (projeto roda em 8.5), Laravel `^13.8`,
Sanctum `^4.3`, Ziggy `^2.6`. Front Inertia + Vue 3 + Tailwind. MySQL 8.4 + Redis via Docker.
Locale padrão `pt_BR`.

---

## ESTADO ATUAL

### [BUILT] — implementado e testado (173 testes verdes, 785 asserts)
- Autenticação API (Sanctum) e web (sessão): register consumer/performer, login, logout, me,
  verificação de e-mail (PT-BR), reset de senha (PT-BR).
- Middleware de role (`EnsureUserHasRole`), policies (User/Payment/PerformerProfile),
  audit log (`AuditLog` + `Support/Audit`).
- Ledger append-only de tokens (`TokenLedger`) + `TokenService`; saldo é soma do ledger.
  Testes provam que UPDATE/DELETE no ledger são bloqueados.
- Compra de tokens via Asaas/PIX (`FakeAsaasClient` em dev, `AsaasHttpClient` real),
  webhook idempotente por evento, reconciliação agendada (`ReconcilePayments`).
- Perfis de performer, catálogo público (auth-gated), follows idempotentes.
- KYC de performer: submit, status, resubmissão, webhook, documentos em storage privado,
  e-mails de aprovação/rejeição (`FakeKycClient` em dev).
- Gorjetas (`TipService`): split por nível (`split_pct`), ledger append-only, idempotência
  por chave, rate limit 10/min, rollback em falha de crédito.
- Dashboard de performer, payout via PIX transfer (`PayoutService`, webhook de transfer).
- Frontend Inertia/Vue: Landing, Entrada (role picker), Login, Register, ForgotPassword,
  ResetPassword, VerifyEmail, Catálogo (Index/Show), Wallet (Index/History), Dashboard,
  Onboarding, Payouts (Index/History). Age gate, intro animada, cards, filtros por mundo.

### [DESIGN / planejado] — não construído
- Chat/mensageria performer↔membro.
- Feed de posts.
- Conteúdo pago destravável (pay-to-unlock).
- Streaming ao vivo (LiveKit) — sessões privadas/câmera. Os `entry_type` do ledger já
  preveem `spend_private` e `spend_camera`, mas o fluxo de streaming não existe.

### [PENDENTE] — operacional/produção
- Validação manual tela a tela dos FIXes de UX (Fase 12).
- Operação de QA com massa de teste (50 performers + 100 membros).
- Scan de CSAM, IP allowlist nos webhooks, HSTS completo em produção (ver Issues).

---

## FASES CONCLUÍDAS

Extraído dos commits reais (`git log --oneline`).

| Fase | Objetivo | Entregas principais | Telas Vue | Testes |
|------|----------|---------------------|-----------|--------|
| 0 | Fundação repo + ambiente | Docker MySQL 8.4 + Redis, `.env`, README_FASE0 | — | — |
| 1 | Modelo de dados + segurança base | Migrations, models, `TokenService`, seeder, `token_ledger` append-only | — | `TokenServiceTest` |
| 2 | Auth + cadastro | Sanctum API (register/login/logout/me), email verify, password reset, role middleware, policies, audit log | — | `AuthApiTest`, `RegisterConsumerRequestTest` |
| 3 | Compra de tokens + Asaas/PIX | `PaymentService`, `AsaasClientInterface`+Fake/Http, webhook idempotente, reconciliação | — | `PaymentApiTest` |
| 4 | Perfis performer + catálogo + follows | `PerformerProfile`, `PerformerCatalogService`, `FollowService`, slugs | — | `PerformerPhase4Test` |
| 5 | KYC de performer | `KycService`, `KycClientInterface`+Fake/Http, webhook, resubmissão, docs criptografados, e-mails | — | `KycPhase5Test` |
| 6 | Gorjetas | `TipService`, split por nível, ledger, idempotência, rate limit 10/min | — | `TipPhase6Test` |
| 7 | Frontend Inertia + Vue 3 + Tailwind | Landing/Cadastro/Login/VerifyEmail/Catálogo, age gate, sessão, Ziggy | Landing, Auth/*, Catalog | `WebPhase7Test` |
| 8 | Catálogo visual de performers | Cards, filtros, perfil público | Catalog/Index, Catalog/Show | `CatalogPhase8Test` |
| 9 | Dashboard de performer | `DashboardController` | Performer/Dashboard | `PerformerDashboardTest` |
| 10 | Compra de tokens (UI) | `WalletController`, PixModal, bônus em pacotes | Consumer/Wallet/* | `WalletTest` |
| 11 | Payout de performer via PIX | `PayoutService`, transfer webhook, `payouts` table | Performer/Payouts/* | `PayoutTest` |
| 12 | UX fixes + produção | i18n PT-BR, entrada role picker, age gate overlay, intro, esqueci senha, catálogo por mundo, `SecurityHeaders`, pipeline CI/CD | Entrada, Auth/Forgot/Reset | `UxFixesFase12Test` |

---

## FASES PENDENTES

- **Chat** (performer↔membro): não implementado.
- **Feed de posts**: não implementado.
- **Conteúdo pago destravável**: não implementado.
- **Streaming LiveKit** (sessão privada / câmera): não implementado; ledger já prevê os tipos.
- **Analytics/relatórios** avançados: não implementado.
- **Scan CSAM / moderação de mídia**: não implementado (risco de compliance — ver Segurança).

---

## ARQUITETURA

```
                    ┌─────────────────────────────────────────┐
   Browser (Vue3) → │ nginx → php8.4-fpm → Laravel 13 (Inertia)│
                    └───────────────┬─────────────────────────┘
                                    │
        ┌───────────────┬───────────┴───────────┬──────────────┐
     MySQL 8.4        Redis                  Storage priv.   Filas (DB/Redis)
   (dados/ledger)  (cache/filas)          (KYC docs, mídia)  (jobs, e-mails)
        │
        │  webhooks idempotentes ↑↓
   Asaas (PIX / transfer)      Didit/Unico (KYC)      LiveKit (futuro)
```

- **Backend:** Laravel 13 / PHP 8.5. Controllers separados `Api/V1/*` (JSON, Sanctum) e
  `Web/*` (Inertia, sessão). Validação sempre via Form Requests. Services encapsulam a lógica
  de domínio (`TokenService`, `TipService`, `PaymentService`, `PayoutService`, `KycService`,
  `FollowService`, `PerformerCatalogService`, `AuthService`).
- **Frontend:** Inertia + Vue 3 + Tailwind. `GuestLayout`/`AppLayout`. Ziggy expõe rotas ao
  front por **allowlist** em `config/ziggy.php` (ver Segurança — quebra crítica se rota nova
  não for adicionada).
- **Integrações externas:** interfaces com implementação Fake (dev) e Http (prod) —
  `AsaasClientInterface`, `KycClientInterface`.

### Princípios não-negociáveis (do CLAUDE.md, confirmados no código)
1. Segurança e idade primeiro (KYC 18+ dos dois lados, PII isolada).
2. **Saldo derivado de ledger append-only.** Nunca `UPDATE saldo = saldo + x`. Todo movimento
   é linha nova em `token_ledger`. Testes bloqueiam update/delete.
3. **Idempotência em pagamento.** Crédito só via webhook idempotente por id de evento.
4. **PII isolada e criptografada** (CPF/documentos), em storage privado, nunca em log/URL.
5. Nada de segredo no Git (tudo em `.env`).
6. Dados reais só em produção; dev/staging usam dados sintéticos.
7. Dinheiro/tokens como inteiros (centavos/tokens), nunca float.

---

## ESTRUTURA DE PASTAS

(`tree` não está instalado; abaixo a estrutura real obtida via `ls -R`.)

```
app/
  Console/Commands/ReconcilePayments.php
  Exceptions/{InsufficientBalanceException, PayoutNotAllowedException}.php
  Http/
    Controllers/
      Api/V1/{Auth/*, Kyc*, Payment, Tip, TokenPackage, PerformerCatalog,
              PerformerProfile, PerformerMedia, Follow, AdminKyc}Controller.php
      Api/AsaasTransferWebhookController.php
      Web/{Landing, Catalog, Entrada, Follow, UserPreferences}Controller.php
      Web/Auth/{Login, Register, EmailVerification, ForgotPassword, ResetPassword}Controller.php
      Web/Consumer/WalletController.php
      Web/Performer/{Dashboard, Onboarding, Payout}Controller.php
    Middleware/{EnsureUserHasRole, HandleInertiaRequests, SecurityHeaders}.php
    Requests/{Auth/*, Web/*, ...}Request.php
    Resources/*Resource.php
  Jobs/{SendKycApprovedEmail, SendKycRejectedEmail}.php
  Mail/{KycApprovedMail, KycRejectedMail}.php
  Models/{User, PerformerProfile, IdentityVerification, TokenWallet, TokenLedger,
          TokenPackage, Payment, PaymentEvent, Payout, Tip, Follow, AuditLog}.php
  Notifications/{ResetPassword, VerifyEmail}Notification.php
  Policies/{User, Payment, PerformerProfile}Policy.php
  Rules/CpfValido.php
  Services/{Token, Tip, Payment, Payout, Kyc, Follow, PerformerCatalog, Auth}Service.php
           Asaas/{AsaasClientInterface, AsaasHttpClient, FakeAsaasClient}.php
           Kyc/{KycClientInterface, KycHttpClient, FakeKycClient}.php
  Support/Audit.php
resources/js/
  Layouts/{AppLayout, GuestLayout}.vue
  Components/{AgeGateModal, IntroAnimation, PerformerCard, FilterPanel, PixModal,
              FollowButton, StarRating, VerifiedBadge, LiveBadge, Button, Input, Modal, PortalLogo}.vue
  Pages/{Landing, Entrada}.vue
        Auth/{Login, Register, ForgotPassword, ResetPassword, VerifyEmail}.vue
        Catalog/{Index, Show}.vue
        Consumer/Wallet/{Index, History}.vue
        Performer/{Dashboard, Onboarding}.vue  Performer/Payouts/{Index, History}.vue
routes/{web.php, api.php, console.php}
database/migrations/*  database/seeders/DatabaseSeeder.php  database/factories/UserFactory.php
tests/Feature/*  tests/Unit/*
```

---

## DEPLOY (o que existe HOJE)

- **VPS:** Hetzner CX (Helsinki), IP `62.238.46.212`, Ubuntu 24.04.
- **Usuários SSH:** `deploy` (deploy automático) e `root`.
- **Path no servidor:** `/var/www/limen`.
- **Domínios:** `limen.dev.br` (staging, ativo) e `limen.com.br` (produção futura).
- **Web:** nginx + `php8.4-fpm`. **SSL:** Let's Encrypt (ECDSA) via Certbot.
- **CI/CD:** GitHub Actions (`.github/workflows/deploy.yml`), pipeline **test → deploy**,
  deploy automático em push na `main`.

### Workflow real (`.github/workflows/deploy.yml`)
**Job `test` (ubuntu-latest):**
- Service `mysql:8.4` (banco `limen_test`, user `limen`/`limen_dev_pw`).
- Setup PHP 8.5 (`mbstring, pdo, pdo_mysql, bcmath, intl, redis`), Node 20, cache Composer.
- `composer install` → `npm ci` → `npm run build` → copia `.env.example`, `key:generate`.
- `php artisan test` contra o MySQL de serviço.

**Job `deploy`** (`needs: test`, só em push na `main`) via `appleboy/ssh-action`, com
`script_stop: true`:
```bash
cd /var/www/limen
git config --global --add safe.directory /var/www/limen
git fetch origin main
git reset --hard origin/main          # força servidor == repo (ver Decisões)
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && route:cache && view:cache && event:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo supervisorctl restart limen-worker:*
```

**Sudoers:** `/etc/sudoers.d/deploy-limen` dá NOPASSWD ao usuário `deploy` **somente** para o
`chown storage/bootstrap` e o `supervisorctl restart limen-worker:*`.

⚠️ **Consequência do `reset --hard`:** qualquer edição manual no servidor é descartada a cada
deploy. O `config/ziggy.php` e o `SecurityHeaders.php` **não** devem ser editados no servidor —
mude no repo. Ver `CURRENT_ISSUES_AND_NEXT_ACTIONS.md` (HSTS foi reduzido manualmente no
servidor e será sobrescrito pelo repo no próximo deploy).

---

## GITHUB

- **Repositório:** `github.com/robsonlupo-dev/limen`, branch `main`.
- **Workflow:** `.github/workflows/deploy.yml`, pipeline `test` → `deploy`.
- **Trigger de deploy:** push em `main` (`if: github.ref == 'refs/heads/main' && push`).
- Concurrency group por ref, `cancel-in-progress: false`.

---

## VARIÁVEIS .ENV (apenas NOMES — nunca valores)

Do `.env.example`:
`APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, APP_LOCALE, APP_FALLBACK_LOCALE,
APP_FAKER_LOCALE, APP_MAINTENANCE_DRIVER, BCRYPT_ROUNDS, LOG_CHANNEL, LOG_STACK,
LOG_DEPRECATIONS_CHANNEL, LOG_LEVEL, DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE,
DB_USERNAME, DB_PASSWORD, SESSION_DRIVER, SESSION_LIFETIME, SESSION_ENCRYPT, SESSION_PATH,
SESSION_DOMAIN, BROADCAST_CONNECTION, FILESYSTEM_DISK, QUEUE_CONNECTION, CACHE_STORE,
CACHE_PREFIX, MEMCACHED_HOST, REDIS_CLIENT, REDIS_HOST, REDIS_PASSWORD, REDIS_PORT,
MAIL_MAILER, MAIL_SCHEME, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
MAIL_FROM_ADDRESS, MAIL_FROM_NAME, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY,
AWS_DEFAULT_REGION, AWS_BUCKET, AWS_USE_PATH_STYLE_ENDPOINT, VITE_APP_NAME,
KYC_PROVIDER, KYC_BASE_URL, KYC_API_KEY, KYC_WEBHOOK_SECRET`

> Nota: `.env.example` traz `DB_CONNECTION=sqlite`, mas o projeto real usa MySQL (dev via
> Docker, CI via service MySQL). Variáveis Asaas de produção (chave/URL/webhook secret) ficam
> só no `.env` do servidor, fora do versionamento.

## GITHUB SECRETS (apenas nomes)
- `HETZNER_HOST`
- `HETZNER_SSH_KEY`

---

## INTEGRAÇÕES

- **Asaas (PIX):** `AsaasClientInterface` com `FakeAsaasClient` (dev/testes) e `AsaasHttpClient`
  (prod). Webhook `POST api/v1/webhooks/asaas` (idempotente por evento; `PaymentEvent` registra
  cada id). Transfer/payout webhook `POST api/webhooks/asaas/transfer`. Reconciliação agendada
  via `ReconcilePayments`. **Status:** implementado e testado com o cliente Fake; chaves reais
  de sandbox/produção pendentes no servidor.
- **KYC (Didit/Unico):** `KycClientInterface` com `FakeKycClient` (dev, `KYC_PROVIDER=fake`) e
  `KycHttpClient`. Webhook `POST api/v1/webhooks/kyc` valida `KYC_WEBHOOK_SECRET`. Submit/status/
  resubmissão implementados; documentos em storage privado. **Status:** funcional com Fake;
  contratação/credenciais do provedor real pendentes.
- **LiveKit (streaming):** **não implementado.** Planejado para sessões privadas/câmera.

---

## DNS

- `limen.dev.br` — **ativo**, aponta para o Hetzner (`62.238.46.212`); staging no ar.
- `limen.com.br` — reservado para produção; status de transferência/apontamento pendente
  (confirmar com o PO antes do go-live).

---

## SEGURANÇA

- **Auth:** Sanctum (API, tokens com expiração/rotação) + sessão web (Inertia).
- **Autorização:** middleware `EnsureUserHasRole` (roles `consumer`/`performer`/`admin`),
  policies `User/Payment/PerformerProfile`. Registro nunca permite `admin` via mass assignment
  (teste cobre isso).
- **`SecurityHeaders` middleware:** `X-Content-Type-Options: nosniff`, `X-Frame-Options:
  SAMEORIGIN`, `X-XSS-Protection`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` (camera/mic/geo off), CSP `frame-ancestors 'self'`, e **HSTS**
  `max-age=31536000; includeSubDomains; preload` sob HTTPS. ⚠️ HSTS foi reduzido para
  `max-age=300` **manualmente no servidor** (staging) — o repo ainda tem 1 ano; o `reset --hard`
  vai restaurar 1 ano no próximo deploy. Decidir no go-live (ver Issues).
- **Validação:** todo input via Form Requests. Idade 18+ validada no cadastro (`CpfValido`,
  data de nascimento). Queries via Eloquent com bind.
- **Rate limit:** gorjetas 10/min.
- **Ledger atômico/append-only:** update/delete bloqueados (testado).
- **PII:** CPF só entra no checkout; documentos KYC em storage privado; nunca em log/URL.
- **Subagente `security-reviewer`** (`.claude/agents/security-reviewer.md`) roda após código
  sensível.

**Riscos conhecidos / pendências:** scan CSAM ausente; sem IP allowlist nos webhooks
(hoje só a assinatura/secret protege); CSP ainda mínimo (`frame-ancestors` só — `default-src`
completo exige afinar fontes/scripts do Inertia/Vite); HSTS a restaurar no go-live.

---

## TESTES

- **Total:** `173 testes, 785 asserts` — **todos verdes** (Pest).
- **Como rodar localmente:** não há SQLite local; rode contra o MySQL do Docker (`limen_test`):
  ```
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=limen_test \
    DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test
  ```
- **Cobertura por arquivo:** `AuthApiTest`, `RegisterConsumerRequestTest` (idade 18+, termos,
  senha forte), `PaymentApiTest`, `PerformerPhase4Test`, `CatalogPhase8Test`, `KycPhase5Test`,
  `TipPhase6Test` (split por nível, insufficient balance, self-tip, rate limit, rollback,
  idempotência), `TokenServiceTest` (bloqueia update/delete do ledger), `WalletTest` (tokens
  sempre do package, não do request; não expõe payment de outro user), `PayoutTest`,
  `PerformerDashboardTest`, `WebPhase7Test`, `UxFixesFase12Test` (entrada, mass assignment
  admin, e-mail PT-BR, reset, preferred world).
- **Cenários críticos cobertos:** saldo derivado do ledger, split correto por nível
  (iniciante/estrela/premium/vip), idempotência de gorjeta, isolamento de dados entre usuários,
  proibição de admin por mass assignment, KYC gate no catálogo.

---

## BUGS ENCONTRADOS E CORRIGIDOS

Do histórico de commits (e da sessão de troubleshooting de 02/07):

| Bug | Impacto | Status | Correção (commit) |
|-----|---------|--------|-------------------|
| Rota `entrada`/reset fora do allowlist do Ziggy → Vue morria na montagem → **tela preta** em tudo | Crítico (site inteiro sem render) | ✅ | `f4bf6ef` fix: add missing frontend routes to Ziggy allowlist |
| Deploy `git pull` falhava com mudanças locais no servidor | Deploy travado | ✅ | `afa2e5e` ci: force server to match repo via reset --hard |
| Deploy "dubious ownership" | Deploy travado | ✅ | `865d433` ci: mark deploy dir as git safe.directory |
| CI em PHP 8.3 + SQLite vs projeto 8.5 + MySQL | CI vermelho | ✅ | `0c68aff` ci: run tests on PHP 8.5 against MySQL service |
| Rota `performer.dashboard` duplicada | Erro de boot | ✅ | `c0e625a` fix: remove duplicate performer.dashboard route name |
| `TipService` sem hardening (self-tip, split inválido) | Segurança/economia | ✅ | `bc26599` fix: harden TipService and tip endpoints |
| KYC webhook/resubmissão sem guardas | Segurança | ✅ | `5e1bd87` fix: harden KYC webhook and resubmission guards |
| `user_id` fillable em `PerformerProfile` / upload de mídia sem authorize | Mass assignment/IDOR | ✅ | `b58b6e7`, `75546ed` |

**Bugs de infra resolvidos na sessão (fora do git, no servidor):** permissões `.git/objects`
(git como root) → `chown deploy:www-data` + `core.sharedRepository group` + `usermod -aG
www-data deploy`; `sudo: password required` no deploy → `/etc/sudoers.d/deploy-limen` NOPASSWD.

---

## DÍVIDAS TÉCNICAS E RISCOS DE PRODUÇÃO

- HSTS divergente entre repo (1 ano) e servidor (300s); risco de preload quase irreversível.
- CSP mínimo — falta `default-src`/`script-src` afinados.
- Sem IP allowlist nos webhooks Asaas/KYC.
- Sem scan CSAM / moderação de mídia (compliance crítico para adulto).
- `.env.example` com `DB_CONNECTION=sqlite` induz erro (projeto usa MySQL).
- Chaves reais Asaas/KYC ainda não configuradas (só Fake).
- Massa de teste ainda não gerada; telas validadas só parcialmente.
- Features de DESIGN (chat, feed, conteúdo pago, streaming) não iniciadas.

---

## CHECKLIST GO-LIVE (limen.com.br)

- [ ] DNS `limen.com.br` apontado + SSL emitido.
- [ ] Restaurar HSTS completo (decidir preload) no `SecurityHeaders`.
- [ ] Afinar CSP (`default-src`/`script-src`).
- [ ] IP allowlist + verificação de assinatura reforçada nos webhooks.
- [ ] Credenciais reais Asaas (produção) e KYC (provedor real).
- [ ] Scan CSAM / moderação de mídia.
- [ ] Backups automatizados verificados (`docs/backup.sh`).
- [ ] Operação de QA concluída (ver `QA_HANDOFF_MASTER.md`).
- [ ] Validação manual tela a tela dos FIXes.
- [ ] Revisão do `security-reviewer` em todo fluxo sensível.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, dados sintéticos removidos.

---

## COMANDOS OPERACIONAIS

```bash
# Acesso SSH
ssh deploy@62.238.46.212        # deploy   | ssh root@62.238.46.212 (admin)
cd /var/www/limen

# Deploy manual de emergência (mesma sequência do workflow)
git fetch origin main && git reset --hard origin/main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo supervisorctl restart limen-worker:*

# Limpar caches
php artisan optimize:clear

# Logs
tail -f storage/logs/laravel.log
sudo journalctl -u php8.4-fpm -f ; sudo tail -f /var/log/nginx/error.log

# Serviços
sudo supervisorctl status ; sudo supervisorctl restart limen-worker:*
sudo systemctl reload nginx ; sudo systemctl restart php8.4-fpm

# Testes (local, contra Docker MySQL)
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=limen_test \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test

# Acesso de dentro da rede Verallia (Zscaler bloqueia): túnel SSH da VM
~/tunel-limen.sh   # sobe túnel + /etc/hosts → https://limen.dev.br:8443/entrada
```

---

## HISTÓRICO DE DECISÕES ARQUITETURAIS

| # | Decisão | Motivo | Alternativa descartada |
|---|---------|--------|------------------------|
| D1 | Saldo derivado de `token_ledger` append-only | Auditabilidade; erro recorrente no projeto anterior | `UPDATE saldo = saldo + x` |
| D2 | Crédito de tokens só via webhook idempotente | Reprocessar nunca duplica saldo | Creditar na criação da cobrança |
| D3 | PII/CPF isolada, storage privado, nunca em log/URL | Compliance/LGPD | CPF em coluna comum |
| D4 | Dinheiro/tokens como inteiros | Evitar erro de float | float/decimal |
| D5 | Interfaces Asaas/KYC com Fake + Http | Testar sem provedor externo | Chamar API real em dev |
| D6 | Ziggy expõe rotas por **allowlist** (`config/ziggy.php`) | Reduzir superfície; não vazar rotas internas | Expor todas as rotas |
| D7 | `category` como "mundo" (mulheres/homens/casais/trans/gls/swing) | Reuso do campo existente | Criar coluna `world` |
| D8 | Rota `/cadastro` reutilizada (não `/registro`) | Consistência de URLs PT-BR | Nova rota |
| D9 | Catálogo **auth-gated** | Idade/segurança; conteúdo adulto | Catálogo público |
| D10 | Deploy via `git fetch + reset --hard` | Servidor sempre == repo; evita conflito com edições locais | `git pull` |
| D11 | Sudoers NOPASSWD restrito a 2 comandos do deploy | Deploy automático sem senha, superfície mínima | NOPASSWD amplo |
| D12 | Locale padrão `pt_BR` | Mercado brasileiro | `en` |
| D13 | Controllers separados `Api/V1` (JSON) e `Web` (Inertia) | Contratos distintos API vs sessão | Controller único |
| D14 | Split por nível via `split_pct` no `PerformerProfile` | Flexível por performer | Split fixo global |
| D15 | Tailwind/Blade→Inertia+Vue só com aprovação do PO | Governança de stack | Trocar stack livremente |
