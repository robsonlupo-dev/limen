<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# LIMEN — SECURITY REPORT (Operação de QA · 02/07/2026)

> ⚠️ **Retrato de 02/07/2026, arquivado em 15/07.** Vários achados já foram corrigidos —
> entre eles o **#1** (KYC cifrado em repouso) e o **#2** (`DatabaseSeeder` com senha
> committada e sem guard de produção). Confira contra a `main` antes de tratar qualquer
> item daqui como aberto. Ver `docs/qa/GO_LIVE_READINESS.md` para o balanço.

> Fontes: (1) casos obrigatórios da Fase 5 executados via suíte Pest (181 verdes) e
> checagens dirigidas; (2) revisão completa do subagente `security-reviewer` do projeto
> (50 verificações, incluindo auditoria empírica no banco de dev). Sem achado inventado.

## Casos obrigatórios — PASS/FAIL

| Caso | Resultado | Evidência |
|---|---|---|
| IDOR (wallet/payment/pending/pix_key de outro user) | ✅ PASS | `WalletTest`, `PaymentApiTest`, `PayoutTest` (pix de outro performer inacessível) |
| Autorização por role (consumer em rota performer/admin) | ✅ PASS | `WalletTest`, `PayoutTest`, `PerformerDashboardTest`; Gate `performer-active` |
| Mass assignment (`role=admin`, `status`, `user_id`) | ✅ PASS | `UxFixesFase12Test` (admin nunca), `role/status/preferred_world` fora do fillable |
| Saldo negativo | ✅ PASS | `TokenServiceTest` + `TipPhase6Test` + `PayoutTest` (sequencial) + auditoria SQL (0 negativos em 1243 linhas) |
| Bypass de pagamento (crédito sem webhook) | ✅ PASS | `WalletTest` ("não credita imediatamente"; tokens sempre do package) |
| Bypass de gorjeta (sem saldo/negativo/idempotency repetido) | ✅ PASS | `TipPhase6Test` (422 + ledger intacto; dedup por chave) |
| Bypass de conteúdo pago | N/A | módulo não implementado |
| Webhook forjado (token inválido) | ✅ PASS | `PaymentApiTest` + `PayoutTest`; `hash_equals` timing-safe nos 3 webhooks |
| Replay de webhook (mesmo event id) | ✅ PASS | `PaymentApiTest` ("não duplica crédito") + `PayoutTest` (idempotente) |
| Link de verificação não assinado/expirado | ✅ PASS | middleware `signed` (web e API), `temporarySignedRoute` |
| Session fixation / CSRF | ✅ PASS | `regenerate()` pós-login/registro, `invalidate()+regenerateToken()` no logout, CSRF sem exclusões |
| Login suspenso/banido | ✅ PASS | `QaOperationTest` (novo — web e API) |
| PII em log/URL | ✅ PASS | auditadas as 7 chamadas `Log::`; CPF só em body POST; `dontFlash` cpf; `pix_key` encrypted + mascarada |

## Achados do security-reviewer

### ALTO
1. **Documentos KYC sem criptografia em repouso** — `app/Http/Controllers/Api/V1/KycController.php:33-52`.
   Os campos de texto têm cast `encrypted`, mas as **imagens** (frente/verso/selfie) vão cruas
   para `storage/app/kyc/{user_id}`. Viola o princípio 4 do CLAUDE.md.
   *Correção:* `Crypt::encrypt` no conteúdo (ou disco com encryption at rest) + decrypt no serving admin.
2. **`DatabaseSeeder` cria admin ativo com senha fraca committada, sem guarda de produção** —
   `database/seeders/DatabaseSeeder.php:43-53` (`admin@limen.test`/`Password1`). Um
   `db:seed` manual em produção instala backdoor admin. (O deploy não roda seed — verificado —
   e o `LimenTestSeeder` tem guarda; o default não.)
   *Correção:* guarda de ambiente + senha via `env()`.

### MÉDIO
3. **Race de idempotência no webhook de pagamento** — `PaymentService.php:71-83`: check-then-insert
   sem capturar `QueryException` do unique (concorrência → 500; sem duplo crédito, que é
   re-verificado sob lock). O `PayoutService` já trata; replicar o padrão.
4. **`TRANSFER_PAID` antecipado se perde** — `PayoutService.php:186-190`: `markPaid` é no-op se o
   payout ainda está `pending` e o evento é marcado processado; não há reconcile de transfers.
   *Correção:* aceitar `pending` no `markPaid` ou adicionar reconciliação.
5. **`POST /cadastro` sem rate limit** — `routes/web.php:25` (login/forgot/reset têm `throttle:5,1`;
   a rota API de registro também). Permite criação de contas em massa. *Correção:* `throttle:5,1`.

### BAIXO
6. `PerformerProfile::$fillable` inclui `is_verified`/`level`/`split_pct` (risco latente em campo
   que controla dinheiro; hoje nenhum controller passa input cru — verificado).
7. Ledger de gorjeta com `reference_id` null (rastreabilidade só no caminho inverso via tips).
8. Enumeração de usuário por timing no login (sem `Hash::check` dummy; mitigado por throttle).
9. Rota API de verificação de e-mail sem throttle (a web tem).
10. Staging público + massa de QA: as 153 contas nasciam com uma senha padrão publicada no
    repo, logáveis se o `LimenTestSeeder` rodasse lá. Proteger staging (auth básica/allowlist)
    e conferir `SESSION_SECURE_COOKIE=true` em produção.
    *(Resolvido depois deste relatório: a senha saiu do repo e passou a vir de
    `SEED_ADMIN_PASSWORD`, obrigatória fora de local/testing — `RefusesUnsafeEnvironment`.)*

## Verificado e aprovado (resumo)
Webhooks timing-safe com rejeição de token vazio; KYC bloqueia replay/downgrade; Sanctum com
expiração 24h; resources públicos sem `user_id`/nome legal; **zero** via de UPDATE de balance
fora do `TokenService` (grep completo); locks ordenados anti-deadlock nas gorjetas; 18+
validado nos 4 fluxos de registro; seeder de QA sem PII real e com guarda de produção;
binding FakeAsaas do seeder não vaza para runtime.

## Veredito de segurança
**Sem blocker de dinheiro.** Dois achados ALTO são de **compliance/hardening** (KYC em repouso,
seeder admin) e precisam entrar como must-fix antes do go-live; nenhum permite mover ou
duplicar tokens. Os testes de segurança de dinheiro passam integralmente.
