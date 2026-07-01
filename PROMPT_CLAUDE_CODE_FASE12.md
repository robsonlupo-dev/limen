# PROMPT CLAUDE CODE — FASE 12: Deploy em Produção

Você é o Arquiteto-Chefe do Limen. Robson é o fundador — ele orienta a visão,
você executa com autonomia total. Leia CLAUDE.md e TODAS as skills antes de começar.

Skills desta fase:
- `.claude/skills/i18n-pt-br/skill.md` ← CRÍTICA
- `.claude/skills/production-hardening/skill.md`
- `.claude/skills/security-checklist/skill.md`

Agentes disponíveis (leia cada um antes de ativá-lo):
- `.claude/agents/orchestrator/agent.md`
- `.claude/agents/localization-checker/agent.md`
- `.claude/agents/security-appsec/agent.md`
- `.claude/agents/backend-qa/agent.md`
- `.claude/agents/frontend-qa/agent.md`
- `.claude/agents/devops-sre/agent.md`
- `.claude/agents/devsecops/agent.md`

Estado atual do deploy: `.claude/memory/deploy-state.md`

---

## FASE 12 — Deploy: limen.com.br no ar

### Decisões fixadas (não discutir)
- Hosting: **Hetzner Cloud CX22** (Nuremberg)
- KYC produção: **Unico**
- SSL: Let's Encrypt
- CI/CD: GitHub Actions

---

## Pipeline de execução (ORDEM OBRIGATÓRIA)

---

### 🔤 ETAPA 1 — Auditoria i18n (localization-checker)

**MISSÃO:** Zero strings em inglês visíveis ao usuário. Corrigir tudo antes de qualquer outra etapa.

Execute as seguintes ações:

1. Verificar/criar estrutura `lang/pt_BR/`:
   ```bash
   ls lang/ || ls resources/lang/
   ```

2. Criar/atualizar os 4 arquivos de idioma completos conforme skill `i18n-pt-br`:
   - `lang/pt_BR/auth.php`
   - `lang/pt_BR/pagination.php`
   - `lang/pt_BR/passwords.php`
   - `lang/pt_BR/validation.php` (COMPLETO com todos os atributos)

3. Atualizar `config/app.php`:
   ```php
   'locale' => 'pt_BR',
   'fallback_locale' => 'pt_BR',
   ```

4. Varrer TODOS os arquivos Vue em `resources/js/Pages/` e `resources/js/Components/`:
   ```bash
   grep -rn --include="*.vue" \
     -e "Loading\.\.\." -e "Submit" -e "Cancel" -e "Error" \
     -e "Success" -e "Required" -e "Invalid" -e "Please" \
     -e "Not found" -e "No results" -e "Previous" -e "Next" \
     resources/js/
   ```
   Corrigir TUDO que aparecer.

5. Criar páginas de erro customizadas conforme skill `i18n-pt-br`:
   - `resources/views/errors/404.blade.php`
   - `resources/views/errors/403.blade.php`
   - `resources/views/errors/500.blade.php`
   - `resources/views/errors/419.blade.php`

6. Verificar bug reportado (senha errada 2x aparece inglês):
   - Confirmar que `validation.php` tem `'confirmed'` e `'password'` em PT-BR
   - Testar com `php artisan tinker` se necessário

7. Atualizar `.claude/handoff/i18n-report.md` com resultado.

**CRITÉRIO PARA AVANÇAR:** Zero strings em inglês detectadas.

---

### 🔐 ETAPA 2 — Auditoria de Segurança Final (security-appsec)

Execute todos os checks do agente `security-appsec`:

1. Verificar que nenhum secret está no código
2. Criar middleware `SecurityHeaders` se não existir
3. Verificar/corrigir `config/cors.php`
4. Verificar rate limiting em todas as rotas sensíveis
5. Verificar mass assignment em todos os Models
6. Verificar que arquivos KYC estão em storage privado
7. Confirmar que `pix_key` está com cast `encrypted` no Payout model
8. Verificar que logs não expõem PII

Atualizar `.claude/handoff/security-report.md`.

**CRITÉRIO PARA AVANÇAR:** Zero findings críticos.

---

### ⚙️ ETAPA 3 — Backend QA Completo (backend-qa)

1. Rodar suite completa:
   ```bash
   php artisan test
   ```
   **161/161 mínimo — qualquer falha é BLOCKER.**

2. Verificar integridade do banco:
   ```sql
   SELECT * FROM token_ledger WHERE balance_after < 0;
   ```

3. Verificar todas as rotas:
   ```bash
   php artisan route:list
   ```

4. Testar endpoints críticos via HTTP (usar `php artisan serve` se necessário).

Atualizar `.claude/handoff/backend-qa-report.md`.

---

### 🎨 ETAPA 4 — Frontend QA Completo (frontend-qa)

1. Listar todas as Pages Vue:
   ```bash
   find resources/js/Pages -name "*.vue" | sort
   ```

2. Para cada página, verificar conforme agente `frontend-qa`:
   - Textos PT-BR
   - Estados loading/erro/vazio em PT-BR
   - Design system Limen aplicado
   - Navegação correta

3. Verificar componentes compartilhados (Navbar, Footer, modals, toasts).

