# Runbook — Webhooks do Asaas (pagamento e transferência)

> **O que isto cobre:** o canal por onde o Asaas avisa o Limen que um PIX/cobrança
> mudou de estado (pago, confirmado, estornado…) e dispara o **crédito de tokens**
> (idempotente por evento — princípio nº 3). Duas rotas, mesmo middleware:
> - `POST /api/v1/webhooks/asaas` → `AsaasWebhookController` (cobranças)
> - `POST /api/v1/webhooks/asaas/transfer` → `AsaasTransferWebhookController` (transferências/payout)
>
> Ambas passam por `asaas.webhook_ip` (`VerifyAsaasWebhookIp`) e, no controller,
> pela checagem do header **`asaas-access-token`** (`hash_equals` contra
> `ASAAS_WEBHOOK_TOKEN`). O token é a autenticação **real**; a allowlist de IP é
> defesa em profundidade.

## Duas camadas de proteção (e o que cada uma responde)

1. **Middleware `asaas.webhook_ip`** — se ligado e o IP de origem não estiver na
   lista, responde **`403 Forbidden`** (e loga `asaas.webhook.ip_blocked`, mas só
   se `LOG_LEVEL` deixar passar `warning`). **Off por padrão**
   (`config/asaas.php` → `env('ASAAS_WEBHOOK_IP_ALLOWLIST', false)`).
2. **Token no controller** — se o header `asaas-access-token` não bater com
   `ASAAS_WEBHOOK_TOKEN`, responde **`401 Unauthorized`**.

Regra de ouro do diagnóstico: **403 = barrado ANTES do controller** (allowlist de
IP ou algo na borda). **401 = chegou no app, token não bate.** **200 = ok.**

## Incidente 25/09/2026 — sandbox tomando 403 (RESOLVIDO)

**Sintoma:** e-mails do Asaas "A sincronização de eventos de Webhooks foi
interrompida" / "Erro ao sincronizar" para a fila `limen-dev`
(`https://limen.dev.br/api/v1/webhooks/asaas`), **status 403**. A fila pausa
sozinha após 15 tentativas; eventos ficam 14 dias; a config é desativada após 30
dias de falha contínua.

**Causa raiz:** `ASAAS_WEBHOOK_IP_ALLOWLIST=true` no `.env` **do sandbox**. O
sandbox do Asaas envia de **IPs adicionais** que **não** estão na lista de
produção, então a allowlist barrava tudo com 403. (A allowlist é um switch
**production-only** — o próprio docblock do middleware diz isso.)

**Descartados no caminho (registrar para não repetir):**
- **Não era Cloudflare.** `curl` para `limen.dev.br` responde `Server: nginx`
  direto, sem `cf-ray`/`cf-mitigated` — **o domínio não está atrás do Cloudflare**.
  (Por isso **não precisa de TrustProxies**: sem CDN na frente, `$request->ip()`
  já é o IP real do Asaas.)
- **Não era bloqueio de User-Agent no nginx.** `grep` no `/etc/nginx` só achou
  `deny all` de dotfiles; nenhum filtro de `Java/...` nem `return 403` na rota.
- **Não era o token** (isso seria 401, não 403).

**Fix aplicado (no `/var/www/limen`, que serve os dois domínios):**
```bash
cd /var/www/limen
sed -i 's/^ASAAS_WEBHOOK_IP_ALLOWLIST=true/ASAAS_WEBHOOK_IP_ALLOWLIST=false/' .env
php artisan config:cache          # com config:cache, mudar .env sem isso não tem efeito
sudo systemctl reload php8.4-fpm
# teste: agora dá 401 (chega no controller, sem token), não mais 403
curl -sS -o /dev/null -w "%{http_code}\n" -X POST -H "User-Agent: Mozilla/5.0" \
  https://limen.dev.br/api/v1/webhooks/asaas
```
Depois, no painel do Asaas: **Menu do usuário → Integrações → Webhooks →** editar a
fila `limen-dev` → ligar **"Este Webhook ficará ativo?"** e **"Fila de
sincronização ativada?"** → Salvar. Os eventos penalizados são reenviados; conferir
em **Logs de Webhooks** que voltam a **200**. (Feito: 20 eventos → todos 200; as
duas filas voltaram a **Ativado / 0 penalizados**.)

