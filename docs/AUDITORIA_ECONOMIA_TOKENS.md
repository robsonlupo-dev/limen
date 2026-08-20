# Auditoria da economia de tokens — extraída do código

> **Escopo e método.** Este relatório mapeia TODOS os fluxos em que token troca de
> mão, lidos do **código COMMITADO** (`HEAD = e407d08`, `main`, inclui PR #196). Cada
> fato aponta `arquivo:linha` ou o método. Onde código e doc divergem, os dois são
> citados.
>
> ⚠️ **Ressalva importante.** O working tree desta sessão tem alterações NÃO-COMMITADAS
> (branch `feat/fractional-token-ledger`: DECIMAL(20,2) + bcmath + chat 80/20 — trabalho
> interrompido). **Essas alterações foram EXCLUÍDAS desta auditoria** — tudo abaixo é o
> estado real/deployável de `main`, lido via `git show HEAD:`. Se você quiser a auditoria
> do working tree em vez do baseline, me avise.
>
> Data: 2026-08-19. Nenhum arquivo de código foi alterado neste passo.

---

## 0. Como o dinheiro se move (as duas classes-âncora)

- **`App\Services\TokenService`** (`app/Services/TokenService.php`) — escritor bruto do
  ledger append-only. `credit(int $amount, …)` / `debit(int $amount, …)`, tudo **inteiro**,
  saldo materializado `token_wallets.balance` reescrito por valor absoluto sob
  `lockForUpdate` na mesma transação da linha. `balance(): int`.
- **`App\Services\TokenCreditPolicy`** (`app/Services/TokenCreditPolicy.php`) — dona única
  das regras M.13: teto, split, chat, payout. Lê `config/monetization.php`.
  - **Split (o coração do arredondamento), `applyRate()` linha ~301:**
    ```php
    $credited = intdiv($amount * $rate + 50, 100);   // round-half-up INTEIRO
    $retained = $amount - $credited;                 // complemento, nunca recalculado
    ```
    `$rate` inteiro (70/75/80/100) de `config/monetization.php › split_rates`. `credited +
    retained == amount` SEMPRE. **Arredondamento = meio-para-cima ao inteiro mais próximo**;
    o empate (fração exatamente 0,5) vai para a **performer** (o crédito é a fatia dela).
  - `creditWithSplit()` credita a fatia da performer e congela `applied_rate` na linha.

O ledger tem **27 `entry_type`** (enum, última migration `2026_08_14_000003`):
`purchase, spend_tip, spend_private, spend_camera, payout_reserve, refund, bonus,
adjustment, tip_credit, payout_reversal, staging_seed_backfill, spend_interest_unlock,
subscription_grant, spend_chat_access, chat_access_credit, spend_boost, spend_content,
content_credit, spend_gift, gift_credit, spend_live, live_credit, spend_call, call_credit,
spend_call_reservation, call_reservation_refund, call_noshow_credit`.

---

## A. Tabela única de fluxos

Legenda de arredondamento: **EXATO** = o split sempre fecha em inteiro por construção do
preço; **round↑** = round-half-up, empate para a performer; **fixo** = crédito fixo, não é %.

| # | Fluxo | Onde | Gatilho | Paga / quanto | Recebe / split real | Fração? | Arredonda p/ | Valor definido em |
|---|-------|------|---------|---------------|---------------------|---------|--------------|-------------------|
| 1 | **Chat / abertura** | `ChatAccessService::openOrRenew` (~90-110) | **abertura** da janela (openOrRenew), idempotente por chave | Membro **2 tk** (Black/FC **1 tk**) → `spend_chat_access` | Performer **FIXO 1 tk** → `chat_access_credit` (via `policy.credit`, nunca respeita teto) | n/a (fixo) | **fixo — ver B/D** | custo: `config/monetization.php › chat.cost_by_tier`; crédito: `chat.performer_credit=1` |
| 1b | Chat / mensagens | `ChatService::sendMessage` | envio dentro da janela aberta | **0** (ilimitado por 30 dias após abrir) | — | — | — | — |
| 2 | **Gorjeta / tip** | `TipService::send` (~60-90) | **envio** da gorjeta | Membro **N tk** (livre) → `spend_tip` | Performer **80%** (`tip_credit`), Limen 20% | **SIM** (N livre) | **round↑ (mistо)** | `split_rates.tip=80` |
| 3 | **Conteúdo pago (foto E vídeo)** | `ContentUnlockService::unlock` (~95-120) | **desbloqueio** (unlock permanente) | Membro `content.price_tokens` → `spend_content` | Performer **80%** (`content_credit`), Limen 20% | **EXATO** (preço múltiplo de 5) | — | preço: `performer_content.price_tokens` (banco); split: `split_rates.content=80`; piso/passo: `content_floor=5`, `content_price_step=5` |
| 4 | **Presente / gift** | `GiftService::send` (~115-150) | **envio** do presente | Membro `gift.tokens` → `spend_gift` | Performer **75%** (`gift_credit`), Limen 25% | **EXATO** (preço múltiplo de 4) | — | catálogo fixo; split `split_rates.gift=75`; `gift_price_multiple=4` |
| 5 | **Chamada privada 1:1** | `CallService::chargeMinute` (~641) via `MinuteBiller` | **por minuto** (pré-pago, `floor(t/60)+1`) | Membro `price_per_minute` → `spend_call` | Performer **70%** (`call_credit`), Limen 30% | **round↑** (múltiplo de 5 × 0,7 → .0 ou .5) | **performer** (.5 sobe) | preço: `performer_profiles.call_price_per_minute`; piso/passo `call_min_price_per_minute=5`/`call_price_step=5`; split `split_rates.call=70` |
| 6 | **Group show 1:X** | `GroupShowService::chargeMinute` (~610) via `MinuteBiller` | **por minuto**, por participante | Cada membro `price_per_minute` → `spend_call` | Performer **70%** (`call_credit`) por participante | igual #5 | performer | igual #5 |
| 7 | **Chamada agendada — depósito** | `CallReservationService::reserve` (~124) | **agendamento** (trava o depósito) | Membro **1× preço/min** → `spend_call_reservation` | ninguém ainda (trava) | — | — | `performer_profiles.call_price_per_minute` (congelado em `price_per_min_locked`) |
| 7a | Agendada — entrada OK (minuto 1) | `CallReservationService::settleMinuteOne` (~650) | ambos entram; minuto 1 pago pelo depósito | (já pago no #7) | Performer **70%** de `price_per_min_locked` (`call_credit`), **sem novo débito** | round↑ | performer | `split_rates.call=70` |
| 7b | Agendada — refund | `settleRefund` (~588) | cancel grátis / não-confirmada / no-show da PERFORMER | — | Membro **100%** do depósito (`call_reservation_refund`, nunca teto, fora do payout) | EXATO | — | — |
| 7c | Agendada — no-show do MEMBRO | `settleNoShowMember` (~618) | membro não entra na janela | (depósito já debitado) | Performer **100%** do depósito (`call_noshow_credit`, `applied_rate=100`, ganho sacável) | EXATO | — | `split_rates.call_noshow=100` |
| 8 | **Live pública** | — (**sem emitter**) | — | **GRÁTIS** (não emite `spend_live`) | — | — | — | `spend_live`/`live_credit` existem no enum mas **nenhum serviço os emite** |
| 8a | Gorjeta/presente durante live | reusa #2 / #4 | envio | igual #2/#4 | igual #2/#4 (80/20 tip, 75/25 gift) | igual #2/#4 | igual | igual |
| 9 | **Interesse revelado** | `InterestService::unlock` (~243) | membro **desbloqueia** quem sinalizou (assinante: grátis) | Membro **15 tk** → `spend_interest_unlock` | **100% Limen** (sem crédito) | inteiro | Limen (é receita) | `config/interest.php › unlock_cost=15` (env) |
| 10 | **Boost / destaque** | `BoostService::boost` (~110) | performer aciona destaque | Performer **50 tk** → `spend_boost` | **100% Limen** (sem crédito) | inteiro | Limen | `config/boost.php › cost_tokens=50` (env); duração 6h; teto 20 |
| 11 | **Compra de tokens (PIX)** | `PaymentService::createPixCharge`+webhook (`creditPaidPurchase`) | **webhook** `PAYMENT_RECEIVED` (idempotente) | Membro paga R$ (com **desconto por tier no PREÇO**) | Membro recebe `package.tokens` → `purchase` (credita cheio mesmo acima do teto) | preço: `round()` centavos | — | pacote: **`token_packages` (banco)**; desconto: `discounts_by_tier` (config, via policy) |
| 12 | **Assinatura de Círculo** | `SubscriptionService` (Asaas cartão) | cobrança recorrente **cartão** | **R$ no cartão — NÃO toca o ledger de tokens** | 100% Limen (a assinatura em si) | — | — | `circles.price_cents` (banco) |
| 12a | Franquia mensal inclusa | `SubscriptionService::grantFranchiseFor` → `policy.grantFranchise` | 1º dia do ciclo (webhook) + reconciliação `grantDueFranchises` | — | Membro recebe franquia → `subscription_grant` (respeita teto, fila de pendência) | inteiro | — | `franchises_by_tier` (config); espelho `circles.monthly_tokens` (banco) |
| 12b | Trial Founding Members | `SubscriptionService` (trial 7d) | assinatura FC/founder | 1ª cobrança **adiada** 7d; tokens do 1º mês entram JÁ | franquia via `subscription_grant` | inteiro | — | mesmo de 12a; sem `entry_type` próprio |
| 13 | **Payout / saque** | `PayoutService::createAndSendPayout` (~120) | on-demand OU sweep mensal dia 1 | — | Performer saca **N tk × R$0,60** via PIX; `payout_reserve` (debita), `payout_reversal` (estorno em falha) | R$: `sprintf` centavos exatos | — | `payout_rate_per_token=0.60`; `min_tokens=100`; `max_tokens=50000`; allowlist `payout.earning_entry_types` |
| 14 | Bônus de pacote | — (**morto**) | — | — | — | — | — | `token_packages.bonus` existe mas é **0** no seeder e **nunca creditado**; `entry_type 'bonus'` sem emitter |
| 15 | Ajuste / seed / reconcile | `ReconcileWallets` | manual/GC | — | `staging_seed_backfill` (backfill), `adjustment` (testes) | inteiro | — | comando |

**Preço definido pela performer** (não config): `content.price_tokens` (#3),
`call_price_per_minute` (#5,#6,#7), `gift` é catálogo fixo (#4), `tip` é livre do membro (#2).

---

## B. Inconsistências encontradas

1. **Chat é a única transação membro→performer que NÃO usa split percentual.** Tudo (tip,
   conteúdo, presente, live, chamada) credita a performer por `%` via `creditWithSplit`;
   **só o chat credita um valor FIXO de 1 token** (`chatOpenPerformerCredit()`), qualquer
   tier. Consequência: a fatia efetiva da performer no chat varia com o custo do membro —
   **50% quando o membro paga 2** (não-Black), **100% quando paga 1** (Black/FC). Duas
   coisas equivalentes ("performer recebe de uma transação de comunicação") tratadas de
   forma estruturalmente diferente. (É a razão de existir a branch interrompida.)

2. **O arredondamento do split não é previsível para a performer, e nem sempre entrega o %
   do contrato.** `intdiv(amount×rate+50,100)` é round-half-up ao inteiro mais próximo. Em
   **gorjeta** (valor livre) a fatia real oscila em torno de 80% conforme o valor: ex.
   gorjeta de **3** → performer **2** (66%, não 80%); gorjeta de **2** → performer **2**
   (100%). O contrato diz "80%", o código entrega "≈80% arredondado". (Conteúdo e presente
   NÃO sofrem disso porque o preço é forçado a múltiplo de 5 / de 4 → o split fecha exato.)

3. **Constantes de dinheiro espalhadas por 5 configs, não uma.** `monetization.php` (splits,
   pacotes, franquias, payout, chat-cost), `boost.php` (50 tk), `interest.php` (15 tk),
   `chat.php`, `scheduled_call.php`. Boost e interesse (ambos 100% Limen) vivem fora do
   arquivo canônico da economia.

4. **Três valores duplicados banco↔config** (sincronizados só por teste, frágil):
   - `circles.monthly_tokens` (banco) ↔ `franchises_by_tier` (config).
   - `circles.discount_pct` (banco) ↔ `discounts_by_tier` (config).
   - `config('monetization.live_split_rate')=70` ↔ `split_rates.live.rate=70` (o próprio
     CLAUDE.md admite ser espelho).
   Quem COBRA lê a config; o banco é só exibição — mas nada no schema impede divergirem.

5. **Enum do ledger com valores mortos/nunca emitidos:** `spend_live`, `live_credit`
   (live pública é grátis — nenhum serviço emite), `bonus` (pacote bonus=0, não creditado),
   `refund` (sem emitter), `spend_private`, `spend_camera` (legado da 1ª migration, jamais
   usados). O leitor do enum não distingue "vivo" de "reservado/morto".

6. **`call_noshow` credita 100% do depósito, o minuto-1 credita 70% do mesmo preço.** No
   no-show do membro a performer leva 100% de `deposit_tokens`; na entrada normal leva 70%
   de `price_per_min_locked` (mesmo valor base). É intencional (M.13.9: compensação pelo
   horário reservado ≠ minuto de serviço), mas são dois tratamentos do "preço de 1 minuto"
   no mesmo fluxo — registre para não ser lido como bug.

7. **Preço-mínimo tem três formas para conceitos análogos:** `content_floor=5` (piso),
   `content_price_step=5` (passo), `call_min_price_per_minute=5`/`call_price_step=5`,
   `gift_price_multiple=4`. Valores e semânticas diferentes ("piso" vs "múltiplo") para a
   mesma ideia de granularidade de preço.

8. **`payout.max_tokens=50000`** é um teto de saque hardcoded na config sem menção no
   modelo M.10/M.13.5 (o mínimo 100 está documentado; o máximo 50.000 não).

---

## C. Divergências entre código e docs (CLAUDE.md / MASTER_HANDOFF)

1. **Chat — copy "você recebe 80%" × código fixo-1.** CLAUDE.md **M.13.1** define chat como
   crédito **FIXO de 1 token** → o código bate (`chat.performer_credit=1`). PORÉM **M.13.5**
   manda a UI da performer dizer *"Você recebe 80% dos tokens da transação"* como redação
   geral. Para o chat isso é **falso** (é 50% num open de 2 tk). Contradição **interna às
   docs**; o código segue M.13.1. É exatamente o gap que a economia de mensagem
   (não-commitada) tenta fechar.

2. **`token_packages` NÃO está "pré-M.13" — o doc está desatualizado.** CLAUDE.md **M.13.12**
   e o cabeçalho de `config/monetization.php` afirmam que a tabela `token_packages` "ainda
   guarda os números pré-M.13" e que a migração é "PR seguinte". **Falso hoje:** o
   `TokenPackageSeeder` já tem os valores **M.13.2** (starter 50/R$49,90, popular 105/R$99,90,
   premium 220/R$199,90, vip 580/R$499,90, bonus 0). Código e config concordam; **a NOTA de
   doc é que está errada.**

3. **Split do chat "75%" (M.3 / M.9 originais) já foi superado por M.13.1**, mas trechos
   antigos do handoff ainda falam em "performer recebe 75% do custo de abertura". O código
   não implementa 75% em chat nenhum — é fixo 1. (M.13.1 avisa que substitui; anotado para
   quem ler o texto antigo.)

4. **`circles` seed original (75/200/500/1200/2500 tokens; desconto 10/20/30/40/50)** ≠ M.13.
   A migration `2026_08_05_000001` corrige para os valores M.13.4/M.13.3 (105/230/490/1000/2100;
   10/10/15/20/25). Estado atual do banco = M.13 ✔. Só não confunda o seed da 1ª migration
   (histórico) com o vigente.

5. **Live per-block (M.5/M.13.6 "live pública 70/30 por bloco de 10 min")** está no modelo
   mas **não no código** — live pública é grátis (PR #139); `spend_live`/`live_credit` são
   enum reservado. O CLAUDE.md já registra "não implementado", mas a TABELA de M.13.6 lista
   live como fluxo cobrado — descasa do runtime.

---

## D. Onde o arredondamento favorece Limen × favorece a performer

Regra do split: `credited = round-half-up(amount × rate / 100)`; empate (0,5) → **performer**.

### Favorece a PERFORMER (ou neutro)
- **Chamada 1:1 / group / minuto-1 agendada (70%):** preço é múltiplo de 5, então
  `0,7 × 5k` dá `.0` (k par) ou `.5` (k ímpar). O `.5` **sempre sobe** → a performer ganha
  até **+0,5 tk/minuto** acima do 70% exato. Nunca favorece o Limen aqui.
- **Gorjeta em valores baixos:** gorjeta de 1 → performer 1 (**100%**); de 2 → performer 2
  (**100%**). Round-half-up sobre base pequena entrega tudo à performer.
- **No-show do membro (100/0):** performer leva o depósito inteiro (por contrato M.13.9).

### Favorece o LIMEN
- **Chat (estrutural, não arredondamento):** no open de **2 tk** (não-assinante, Explorador,
  Insider, Prestige — a MAIORIA), a performer recebe **1 (50%)** e o **Limen fica com 1
  (50%)**. Se o contrato/marketing diz "80%", **o Limen está retendo ~0,6 tk a mais por
  abertura de chat do que um "80%" entregaria** — e o chat é, pelo próprio PO, "o canal mais
  usado". **Este é o único lugar onde sobra dinheiro da performer de forma sistemática e em
  volume.** (Formalmente está dentro de M.13.1, que define chat como fixo-1; o conflito é com
  a promessa de 80% de M.13.5.)
- **Gorjeta em valores ≡ 3 ou 4 (mod 5):** ex. gorjeta **3** → performer **2** de 3 (66%),
  Limen fica com **1** em vez de 0,6 (**+0,4 tk** ao Limen); gorjeta **4** → performer **3**
  (75%), Limen **1** em vez de 0,8 (**+0,2**). É sub-token, não sistemático (depende do valor
  que o membro digita), mas quando ocorre, **é dinheiro da performer que fica com o Limen sem
  o contrato dizer** — o "80%" vira 66-75% nesses valores.

### Sem arredondamento (fecha exato — sem viés)
- **Conteúdo (80%)**: preço forçado a múltiplo de 5 → `0,8 × 5k = 4k` inteiro. Exato.
- **Presente (75%)**: preço múltiplo de 4 → `0,75 × 4k = 3k` inteiro. Exato.

### Veredito da parte D
Fora do **chat** (estrutural) e de **gorjetas em certos valores** (arredondamento para baixo),
o sistema **não** raspa dinheiro da performer — chamada e conteúdo/presente ou favorecem a
performer ou fecham exato. **A exposição real e recorrente de "dinheiro dela sem o contrato
dizer" é o chat fixo-1 sobre base 2**, e secundariamente o round-down de gorjetas de valor
`≡3,4 (mod 5)`. Ambos somem se o split de chat/gorjeta passar a ser **decimal exato** (o que
a branch `feat/fractional-token-ledger`, interrompida, faz).

---

## Anexo — arquivos-fonte lidos (HEAD)

`TokenService.php`, `TokenCreditPolicy.php`, `config/monetization.php`, `ChatAccessService.php`,
`TipService.php`, `GiftService.php`, `ContentUnlockService.php`, `CallService.php`,
`MinuteBiller.php`, `GroupShowService.php`, `CallReservationService.php`, `BoostService.php` +
`config/boost.php`, `InterestService.php` + `config/interest.php`, `PaymentService.php`,
`SubscriptionService.php`, `PayoutService.php`, `Models/{Tip,GiftSend,TokenPackage,Circle}.php`,
`database/seeders/TokenPackageSeeder.php`, migrations de `token_ledger`/`circles`.

---

## E. DEPOIS — economia decimal exata (`feat/fractional-token-ledger`, 19/08/2026)

Implementação da decisão do PO pós-auditoria: split decimal exato para TODOS os fluxos,
arredondamento só no payout. Ver CLAUDE.md **M.14** (Regra Única de Arredondamento R1–R4).

### Tabela ANTES × DEPOIS (o que mudou em cada eixo)

| Eixo | ANTES (`main` e407d08) | DEPOIS (esta branch) |
|------|------------------------|----------------------|
| Coluna do ledger | `bigInteger` amount/balance/balance_after | **DECIMAL(20,4)** (+ mirrors de tip/gift + payouts.tokens) |
| Aritmética | operador nativo PHP (int) | **bcmath escala 4** (`App\Support\TokenMath`), nunca float |
| `applyRate` | `intdiv(amount×rate+50,100)` (round-half-up) | **decimal EXATO** `bcdiv(bcmul(...))`, sem arredondamento |
| **Chat** | **FIXO 1 tk** (50% num open de 2) | **split 80/20** — 2→1,6000; 1→0,8000 |
| Gorjeta de 3 (80%) | performer **2** (66%, perde 0,40 p/ Limen) | performer **2,4000** (80% exato) |
| Conteúdo de 7 (80%) | performer 6 (arredonda) | performer **5,6000** |
| Chamada de 13 (70%) | performer 9 (round↑) | performer **9,1000** |
| Presente de 7 (75%) | — (preço múltiplo de 4) | **5,2500** (imune a preço não-redondo) |
| Arredondamento | **em cada transação** (nearest, empate p/ performer) | **NUNCA no ledger; só no payout, FLOOR** (R1/R2) |
| Sobra do arredondamento | dissolvida na transação (às vezes p/ Limen) | **preservada no saldo da performer** (R3) |
| Payout de 4,8733 | (não existia fração) | **R$2,92 floor**, consome 4,8666, sobra 0,0067 fica |
| Saldo do membro | inteiro | **inteiro** (inalterado — só a performer fraciona) |
| Preços boost/interesse | `config/boost.php`/`config/interest.php` | **centralizados em `monetization.php`** |
| `live_split_rate` / circle mirrors | duplicados banco↔config (sync por teste) | **fonte única = config**; banco deriva/removido |

### Onde ficava dinheiro dela ANTES × está resolvido DEPOIS

- **Chat fixo-1 (a leak sistemática):** ANTES a performer recebia 1 de 2 (50%) com o
  contrato dizendo 80%. DEPOIS recebe 1,6000 (80% exato). **Resolvido.**
- **Gorjeta de valor ≡3,4 (mod 5):** ANTES arredondava para baixo (Limen ficava com a
  fração). DEPOIS é exato. **Resolvido.**
- **Payout:** ANTES não fracionava; DEPOIS o único arredondamento (floor ao centavo)
  **nunca favorece a Limen** (o truncamento de `tokens_consumed` é para baixo → paga ≥ o
  que debita) e a sobra fica com a performer (R3). **Direção segura garantida.**

### Invariante nova travada por teste (`tests/Feature/FractionalTokenLedgerTest.php`)

3×1,60 = 4,8000 exato (bcmath, caminho real) · splits exatos (2,4000/5,6000/1,6000/9,1000/
5,2500) · conservação R4 (débito+crédito+comissão=0) · saldo do membro inteiro · payout
4,8733 → R$2,92 com sobra 0,0067 preservada · dado inteiro migra sem perda · replay de
chave cobra uma vez só.
