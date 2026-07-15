# LIMEN — GO-LIVE READINESS (Operação de QA · 02/07/2026)

> ⚠️ **Retrato de 02/07/2026, arquivado em 15/07.** A lista de must-fix abaixo **não é o
> estado atual**: dos itens 1–5, só o **3 (ligar a UI de gorjeta)** seguia aberto em
> 15/07/2026. Resolvidos desde então: **1** (KYC cifrado em repouso — `KycDocumentStore` +
> disco `kyc`), **2** (guard de produção + `SEED_ADMIN_PASSWORD` — `RefusesUnsafeEnvironment`),
> **4** (`throttle:5,1` no cadastro) e **5** (`TRANSFER_DONE` + `payouts:reconcile`).
> Vale como histórico da operação, não como checklist vivo.

> Consolidação final do orchestrator. Pesos: Funcional 30% · Segurança 30% ·
> Economia de tokens/ledger 20% · UX 10% · Carga 10%. Cada nota vem dos relatórios
> irmãos em `docs/qa/` (dados reais de execução).

## Nota final: **85 / 100** — Veredito: **GO com ressalvas**

| Dimensão | Peso | Nota | Pontos | Fonte |
|---|---|---|---|---|
| Funcional | 30% | 9,5/10 | 28,5 | 181/181 testes verdes; 5 módulos N/A por não existirem (TEST_RESULTS.md) |
| Segurança | 30% | 8,0/10 | 24,0 | Todos os casos de dinheiro PASS; 2 ALTO de compliance abertos (SECURITY_REPORT.md) |
| Ledger / economia | 20% | 10/10 | 20,0 | 4 invariantes × 0 falhas em 1243 lançamentos; íntegro até sob carga (TEST_RESULTS.md, LOAD_REPORT.md) |
| UX | 10% | 8,1/10 | 8,1 | Média das 15 telas (UX_REPORT.md) |
| Carga | 10% | 4,0/10 | 4,0 | Estável e sem 5xx, mas thresholds estourados em ambiente não representativo (LOAD_REPORT.md) |

## Blockers (impedem go-live)
Nenhum blocker de dinheiro/segurança encontrado: saldo negativo impossível, webhooks com
dedup comprovado, ledger perfeito, IDOR/mass assignment bloqueados.

**Blocker de produto (não técnico):** a gorjeta — único mecanismo de gasto — está desligada
na UI (`Catalog/Show.vue`, modal "Em breve"). Lançar assim significa lançar sem monetização.
Tratar como bloqueador de lançamento na prática.

## Must-fix antes de produção (limen.com.br)
1. **Criptografar imagens de KYC em repouso** (ALTO — compliance; SECURITY_REPORT #1).
2. **Guarda de produção + senha via env no `DatabaseSeeder`** (ALTO — backdoor admin; #2).
3. **Ligar a UI de gorjeta** ao `POST /api/v1/tips` (produto; UX_REPORT P0).
4. `throttle:5,1` no `POST /cadastro` (MÉDIO; #5).
5. Tratar race do webhook de pagamento como no payout (MÉDIO; #3) e aceitar `TRANSFER_PAID`
   antecipado / reconcile de transfers (MÉDIO; #4).
6. Carga representativa no VPS (nginx+fpm+opcache): meta p95 < 300ms @ 100 VUs.
7. Itens já rastreados no repo: HSTS por ambiente (**resolvido em 07208a9**), credenciais
   reais Asaas/KYC, scan CSAM, IP allowlist de webhooks (CURRENT_ISSUES / handoff).
8. Se staging ficar público com a massa de QA: proteger com auth básica/allowlist e
   `SESSION_SECURE_COOKIE=true` em produção. *(A senha da massa era pública no repo quando
   isto foi escrito; desde então vem de `SEED_ADMIN_PASSWORD` e não é publicada.)*

## Recomendados (não bloqueiam)
- `PerformerProfile`: tirar `is_verified`/`level`/`split_pct` do fillable (defesa em profundidade).
- Backfill de `reference_id` no ledger de gorjetas; hash dummy no login; throttle na rota API
  de verificação; feedback pós-reenvio de verificação; CTA morto "Ir ao vivo" substituído.

## O que esta operação entregou
- Ambiente de dev **povoado e permanente**: 50 performers verificados + 100 membros com
  saldos, 755 follows, 444 gorjetas, 266 pagamentos — tudo via services/ledger reais.
- 8 testes novos de gap (suíte: 173 → 181) + script k6 versionado.
- 16 agentes de QA reutilizáveis em `.claude/agents/`.
- 7 relatórios em `docs/qa/`: TEST_ACCOUNTS, TEST_RESULTS, UX_REPORT, SECURITY_REPORT,
  LOAD_REPORT, GO_LIVE_READINESS, GROWTH_STRATEGY.