## Diagnóstico rápido (se voltar a falhar)

```bash
# 1) É borda (nginx/CDN) ou app? (sem token → 401 esperado; 403 = allowlist/borda)
curl -sS -o /dev/null -w "%{http_code}\n" -X POST -H "User-Agent: Mozilla/5.0" \
  https://limen.dev.br/api/v1/webhooks/asaas

# 2) Flag da allowlist e nível de log
grep -E "ASAAS_WEBHOOK|ASAAS_ENV|LOG_LEVEL" /var/www/limen/.env

# 3) O app registrou bloqueio de IP? (só aparece se LOG_LEVEL<=warning)
grep asaas.webhook.ip_blocked /var/www/limen/storage/logs/laravel*.log | tail
```
- **401** no passo 1 → app ok; se o Asaas ainda reclama, é **token** (alinhar o
  campo "Token de autenticação" do webhook no painel com `ASAAS_WEBHOOK_TOKEN`).
- **403** no passo 1 → allowlist ligada barrando o IP de origem (ver passo 2/3).

## Config (config/asaas.php)

- `ASAAS_WEBHOOK_IP_ALLOWLIST` (bool, default **false**) — liga/desliga a camada de IP.
- `ASAAS_WEBHOOK_ALLOWED_IPS` (CSV, opcional) — sobrescreve a lista. Sem override,
  usa o default do config, que são os **4 IPs OFICIAIS DE PRODUÇÃO**:
  `52.67.12.206, 18.230.8.159, 54.94.136.112, 54.94.183.101`.
  Fonte canônica (conferir no go-live, muda): https://docs.asaas.com/docs/official-asaas-ips
- `ASAAS_WEBHOOK_TOKEN` — segredo do header `asaas-access-token`. Fora do Git.

> **Sandbox usa IPs ADICIONAIS** não publicados — por isso no sandbox a allowlist
> fica **`false`** e a proteção é só o token. Em produção os 4 IPs oficiais bastam.

## ✅ Checklist de GO-LIVE (Asaas em produção)

Ao virar o Asaas de sandbox para produção (novo `ASAAS_API_KEY`, `ASAAS_ENV=production`,
`ASAAS_BASE_URL` de produção, e o webhook cadastrado na conta de PRODUÇÃO do Asaas):

1. **Ligar a allowlist de IP:** no `.env` de produção, `ASAAS_WEBHOOK_IP_ALLOWLIST=true`.
   - **Não precisa** mexer em `ASAAS_WEBHOOK_ALLOWED_IPS`: os 4 IPs oficiais de
     produção **já são o default do `config/asaas.php`**. (Os 2 IPs extras que
     apareceram no e-mail do sandbox — `54.94.135.45`, `52.67.211.226` — são
     **só do sandbox**; NÃO colocar em produção.)
   - Antes de virar, reconferir a lista oficial no link acima (pode mudar).
2. `php artisan config:cache` + `sudo systemctl reload php8.4-fpm`.
3. Confirmar que o **`asaas-access-token`** do webhook de produção (no painel do
   Asaas) é igual ao `ASAAS_WEBHOOK_TOKEN` do `.env`.
4. Teste de fumaça: uma cobrança de teste em produção deve gerar evento **200** em
   **Logs de Webhooks**, e o crédito de tokens correspondente deve cair (idempotente).

> **Atenção — instância única:** o `/var/www/limen` serve **os dois domínios** com o
> **mesmo `.env`** (ver a nota de topologia no CLAUDE.md). Não há sandbox e produção
> rodando lado a lado no mesmo box: no go-live a integração inteira do Asaas troca de
> sandbox para produção, e é aí que a flag vira `true`. Enquanto for sandbox, fica `false`.