4. Verificar meta tags `<Head>` em PT-BR.

5. Corrigir TUDO no código — não só reportar.

Atualizar `.claude/handoff/frontend-qa-report.md`.

---

### 🚀 ETAPA 5 — Preparação para Deploy (devops-sre)

**ATENÇÃO:** Esta etapa requer ações manuais de Robson no painel Hetzner.
Gerar as instruções detalhadas e aguardar confirmação de Robson.

1. Criar middleware `SecurityHeaders` (se não criado na Etapa 2):
   ```bash
   # Criar app/Http/Middleware/SecurityHeaders.php
   # Registrar em bootstrap/app.php
   ```

2. Criar arquivo `.github/workflows/deploy.yml` (CI/CD completo).

3. Criar script de backup `/home/deploy/backup.sh` (template).

4. Criar `deploy.sh` na raiz do projeto para uso manual:
   ```bash
   #!/bin/bash
   # Script de deploy manual (usar se CI/CD falhar)
   cd /var/www/limen
   git pull origin main
   composer install --no-dev --optimize-autoloader
   npm install && npm run build
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   sudo chown -R www-data:www-data storage bootstrap/cache
   sudo supervisorctl restart limen-worker:*
   echo "✅ Deploy manual concluído: $(date)"
   ```

5. Criar `.env.production.example` (template com placeholders, sem valores reais).

6. Atualizar `README.md` com instruções de deploy.

7. Gerar lista de todos os secrets que Robson precisa configurar no GitHub:
   ```
   GitHub Secrets necessários:
   - HETZNER_HOST: IP do servidor
   - HETZNER_SSH_KEY: chave SSH privada do usuário deploy
   ```

8. Commit de tudo: `feat: add deploy pipeline, security headers, i18n pt-BR (fase 12)`

Atualizar `.claude/handoff/deploy-report.md`.

---

### 🔄 ETAPA 6 — CI/CD e Monitoramento (devsecops)

Verificar que o arquivo `.github/workflows/deploy.yml` está correto e válido.
Documentar passo-a-passo que Robson deve seguir para:
1. Criar servidor no Hetzner
2. Configurar DNS
3. Configurar GitHub Secrets
4. Fazer o primeiro deploy manual
5. Verificar que CI/CD automático está funcionando

Atualizar `.claude/handoff/cicd-report.md`.

---

### ✅ ETAPA 7 — Checklist Final Pré Go-Live

Gerar relatório final consolidado em `.claude/handoff/go-live-checklist.md`:

```markdown
# Go-Live Checklist — limen.com.br

## Código (feito pelos agentes)
- [ ] i18n PT-BR completo
- [ ] Zero strings em inglês
- [ ] Páginas de erro customizadas (404/403/500/419)
- [ ] Security headers middleware
- [ ] 161+ testes verdes
- [ ] Security audit sem críticos
- [ ] GitHub Actions deploy.yml criado

## Infraestrutura (Robson faz)
- [ ] Criar servidor Hetzner CX22 (Nuremberg)
- [ ] Anotar IP público
- [ ] Configurar DNS: A limen.com.br → IP
- [ ] Configurar DNS: A www.limen.com.br → IP
- [ ] SSH no servidor e rodar setup (ver devops-sre/agent.md)
- [ ] Clonar repo e configurar .env de produção
- [ ] Rodar php artisan migrate --force
- [ ] Instalar Certbot e gerar SSL
- [ ] Configurar GitHub Secrets (HETZNER_HOST, HETZNER_SSH_KEY)
- [ ] Primeiro push → verificar CI/CD

## Contas externas (Robson faz)
- [ ] Conta Asaas em produção (não sandbox)
- [ ] Conta Unico ativada para produção
- [ ] Webhook Asaas apontando para https://limen.com.br/webhooks/asaas
- [ ] Webhook KYC Unico apontando para https://limen.com.br/webhooks/kyc
- [ ] Zoho Mail configurado (contato@limen.com.br)
- [ ] Uptime Robot configurado (https://limen.com.br)

## Verificação final (após subir)
- [ ] https://limen.com.br carrega (200)
- [ ] SSL válido (cadeado verde)
- [ ] Registro de usuário funciona
- [ ] Login funciona
- [ ] Erro de senha exibe mensagem em PT-BR
- [ ] Catálogo carrega
- [ ] Compra de tokens (PIX sandbox) funciona
- [ ] Dashboard do performer carrega
```

---

## Regras críticas desta fase
- i18n ANTES de tudo — é blocker
- Nenhum commit com APP_DEBUG=true
- .env NUNCA no Git (verificar .gitignore)
- security-reviewer obrigatório antes do commit final
- Commit desta fase: `feat: production-ready — i18n, security, deploy pipeline (fase 12)`

## Critério de aceite
✅ 161+ testes verdes
✅ Zero strings em inglês no código
✅ .github/workflows/deploy.yml criado e válido
✅ Security headers implementados
✅ Páginas de erro 404/403/500/419 em PT-BR no design Limen
✅ Go-live checklist gerado para Robson
✅ Security reviewer sem achados críticos
✅ Commit + push para main

Ao finalizar, staged de tudo e aguarde aprovação para commit + push.
