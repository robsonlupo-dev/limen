# Limen — Guia do Projeto (leia antes de qualquer tarefa)

Plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Este arquivo é o cérebro do projeto. O Claude Code deve segui-lo em toda sessão.

## Stack
- PHP 8.4.24 + Laravel 13 (`laravel/framework: ^13.8`). **`ffmpeg` instalado no
  servidor** — sanitização de upload de vídeo **entregue** no fecho do Sprint 16
  (PR #167, `VideoProcessingService`/`ProcessVideoContent`; re-encode H.264/AAC dos
  streams decodificados, strip de metadata, teto 500MB/10min). Upload no FPM
  ajustado para **512M** (`upload_max_filesize`/`post_max_size`) para o conteúdo
  em vídeo.
- MySQL 8.4 (via Docker) — banco principal
- Redis (via Docker) — cache/filas
- Front-end: **Inertia + Vue 3 + Tailwind v4** (+ Ziggy para rotas no JS).
  Blade sobrou só no layout raiz. Mudar de stack, só com aprovação do PO.
- Pagamento: Asaas / PIX (entregue na fundação)
- Realtime: Laravel Reverb (chat). O servidor Reverb **ainda não roda** —
  dev/staging usam o driver `log`. Ver `config/broadcasting.php`.
- Streaming de vídeo (LiveKit): **IMPLEMENTADO no Sprint 15** (PRs #138–#145 —
  live pública, chamada 1:1, group show). SDK `agence104/livekit-server-sdk` no
  `composer.json`, `config/livekit.php`, `LiveKitService` como dona única de rooms
  e JWTs. Credenciais no `.env` (`LIVEKIT_API_KEY`/`LIVEKIT_API_SECRET`/
  `LIVEKIT_URL`; segredo fora do Git — princípio nº 5). **Sobe DESLIGADO em
  produção**: `FEATURE_LIVE_ENABLED`/`FEATURE_CALL_ENABLED` off (dark launch — a
  liberação é jurídica, muda só o `.env`, zero deploy). O § 2.5 (serving sem cifra)
  está resolvido por arquitetura: não há serving HTTP de bytes de vídeo — o SFU faz
  o relay via WebRTC (DTLS-SRTP) e o backend só emite tokens.

## Princípios de arquitetura (não negociáveis)
1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados, prevenção de conteúdo ilegal. É fundação, não feature.
2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer `UPDATE ... saldo = saldo + x`. Todo movimento é uma linha nova em `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não repetir.)
   - **Ressalva de leitura (não é violação):** existe `token_wallets.balance` como **cache materializado**. `TokenService::credit/debit` escrevem esse campo por `UPDATE` de **valor absoluto** (`balance = <novo saldo calculado em PHP>`) **sob `lockForUpdate`**, na mesma transação da linha do ledger — nunca o padrão **aditivo** `balance = balance + x` que o princípio proíbe. O invariante `balance == SUM(token_ledger.amount)` vale por construção (crédito/débito/`releaseAfterDebit` sempre escrevem valor e linha juntos). Portanto um `UPDATE ... token_wallets ... balance` no log de queries é esperado; o que seria bug é o SQL aditivo ou o saldo divergindo da soma. (Achado do Pre-Flight Sweep, ago/2026.)
3. **Idempotência em pagamento.** Crédito de tokens só via webhook idempotente por id de evento. Reprocessar nunca duplica saldo.
4. **PII isolada e criptografada.** CPF, documentos e dados de verificação ficam em tabela separada, criptografados em repouso, em storage privado. Nunca em log, nunca em URL.
5. **Nada de segredo no Git.** Tudo em `.env` (fora do versionamento). 
6. **Dados reais só em produção.** Dev/staging usam dados sintéticos.

## Convenções
- Migrations versionadas para TODA mudança de schema. Nunca alterar o banco à mão.
- Validação sempre via Form Requests (nunca confiar no input cru).
- Queries via Eloquent/Query Builder com bind. Nunca concatenar string em SQL.
- **Duas portas de auth, não confundir:** a API (`/api/v1/*`) usa Sanctum; o
  frontend Vue fala com as rotas **web** (sessão + CSRF). Consequência prática:
  fora de `api/*` uma exceção não vira JSON automaticamente — erro que o front
  precisa consumir exige `response()->json()` explícito.
  **Isso vale para VALIDAÇÃO também:** `shouldRenderJsonWhen` só liga o JSON em
  `api/*`, então uma `ValidationException` numa rota web vira
  redirect-com-erros-de-sessão **mesmo com `Accept: application/json`** — e o
  `fetch` do front recebe HTML. Endpoint web novo que o JavaScript consumir usa o
  trait `App\Http\Controllers\Web\Concerns\FailsValidationAsJson` (achado do
  Sprint 9B).
- **`SubstituteBindings` roda ANTES do middleware de rota.** Teste de gate com id
  inexistente leva 404 do binding e **passa sem exercitar o gate** — use id
  existente ao testar `role`, `2fa` ou `documents.accepted`.
- Dinheiro/tokens como inteiros (centavos / tokens), nunca float.
- **Design tokens `limen-*` (redesign maison, Sprint 16).** A paleta do front vive
  no `@theme` de `resources/css/app.css`, e **todo componente novo de catálogo/
  perfil usa esses tokens**, não hex solto nem cor Tailwind crua:
  `limen-bg` (#181410, fundo), `limen-surface`/`limen-surface-2` (cartões),
  `limen-ink`/`limen-ink-soft`/`limen-ink-mute` (texto em três pesos),
  `limen-gold` (#d6b872, destaque/curadoria), `limen-line` (bordas). **`limen-live`
  (#e24b4a) é EXCLUSIVO do estado "ao vivo"** — badge/borda de live e nada mais;
  usá-lo fora disso quebra o significado da cor. Tokens antigos (`cream`, `gold` do
  tema legado) continuam onde já estavam, mas tela nova entra em `limen-*`.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.
- **Ao adicionar seção nova ao handoff (`docs/MASTER_HANDOFF_FINAL.md`), use um
  título descritivo único (nome da feature ou data), NUNCA número sequencial
  (`A.0.N`).** A numeração sequencial colide quando duas branches criadas em paralelo
  adicionam a "próxima" seção com o mesmo número — foi o que forçou o renumber
  "A.0.4 → A.0.9". Título descritivo não colide. (Ver a nota de convenção no topo do
  Apêndice A do handoff.)

## Fluxo de trabalho
- O Product Owner (Robson) abre issues no GitHub para bugs e mudanças.
- Cada sprint termina com: suíte de testes verde + passo de debug + revisão de segurança.
- Antes de implementar algo sensível (cadastro, KYC, pagamento, payout), rodar o subagente de segurança.

## Regra de Ouro — Git Flow

**Nenhum commit direto na `main`.** O Limen lida com pagamentos e dados sensíveis;
um erro na main derruba o site em produção.

### Fluxo obrigatório para toda feature/fix do Sprint 7 em diante:

1. Criar branch a partir da main:
   `git checkout -b feat/sprint7-<descricao-curta>`

2. Desenvolver e commitar na branch

3. Abrir PR no GitHub apontando para main

4. Aguardar aprovação do Robson antes de mergear

5. Após aprovação: merge via GitHub (squash ou merge commit — nunca force push na main)

### Nomenclatura de branches:
- `feat/sprint7-<descricao>` — nova feature
- `fix/sprint7-<descricao>` — correção de bug
- `docs/<descricao>` — documentação apenas

### Exceções permitidas (único caso):
- Commits de documentação pura (ex: atualização de MASTER_HANDOFF_FINAL.md ou CLAUDE.md)
  podem ir direto na main, desde que não toquem em código PHP, Vue ou configuração.

## Modelo de tokens (resumo — implementado na fundação)
- Cliente compra pacotes de tokens via PIX.
- Cliente gasta tokens (gorjeta, sessão privada).
- No gasto, a plataforma retém um split por nível do performer; o restante credita o performer.
- Tudo isso é registrado no `token_ledger` (append-only).

## Modelo de monetização — DECISÕES FECHADAS pelo PO (referência canônica)

**Fechado pelo PO (Robson) em 03/08/2026. É a referência para TODA implementação
futura de monetização** — pacotes, chat, conteúdo, live, chamada, assinatura,
payout. O detalhe completo (com os porquês) vive em `docs/MASTER_HANDOFF_FINAL.md`,
seção **"MODELO DE MONETIZAÇÃO LIMEN — DECISÕES FECHADAS"**. Este resumo situa; ao
implementar, leia a seção lá. **Onde este modelo conflita com `docs/SUBSCRIPTION_TIERS.md`
ou `docs/CIRCLES_SYSTEM_V4.md` (a divergência do §19.2 do handoff), ESTE modelo
vence** — e os slugs de tier são os do `Circle::TIER_ORDER`
(`explorador / insider / prestige / black / founders_circle`).

- **[M.1] Moeda única: TOKENS.** Tudo (chat, conteúdo, live, gorjeta, presente) passa
  por tokens. Tokens **NUNCA expiram**. Teto de acúmulo **5.000**: no teto, o
  assinante continua pagando a assinatura mas os tokens **não creditam** até
  gastar (vale inclusive para a franquia mensal do tier). A performer vê o **R$
  equivalente** ao lado do preço em tokens.
- **[M.2] Pacotes (compra via PIX), preço cheio:** Starter R$49,90=50 · Popular
  R$99,90=110 · Premium R$199,90=240 · VIP R$499,90=650. **Desconto por tier**
  (aplica sobre a compra): Explorador/Insider 10% · Prestige 20% · Black 30% ·
  FC 40%.
- **[M.3] Chat:** abrir custa 2 tokens (não-assinante), 1 token (Explorador/Insider/
  Prestige), **grátis** (Black/FC — a Limen subsidia e paga 1 token à performer).
  Aberto → mensagens ilimitadas por **30 dias**. Performer recebe **75%** do custo
  de abertura.
- **[M.4] Conteúdo (fotos/vídeos PERMANENTES):** a performer define nível
  (**Aberto / Premium / Exclusivo / FC Only**) + preço em tokens. Aberto é grátis
  para todo assinante; Premium exige Prestige+; Exclusivo exige Black+; FC Only só
  FC. Explorador/Insider só veem o Aberto (incentiva upgrade). Desbloqueado é
  **permanente**. Split **80% performer / 20% Limen**.
- **[M.5] Live pública:** performer define X tokens por bloco de **10 min**; **todos
  pagam** (inclusive FC, com inclusos ou comprados); não-assinante assiste pagando
  cheio. Split **70/30**. Gorjeta/presente durante a live: **80/20**.
- **[M.6] Chamada privada (1:1 vídeo):** performer define X tokens/minuto; **todos
  pagam**. Split **70/30**.
- **[M.7] Assinaturas dos Círculos** (100% Limen, sem split): Explorador R$89,90 (chat
  1 tk, 50 inclusos, −10%) · Insider R$189,90 (chat 1 tk, 120 inclusos, −10%) ·
  Prestige R$389,90 (chat 1 tk, +Premium, 250 inclusos, read receipts (atualizado
  por M.13.13: read receipts para todos os assinantes), −20%) ·
  Black R$749,90 (chat GRÁTIS, +Exclusivo, 500 inclusos, Ghost Mode + Modo
  Discreto, −30%, cap 500) · FC R$1.490,00 (chat GRÁTIS, TUDO, 1.200 inclusos,
  número permanente + milestones, −40%, cap 100).
- **[M.8] Tokens inclusos:** creditados no 1º dia do ciclo, **não expiram** (entram no
  saldo normal, `subscription_grant` no ledger), sujeitos ao teto de 5.000 (no
  teto, não credita até gastar).
- **[M.9] Split por tipo de evento:** conteúdo 80/20 · chat (abertura) 75/25 · live
  70/30 · chamada 70/30 · gorjeta 80/20 · presente 75/25 · **boost 100% Limen** ·
  **interesse revelado 100% Limen** · **assinatura 100% Limen**.
- **[M.10] Payout da performer:** **sweep automático mensal no dia 1** referente ao
  mês anterior (`payouts:process-monthly`, idempotente por (performer, ano, mês)),
  **mais** saque **on-demand** disponível a qualquer momento (os dois convivem —
  decisão do PO 04/08/2026, PR #134). Mínimo **100 tokens** em ambos, via PIX
  (Asaas). **Só ganhos são sacáveis** (M.13.5): `payable = min(ganhos_devidos,
  saldo)`, somando só o allowlist de `*_credit` de ganho (tip_credit,
  chat_access_credit, …) — NUNCA purchase/bonus/subscription_grant/refund (sacá-los
  a R$0,60 seria leak). R$ equivalente (R$0,60/token fixo) visível no dashboard.
- **[M.11] LiveKit (infra de live/chamada):** Fase 1 lançamento = LiveKit Cloud Build
  (grátis, 5.000 min/mês); Fase 2 crescimento = Cloud Ship ($50/mês); Fase 3
  escala = self-hosted Hetzner (~R$230/mês fixo). Custo real ~R$0,01/min por
  participante (margem 98%+).
- **[M.12] Presentes virtuais (BACKLOG, não implementado):** catálogo fixo da Limen,
  preços fixos (ex.: Rosa 5 tk, Champagne 50 tk), split **75/25**, animação na
  tela durante a live.

> **Nota de implementação vs. estado atual:** este é o modelo-alvo fechado, **não
> um inventário do que já roda**. Hoje o ledger só tem os `entry_type` do §6.2 do
> handoff (não há `spend_content`, `spend_live`, `spend_call`, `gift_*`); live,
> chamada, conteúdo permanente e presentes **não estão implementados**. Cada novo
> tipo de gasto/crédito é **migration no enum de `entry_type`** (princípio nº 2,
> ledger append-only) — nunca `UPDATE` de saldo.

## M.13 — Emenda de 03/08/2026: invariantes da economia de tokens (SUBSTITUI M.1–M.9 onde conflitar). M.10 (Payout), M.11 (LiveKit) e M.12 (Presentes) seguem vigentes — M.13.5 e M.13.6 os complementam sem substituir.

Fechado pelo PO após benchmark de mercado e simulação de margem. Cada item é invariante — mudar exige decisão de PO registrada.

### M.13.1 — Chat: crédito fixo, sem percentual (SUBSTITUI M.3 e coluna "Chat" de M.7)

Split percentual superado para chat. Não existe arredondamento que preserve 75/25 sobre base de 1 ou 2 tokens.

| Quem abre                          | Membro paga | Performer recebe | Resultado Limen |
|------------------------------------|-------------|------------------|-----------------|
| Não-assinante                      | 2 tk        | 1 tk (fixo)      | +1 tk           |
| Explorador / Insider / Prestige    | 2 tk        | 1 tk (fixo)      | +1 tk           |
| Black                              | 1 tk        | 1 tk (fixo)      | 0               |
| FC                                 | 1 tk        | 1 tk (fixo)      | 0               |

Black passou de grátis para 1 token (mudança pré-lançamento). Explorador/Insider/Prestige passaram de 1 para 2 tokens (benefício real desses tiers é franquia e conteúdo, não chat). Subsídio eliminado — nenhum tier gera custo para a Limen no chat. entry_type continua chat_access_credit; NÃO criar tipo separado. Chat é canal, não receita.

### M.13.2 — Pacotes achatados (SUBSTITUI tabela de M.1)

| Pacote  | Preço     | Tokens | R$/token |
|---------|-----------|--------|----------|
| Starter | R$ 49,90  | 50     | R$ 1,00  |
| Popular | R$ 99,90  | 105    | R$ 0,95  |
| Premium | R$ 199,90 | 220    | R$ 0,91  |
| VIP     | R$ 499,90 | 580    | R$ 0,86  |

Âncora de R$1,00/token no Starter é inviolável.

### M.13.3 — Desconto por tier (SUBSTITUI M.2)

Explorador 10% · Insider 10% · Prestige 15% · Black 20% · FC 25%. Invariante: nenhuma combinação de pacote + desconto pode levar custo efetivo abaixo de R$0,625/token (margem mínima 25%).

### M.13.4 — Franquia mensal dos Círculos (SUBSTITUI inclusos de M.7)

| Tier        | Assinatura    | Inclusos/mês | R$/token implícito |
|-------------|---------------|--------------|---------------------|
| Explorador  | R$ 89,90      | 105          | R$ 0,86             |
| Insider     | R$ 189,90     | 230          | R$ 0,83             |
| Prestige    | R$ 389,90     | 490          | R$ 0,80             |
| Black       | R$ 749,90     | 1.000        | R$ 0,75             |
| FC          | R$ 1.490,00   | 2.100        | R$ 0,71             |

Tokens inclusos: subscription_grant, não expiram, sujeitos ao teto (M.13.8).

### M.13.5 — Payout: R$0,60/token fixo (NOVO)

Cada token vale R$0,60 no saque, sempre, independente da origem. Redação obrigatória na interface da performer: "Você recebe 80% dos tokens da transação. Cada token vale R$0,60 no saque." NUNCA escrever porcentagem sobre valor em reais.

### M.13.6 — Split: do tipo de evento, nunca do lugar (SUBSTITUI M.5; CONFIRMA M.9)

| Tipo de evento              | Split (performer / Limen) |
|-----------------------------|---------------------------|
| Conteúdo permanente         | 80 / 20                   |
| Gorjeta                     | 80 / 20                   |
| Chat (abertura)             | fixo 1 tk (fora do %)     |
| Presente virtual            | 75 / 25                   |
| Live pública (por bloco)    | 70 / 30                   |
| Chamada privada (por min)   | 70 / 30                   |
| Boost / Interesse revelado  | 100% Limen                |
| Assinatura de Círculo       | 100% Limen                |

Catálogo de presentes em múltiplos de 4 tokens (invariante validada): Rosa 4 · Chocolate 12 · Champagne 40 · Joia 100 · Coroa 200 · Diamante 400.

### M.13.7 — Arredondamento: regra única para split percentual

1. Taxa gravada na linha do ledger em inteiro (70, 75, 80). 2. Congelada na transação, nunca recalculada. 3. credito = intdiv(valor * taxa + 50, 100); retencao = valor - credito. 4. Round-half-up, inteiros, nunca float. 5. Piso de 5 tokens em conteúdo/live/chamada. 6. Tabela em config/monetization.php por entry_type com data de vigência.

### M.13.8 — Teto de acúmulo: escalonado + fila de pendência (SUBSTITUI teto fixo de M.1)

Teto = max(5.000, 4 × franquia), com FC fixado em 8.000 pelo PO. Sem assinatura até Black = 5.000; FC = 8.000. O teto é incentivo a gastar, não confisco. Fila de grant pendente: credita o que couber, pendura o resto; pendência máxima = 1 franquia (ciclo novo substitui, não empilha); não expira; consumo automático parcial a cada gasto. Aviso quando espaço_restante ≤ 2 × franquia_do_tier (4.500 fixo para não-assinante).

### M.13.9 — O teto é propriedade do MOVIMENTO, não da pessoa

saldo > teto é estado legítimo. NÃO criar constraint. Invariante real: purchase, bonus e subscription_grant não creditam acima do teto. Chave é entry_type, nunca role (performer pode assinar Círculo). Respeitam teto: purchase, bonus, subscription_grant. Nunca respeitam: tip_credit, chat_access_credit, refund, payout_reversal, adjustment, todo *_credit. Compra acima do teto: barrada no checkout; webhook que chegar mesmo assim credita e loga.

### M.13.10 — Tier do membro NÃO é visível para a performer

Performer não vê tier nem bit "assinante". Exceção única: FC Only revela FC no desbloqueio. Mostrar depois é fácil, esconder depois é impossível.

### M.13.11 — Margem mínima de 25%

Nenhuma transação pode resultar em margem bruta abaixo de 25%. Piso de custo efetivo: R$0,625/token. Se mudar payout, split ou desconto, recalcular antes de implementar.

### M.13.13 — Tabela consolidada de benefícios por Círculo

Regra geral: o tier dá a chave de acesso; o conteúdo é pago com tokens (preço definido pela performer). "Paga tokens" = tem acesso, paga para desbloquear. "Grátis" = acesso sem custo. "❌" = sem acesso ao nível.

Tipos de conteúdo (foto, álbum ou vídeo — mesma estrutura, mesmos níveis):
- Aberto: vitrine da performer; grátis para assinantes, pago para não-assinante (degustação que incentiva assinatura).
- Premium: primeiro nível pago; acesso a partir de Prestige.
- Exclusivo: acesso a partir de Black; conteúdo mais íntimo, preço mais alto.
- FC Only: só FC pode desbloquear; é o perk que justifica R$1.490/mês e a única situação onde a performer sabe que o membro é FC (M.13.10).

| Benefício | Não-assinante | Explorador | Insider | Prestige | Black | FC |
|---|---|---|---|---|---|---|
| Chat (abertura) | 2 tk | 2 tk | 2 tk | 2 tk | 1 tk | 1 tk |
| Conteúdo Aberto | Paga tokens | Grátis | Grátis | Grátis | Grátis | Grátis |
| Conteúdo Premium | ❌ | ❌ | ❌ | Paga tokens | Paga tokens | Paga tokens |
| Conteúdo Exclusivo | ❌ | ❌ | ❌ | ❌ | Paga tokens | Paga tokens |
| Conteúdo FC Only | ❌ | ❌ | ❌ | ❌ | ❌ | Paga tokens |
| Live pública | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens |
| Chamada privada | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens |
| Gorjeta | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens |
| Presentes | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens |
| Read receipts | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Tokens inclusos/mês | — | 105 | 230 | 490 | 1.000 | 2.100 |
| Desconto pacote avulso | — | 10% | 10% | 15% | 20% | 25% |
| Ghost Mode | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Modo Discreto | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Número FC permanente | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Milestones físicos | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Teto de acúmulo | 5.000 | 5.000 | 5.000 | 5.000 | 5.000 | 8.000 |
| Cap de vagas | ∞ | ∞ | ∞ | ∞ | 500 | 100 |

Read receipts atualizado: todos os assinantes (Explorador a FC), não apenas Prestige+ como no M.7 original. Não-assinante não tem (chat dele já é limitado a 2 tk + 30 dias).

### M.13.12 — Estado de implementação

Nada de M.13 está implementado até o PR #130. O bloco de monetização acima (seção "Modelo de monetização — DECISÕES FECHADAS", antes desta emenda) (pacotes, chat, descontos, inclusos) fica superado por M.13 — ambos presentes, M.13 tem precedência.

**`entry_type` do agendamento de chamada (PR #170, `feat/scheduled-call-v1`).** Três
tipos novos no enum do ledger append-only (migration própria, princípio nº 2 — nunca
`UPDATE` de saldo), somados aos de live/chamada do Sprint 15
(`spend_live`/`live_credit`/`spend_call`/`call_credit`):
- **`spend_call_reservation`** — débito do depósito do membro no ato do agendamento
  (trava os tokens; preço/min congelado na reserva).
- **`call_reservation_refund`** — crédito 100% ao membro (cancel grátis, reserva não
  confirmada, no-show da performer). É devolução, não ganho: **NUNCA respeita teto**
  (M.13.9, fora de `cap_respecting_entry_types`) e **fora** do `payout.earning_entry_types`.
- **`call_noshow_credit`** — crédito 100/0 à performer no no-show do MEMBRO
  (compensação pela reserva do horário; `applied_rate=100`). É **ganho sacável** →
  entra no `payout.earning_entry_types`.
A entrada bem-sucedida (minuto 1 pago pelo depósito) reusa `call_credit` (70/30) do
PR #140 — sem tipo novo.

## Estado atual

> **Estado atual** (`main`, `92ba2c7`): **2000 testes, 15960 asserts** (1999 passam
> local — a única falha é a antiga da view 451 do GeoBlock, que não recorre depois do
> `npm run build`, que compila a view — ver § "Ambiente de dev"). **130 migrations,
> ~205 rotas web + 42 rotas API.** A **fila de melhorias** (itens 2–5) e o polimento
> premium foram **consolidados na `main`** nesta sessão — sete PRs mergeados em
> sequência a partir do fecho do Agendamento de chamada (`db007b3` → docs #169 →
> catálogo-de-membros-home #173 `67f88a0` → os sete abaixo). O detalhe completo vive
> em **`docs/MASTER_HANDOFF_FINAL.md`** — esse é o doc a ler antes de pegar tarefa (o
> `MASTER_HANDOFF_SPRINT6.md` é histórico). Este resumo só situa. **Base original**
> (PR #69, `229d852`): 556 testes, 2614.
>
> **Consolidado na `main` nesta sessão (todos MERGEADOS, `92ba2c7`):**
>
> - **Turnstile — driver de captcha switchável** (PR #174, `04ea58b`): o que era só
>   hCaptcha virou `CAPTCHA_PROVIDER=none|hcaptcha|turnstile` (`config/captcha.php`,
>   `App\Services\Captcha\*`, regra `CaptchaValid`, `Captcha.vue`). Sobe DESLIGADO
>   (`none`, no-op). Motivado pelo fim do trial Pro do hCaptcha (11/08/2026). Ver §
>   "Captcha".
> - **Sinais de atividade nos catálogos** (PR #175, `f3ec9b1`, +10 testes): selo
>   "Nova/Novo" (janela de 7 dias, `NewBadge`, BOOLEANO derivado) nos dois catálogos +
>   contadores de não-vistos na nav (`NavBadgeService`/`nav_counts` — mensagens não
>   lidas respeitando o paywall + corações recebidos via watermark `hearts_seen_at`).
>   **"Online agora" NÃO entrou** (decisão do PO — colide com a granularidade "hoje" do
>   `ActivitySlot` e a não-exposição de presença do membro). Ver § "Sinais de atividade
>   nos catálogos".
> - **Teaser da mensagem bloqueada** (PR #176, `17ba83e`, +11 testes): corte
>   **SERVER-SIDE** das primeiras ~3 palavras da mensagem paga (o membro sem acesso
>   nunca recebe o corpo completo — borrar via CSS vazaria no DevTools). Dona única
>   `App\Support\MessageTeaser` + `config/message_teaser.php`. A economia (gate M.13.1)
>   não muda; só o preview. Ver § "Teaser da mensagem bloqueada".
> - **Filtro de cidade CONSENTIDO no catálogo de performers** (PR #177, `191d384`,
>   +14 testes, item 4 da fila): autocomplete do IBGE (~5.570 municípios em
>   `public/data/ibge-municipios.json`, self-hosted, zero asset externo) + opt-in
>   `findable_by_city` (default OFF). A cidade da performer **continua interna** (só UF
>   é pública); ela só passa a FILTRAR a busca de quem ligou o opt-in, e **nunca é
>   exibida**. NÃO toca o catálogo de MEMBROS. Ver § "Filtro de cidade consentido".
> - **Visitas bidirecionais** (PR #178, `f6597d3`, +19 testes, item 5 da fila): o
>   sentido INVERSO das visitas (performer → membro) — a performer abre o "perfil" de
>   um membro e o membro vê **"Quem visitou seu perfil"** (`/quem-me-visitou`) com a
>   identidade PÚBLICA da performer (sem FanAlias, sem piso, sem paywall). Tabela nova
>   `member_profile_visits` (separada de `profile_visits`); `ProfileVisitService`
>   ESTENDIDO. Ghost Mode não se aplica ao inverso. v1 sem monetização. Ver § "Visitas
>   bidirecionais".
> - **Microinterações premium** (PR #179, `16ce24a`, +3 testes): camada puramente
>   VISUAL (CSS puro, zero biblioteca, zero asset externo) — lift dos cards, micro-pulso
>   dos botões, "pop" do coração, fade de página, barra dourada de loading, slide do
>   erro de formulário. Tudo desligado sob `prefers-reduced-motion`, sem tocar
>   mobile/lógica/privacidade. Dona única `resources/css/micro-interactions.css`. Ver §
>   "Microinterações premium".
> - **Intro de voz da performer** (PR #180, `92ba2c7`, +28 testes): PRIMEIRO áudio do
>   projeto (greenfield). Clipe ≤20s no perfil, GRÁTIS de ouvir, opt-in. Higienizado por
>   ffmpeg (MP3 mono, strip de TODO metadado — `VoiceProcessingService`, separado do
>   vídeo) e **NÃO vai ao ar sem MODERAÇÃO HUMANA** (`processing → pending →
>   approved/rejected`; só `approved` é servível). Motivo (PO): áudio dribla o filtro de
>   texto do chat — risco art. 228; anti-CSAM não se aplica a áudio, o humano é o gate.
>   Fila `/moderacao/apresentacoes-de-voz`, disco privado, serving por request. Ver §
>   "Intro de voz da performer".
>
> Todos com revisão de segurança rodada (sem 🔴/🟡, salvo os 🟡/🟢 já corrigidos
> registrados nas §§ respectivas). A **dependência dura** das visitas bidirecionais — o
> catálogo de membros como HOME + motor de engajamento coração/mensagem — mergeou antes,
> no #173 (`67f88a0`); ver §§ "Catálogo de membros como HOME" e "Motor de engajamento".
>
> **Landing CINEMATOGRÁFICA — MERGEADA (`feat/landing-cinematic`, PR #184):** a raiz
> pública `/` virou a "porta do clube": 5 cenas de tela cheia com scroll-storytelling
> (abertura em vídeo → portal → verificação → mistério → convite), dourado e mistério,
> substituindo o hero-maison do PR #153. Mídia 100% SELF-HOST em `public/landing/*`
> (WebP desktop+mobile <400KB cada + 1 MP4 mudo ~0,9MB, otimizados por ffmpeg a partir
> dos PNGs de 2–7MB) — vídeo só no desktop, `prefers-reduced-motion` e mobile caem na
> `porta.webp` estática, lazy-load abaixo da dobra. `ExternalAssetPolicyTest` verde
> (tudo relativo `/landing/…`; o Nginx ganhou um `^~ /landing/` para servir os assets —
> fix pós-merge). Só a raiz pública muda; nenhuma tela interna tocada.
>
> **Em branch (`feat/landing-waitlist-focus`, a partir da `main`, +5 testes → 2017
> testes / 16083 asserts, PR pendente):** ajuste de **PRÉ-LANÇAMENTO** da landing. **A
> landing não oferece mais cadastro** — o botão "Solicitar convite" → `/cadastro` saiu
> da cena do convite e o **único CTA passa a ser "Entre na lista de espera"** (o backend
> de `/cadastro` fica intacto; volta no lançamento). O **header da landing esconde
> Entrar / Criar conta** (fica só o logo) via flag `features.landing_prelaunch` (default
> TRUE) passada ao `GuestLayout` — escopo só na landing, reativa no lançamento só pelo
> `.env`. Além disso: cena 2 com **fade dirigido por scroll** e arco em brilho pleno
> (texto no terço inferior, abaixo do LIMEN); cena 5 (moldura) **full-bleed
> `object-cover`**; a seção da lista de espera ganha **fundo de mármore escurecido** +
> **aviso de SPAM** na tela de sucesso ("confira a caixa de spam · marque como não é
> spam"). Reduced-motion e mobile honrados. `ExternalAssetPolicyTest` verde. Ver §
> "Landing cinematográfica — foco em lista de espera".

**Sprints 6, 7, 8, 9A, 9C, 10, 11, 12, 13, 14, 15 e 16 fechados** (tags `v1.0-sprint6`
a `v1.0-sprint9a`, **`v1.0-sprint9`** no fecho do 9C, **`v1.0-sprint9.1`** no fecho
dos bloqueadores da Foto Efêmera, **`v1.0-sprint10`** (`402d29e`) no fecho do
Sprint 10, **`v1.0-sprint11`** (`11354b4`) no fecho do Sprint 11,
**`v1.0-sprint12`** (`f23368a`) no fecho do Sprint 12, **`v1.0-sprint13`**
(`1d63371`) no fecho do Sprint 13, **`v1.0-sprint14`** (`0f6aefb`) no fecho do
Sprint 14, e **`v1.0-sprint15`** (`bf1c3dd`) no fecho do Sprint 15). **O Sprint 16
fechou em `37d8cec` (PRs #151–#166); os PRs #167/#168 mergearam logo depois (`main`
em `55de8cd`) e ainda não há tag** — o fecho é este doc.
**O Sprint 9B não tem tag própria** e não está fechado.

> **Sprint 15 fechou com 8 entregas** (tag `v1.0-sprint15`, `bf1c3dd`) — **vídeo
> em tempo real (LiveKit)**, planejado desde a fundação e nunca implementado até
> aqui. Cada gasto/crédito novo virou migration no enum de `entry_type` do ledger
> append-only (princípio nº 2, `spend_live`/`live_credit`/`spend_call`/`call_credit`),
> nunca `UPDATE` de saldo; a cobrança por minuto/bloco é pré-paga, saldo nunca
> negativo, split por evento congelado (`applied_rate`). PR #138 (**infra LiveKit +
> token service** — `LiveKitService` dona única de rooms/JWTs, `config/livekit.php`,
> feature flags de dark launch, identity opaca/FanAlias por par, room_name nunca em
> URL/log), PR #139 (**live pública grátis** com gorjeta/presente — serving por
> sessão, sem URL assinada, badge no catálogo, gorjeta 80/20 e presente 75/25 pelas
> rotas existentes), PR #140 (**chamada privada 1:1** com cobrança por minuto — split
> 70/30, request/accept/decline, heartbeat pré-pago idempotente por minuto,
> `MinuteBiller` como motor único, exclusividade sob lock, ban/kill-switch,
> `calls:reap-stale`), PR #141 (**group show 1:X** com upgrade para 1:1 — 1 sessão
> `type=group` + N `call_session_participants` com cobrança independente, upgrade
> com revoke de 10s por job, exclusividade bidirecional), PR #142 (**animação de
> gorjeta/presente na live** — evento broadcast `LiveReaction` no canal `live.{slug}`,
> `<LiveOverlay>` com fila, payload não-sensível FanAlias-only), PR #143 (**preview
> animado no catálogo** — frame JPEG por sessão capturado do canvas a cada 10s,
> disco privado `live_previews`, ServesPhotoBytes, `live-previews:purge`), PR #144
> (**toast global de mensagem** estilo Seeking — `<MessageToast>` no AppLayout,
> `NewMessage` com sender mascarado por destinatário, nunca o corpo), PR #145
> (**"Em breve"** em produção — flags compartilhadas como props Inertia,
> `<ComingSoon>`, todas as rotas de live/call/group gateadas por `feature:*`).
> **Resolução do § 2.5** (serving sem cifra em memória, que travou as FC Sessions):
> **não há serving HTTP de bytes de vídeo** — o LiveKit SFU faz o relay do vídeo
> via WebRTC (DTLS-SRTP fim-a-fim); o backend só emite tokens JWT curtos e controla
> permissão (quem entra na sala, por quanto tempo). O gargalo histórico deixou de
> existir por arquitetura, não por cifra em memória. **Deploy de staging pendente**;
> as features sobem com `FEATURE_LIVE_ENABLED`/`FEATURE_CALL_ENABLED` **off** (dark
> launch — liberação é decisão jurídica, muda só o `.env`). A tag é marco de código,
> não de go-live (ver abaixo). **Não iniciado (foi para Sprint 16):** feed de
> conteúdo permanente, sanitização de upload de vídeo, verificação de documento,
> animações elaboradas de presente, preview via WebRTC real, som de notificação.

> **Sprint 16 fechou com 16 PRs** (`main` em `37d8cec`, PRs #151–#166, **sem tag**
> — o fecho é este doc). Duas frentes: **redesign "maison"** do front (catálogo,
> landing, card, perfil, crop de avatar/capa) e **novas superfícies de produto**
> (feed de conteúdo, catálogo de membros, anti-CSAM, receita real no admin, som de
> notificação). PR #151 (**PanicButton visível ao membro** — bug de visibilidade),
> PR #152 (**card v2** do catálogo no design system `limen-*`), PR #153 (**landing
> redesenhada**), PR #154 (**grid v2 + trilha "Agora"** com lives/stories + slot de
> Destaque), PR #155 (**PanicButton vira link de texto no header** — o disco sozinho
> era lido como "fechar"; achado do UAT), PR #156 (**crop interativo** de avatar
> 1:1 e capa 3:1 com cropperjs, avatar agora passa pelo `ImageProcessingService`),
> PR #157 (**limpeza de ruído de log** — pula pagamentos `pay_fake_` na reconciliação
> e protege `LivePreviewService::purgeOrphans` contra disco ausente), PR #158
> (**perfil da performer redesenhado** na estética maison), PR #159 (**feed do
> membro** `/feed` — consome o backend de conteúdo permanente do PR #135; desbloqueio/
> serving reusam `content.*`), PR #160 (**dashboard admin de receita** `/admin/dashboard`
> — `AdminMetricsService`, só `role:admin`, **zero PII de membro**), PR #161
> (**anti-CSAM MVP** — § abaixo), PR #162 (**preview WebRTC real** no hover do card,
> v2 do snapshot JPEG), PR #163 (**animações de presente por partícula/sprite** no
> `<LiveOverlay>`, v2 da CSS animation simples), PR #164 (**receita real** — o
> dashboard passa a somar `payments` confirmados, não estimativa de ledger), PR #165
> (**catálogo de membros para a performer** — § abaixo, `MemberCatalogService`,
> Interesse Controlado invertido), PR #166 (**som de notificação + preferências** —
> § abaixo, `users.notification_preferences`). **Mergeados APÓS o fecho inicial (PRs
> #167/#168, `main` em `55de8cd`):** a **sanitização de upload de vídeo** (PR #167 —
> `performer_content.kind` ganhou `'video'` + coluna `status`
> `processing→ready/failed`; migration `2026_08_10_000001_add_video_support_to_performer_content`)
> e o **selo de curadoria "maison/select"** (PR #168 — `<CurationSeal>` no perfil e
> na `Catalog/Show`/`Performers/Show`). **Não iniciados:** verificação de documento
> (Didit), hCaptcha em produção, pin PHP 8.5→8.4 no `deploy.yml`.

> **Sprint 14 fechou com 8 entregas** (tag `v1.0-sprint14`, `0f6aefb`) — a
> **implementação do modelo de monetização fechado** (§ "Modelo de monetização —
> DECISÕES FECHADAS" + emenda M.13). Cada tipo novo de gasto/crédito virou
> migration no enum de `entry_type` do ledger append-only (princípio nº 2), nunca
> `UPDATE` de saldo. PR #130 (**invariantes M.13** — `config/monetization.php`
> como fonte canônica + `TokenCreditPolicy` dona única de teto/split/pendência/
> chat/payout, `applied_rate` congelado), PR #131 (**rewire tip/pacotes/desconto**
> para M.13 — gorjeta 80/20 por evento, desconto de compra pela config, pacotes
> achatados M.13.2), PR #132 (**chat M.13.1** — fim do chat grátis de assinante:
> todo tier paga abertura, performer +1 token fixo), PR #133 (**subscription
> grant com fila de pendência** M.13.4/M.13.8 — franquia mensal com teto
> escalonado, webhook primário + command de reconciliação), PR #134 (**payout
> mensal R$0,60/token** M.13.5/M.10 — sweep dia 1 idempotente + on-demand, só
> ganhos sacáveis), PR #135 (**conteúdo permanente com acesso por tier**
> M.13.13/M.4 — foto v1, níveis Aberto/Premium/Exclusivo/FC Only, desbloqueio
> permanente, split 80/20), PR #136 (**fix de copy dos founding members** —
> gênero-neutro, position counter removido), PR #137 (**catálogo de presentes
> virtuais** M.13.6 — 6 presentes fixos da Limen múltiplos de 4, split 75/25,
> idempotência por remetente). **Deploy de staging pendente** para as entregas. A
> tag é marco de código, não de go-live (ver abaixo). **Ainda no backlog de
> monetização (foi para Sprint 15):** live pública e chamada privada (LiveKit),
> gorjeta/presente durante a live com animação.

> **Sprint 13 fechou com 5 entregas** (tag `v1.0-sprint13`, `1d63371`): PR #125
> (**Refactor de roles** — `moderador` separado de `admin`, fila dedicada
> `/moderacao/*`), PR #126 (**Evidence viewer** — visualizador da prova retida na
> fila de moderação), PR #127 (**Múltiplas localizações** — até 3 por performer,
> com migração das linhas existentes), PR #128 (**Photo permissions** — foto da
> galeria pública/privada + sistema de grant por FanAlias — § abaixo), PR #129
> (**Stories feed carousel** — a UI que consome `stories.feed` no topo do catálogo
> — § abaixo). **Também nesta janela:** o **modelo de monetização** foi fechado e
> documentado como referência canônica (commit `f6aa9a3`, § acima). **Deploy de
> staging pendente** para as 5 entregas. A tag é marco de código, não de go-live
> (ver abaixo).

> **Sprint 12 fechou com 3 entregas** (tag `v1.0-sprint12`, `f23368a`): PR #122
> (fix da ordem de posse no `deploy.sh` manual), PR #123 (**Convite via Stories** —
> `is_invite`, teto de 2 convites ativos, selo no feed do membro sem chat — §
> abaixo), PR #124 (**Salvar busca** — combinações de filtros do catálogo, cap 10,
> § abaixo). **Deploy de staging pendente:** os PRs #123 e #124 **ainda NÃO foram
> para staging** (o #122 é script manual, não muda o que roda). A tag é marco de
> código, não de go-live (ver abaixo).

> **Sprint 11 fechou com 4 entregas** (tag `v1.0-sprint11`, `11354b4`): PR #118
> (Login OTP passwordless), PR #119 (badge "Disponível para conversa"), PR #120
> (Notas privadas de membros), PR #121 (Boost pago) — §§ abaixo. **Deploy de
> staging pendente:** só o PR #118 (OTP) foi deployado; **#119, #120 e #121 ainda
> NÃO foram para staging.** A tag é marco de código, não de go-live (ver abaixo).

> **Tag é marco, nunca carimbo de go-live.** `v1.0-sprint9` (`57aab21`) fecha o
> arco Sprint 9 inteiro (9A + 9B + 9C) e é **anterior** ao PR #110 — aponta para
> um estado em que os 4 🔴 da Foto Efêmera ainda estavam abertos.
> `v1.0-sprint9.1` (`49ef728`) é o mesmo arco com eles fechados. **Nenhuma das
> duas libera nada.**
>
> Sobre os nomes: `v1.0-sprint9` **não** é "a versão sem sufixo" da
> `v1.0-sprint9a` — é o fecho do arco, e a ordem real é 9a → 9 → 9.1.
> **Não existem `v1.0-sprint9b` nem `v1.0-sprint9c`, e não é para criar:** o 9B
> não fechou como sprint, e por isso o sufixo do fix é `.1` e não `b`.

> **A Foto Efêmera do Membro está implementada, SEM BLOQUEADOR, e NÃO liberada.**
> Existe ponta a ponta (PRs #101–#104) e os **4 bloqueadores 🔴 foram fechados no
> PR #110** — denúncia, retenção da prova, audit log e `canMemberSendTo` como
> fonte única, mais os achados da revisão de segurança rodada sobre ele.
> **Ligar para usuário real é decisão do PO**, e continua valendo tudo o que a
> § da feature diz sobre a natureza dela: é des-anonimização consentida, e o
> rosto é uma chave de join global que o TTL não protege.

**O Sprint 9C entregou Stories da Performer** (PRs #105–#108, § abaixo) e começou
pelos 🔴, como mandava a regra: os **7 bloqueadores** da pré-análise
(`SECURITY_ISSUES.md`, § 2.1–2.7) foram endereçados, e o **pipeline de moderação
subiu antes do primeiro upload** (denúncia + quarentena + `content_hash`).

**Histórico do que estava travado desde o Sprint 10 (hoje resolvido):**
1. ~~**O refactor de `role` NÃO foi feito**~~ — **feito no Sprint 13 (PR #125):**
   `moderador` foi separado de `admin` e a fila humana passou a ser `/moderacao/*`
   (dedicada), com o **evidence viewer** da prova retida no PR #126. Destrava o
   **Curador das FC Sessions**. **Ressalva:** trechos mais antigos deste arquivo
   ainda descrevem "moderador = admin, fila `/admin/reports`" — são históricos e
   valem até o Sprint 13; a fila viva é `/moderacao/*`.
2. ~~**Os 4 🔴 da Foto Efêmera**~~ — **fechados** no
   **PR #110** (denúncia, quarentena, audit e a extração de
   `canMemberSendTo`), reusando o caminho que o PR #108 abriu para o story. A
   feature deixou de ter bloqueador; **ligar para usuário real continua sendo
   decisão do PO**, e os 🟡 residuais estão na seção da Foto Efêmera.

> **Numeração — só existe UMA: Sprint.** O trabalho fundacional era numerado por
> "Fase", e as duas sequências colidiam (a antiga Fase 3 e o Sprint 3 são coisas
> diferentes). Os rótulos de Fase foram **removidos**: a fundação virou lista por
> nome, e "Sprint N" agora aponta para uma coisa só. Docs antigos em `docs/`
> (`fase2-auth-api.md`, `fase4-perfis-catalogo.md`, o roadmap do handoff do
> Sprint 5) ainda falam em Fase — são históricos, e "Fase N" ali **não** é
> "Sprint N".

### Entregue — fundação (anterior aos Sprints)
- Fundação do repo + ambiente (MySQL/Docker).
- Modelo de dados + segurança de base (migrations, models, TokenService, seeder).
- Autenticação + cadastro (Sanctum API, register/login/logout/me, email verification, password reset, role middleware, policies, audit log).
- Compra de tokens + Asaas/PIX (cliente mockável, pagamento, webhook idempotente, reconciliação agendada).
- Perfis de performer, catálogo público e sistema de follows.
- Verificação KYC de performers (webhook Didit, resubmissão, documentos criptografados).
- Gorjetas (TipService, split, ledger append-only, idempotência, rate limit 10/min).
- Frontend Inertia + Vue 3 + Tailwind v4 (design system Limen, páginas Landing/Cadastro/Login/VerifyEmail/Catálogo, gate de idade, auth por sessão, Ziggy).
- Catálogo de performers no frontend (público e autenticado).

### Entregue — Sprints
- **Sprint 1** — fechamento de servidor (ASAAS Fake em staging, `performers:backfill-avatars`, sudoers do vendor).
- **Sprint 3** — **Interesse Controlado**: performer sinaliza, membro paga 15 tokens (100% plataforma) para desbloquear. Opt-out mascarado. Ver `docs/INTEREST_SYSTEM_SPEC.md`.
- **Sprint 4** — **Chat** interest-gated em tempo real (Reverb): janela de acesso paga, soft-delete LGPD.
- **Sprint 5** — KYC Didit real (`x-api-key`, webhook v3 `X-Signature-V2`), PCI SAQ-D (`docs/PCI_SAQ_D.md`), payout com porta de saída `needs_review` (alerta + requeue), trial de 7 dias dos Founding Members, `ExpireSubscriptions` por `next_due_date`, **Piso de Anonimato + Modo Discreto + mitigação de sybil** (§ abaixo).
- **Sprint 6** — Age Verification (CPF+DOB), **FanAlias**, aceite de documentos, Panic Button, shared-IP flag, Report, Hard Delete LGPD, Ghost Mode / Read Receipts / painel de visitantes (k=3), **2FA TOTP**, geobloqueio, filtro de chat (§§ abaixo).
- **Sprint 7** — tier da performer + grant admin, KYC no onboarding web, painel admin de KYC, múltiplos mundos (`worlds`), **Git Flow obrigatório**.
- **Sprint 8** — status `banned` + sessão viva, lista negra antifraude (hash), **KYC Nível 2 do membro**, edição de `worlds`, revisão de segurança pré-Sprint 9.
- **Sprint 9A** — UX e descoberta: tags e campos da performer, interesses do membro, filtros do catálogo, badges, localização opt-in (só UF, e some com `is_live`), hCaptcha, e-mail do fundador, onboarding, camada reservada do PanicButton.
- **Sprint 9B** (SEM TAG, não fechado) — **Foto Efêmera do Membro** (§ abaixo): `ImageProcessingService`, storage cifrado, expiração, endpoints e UI de chat, GC. **Implementada, não liberada.**
- **Sprint 9C** — **Stories da Performer** (§ abaixo), tag `v1.0-sprint9`: publicação com TTL fixo de 24h e 3 níveis de visibilidade, feed e serving autenticados, ponto dourado no catálogo, e a moderação junto (denúncia, quarentena, `content_hash`, `DeletionService` nos dois sentidos).
- **Sprint 10** — descoberta e perfil, tag `v1.0-sprint10` (PRs #111–#117, deploy de staging): **Estilos de Vida** (6 faixas opt-in, sem filtro, Modo Discreto suprime, fora do painel de visitantes), **Favoritos** (bookmark privado — § abaixo), "Sobre mim" no perfil público, "visto por último" em faixa (Ghost Mode suprime a escrita), barra de progresso do perfil, **galeria de fotos** (carrossel 6, EXIF strip, pública).
- **Sprint 11** — FECHADO, tag `v1.0-sprint11` (`11354b4`), 4 entregas: **Login OTP passwordless** (§ abaixo, PR #118: código de 6 dígitos por e-mail, 5 min, uso único, 5 palpites, 3/hora; web + API, convive com o login por senha; 2FA da performer se aplica depois; `otp:purge` GC horário); **badge "Disponível para conversa"** (PR #119, `available_for_chat_at` no perfil, janela de 4h com auto-expiração na leitura); **Notas privadas da performer sobre membros** (§ abaixo, PR #120: nota por FanAlias, cifrada, o membro nunca vê); **Boost pago** (§ abaixo, PR #121: 50 tokens, 6h, ledger append-only `spend_boost`, destaca o perfil no topo do catálogo). **Deploy de staging: só o PR #118 subiu; #119–#121 pendentes.** O resto do backlog do Sprint 11 (convite via Stories, videochamada LiveKit) **não foi iniciado**.
- **Sprint 12** — FECHADO, tag `v1.0-sprint12` (`f23368a`), 3 entregas: **fix da ordem de posse no `deploy.sh` manual** (PR #122: chown de `storage/` antes do `git pull` e de `public/build/` antes do `npm run build`, espelhando a hardening que o workflow de CI já tinha); **Convite via Stories** (§ abaixo, PR #123: `is_invite` no story, teto de 2 convites ativos por performer sob leitura, selo "💌 Convite" no feed só para o seguidor SEM chat — `ChatAccessService::memberHasChatWith` como dona; sem lista de "quem recebeu"); **Salvar busca** (§ abaixo, PR #124: o membro guarda combinações de filtros do catálogo, cap 10 sob lock, allowlist derivado de `filterRules()`, privado do membro, varrido no Hard Delete). **Deploy de staging: #123 e #124 pendentes** (#122 é script manual). Não iniciados do backlog: refactor de roles, videochamada LiveKit, e a **tela de feed que consome `stories.feed`** (o endpoint existe e é testado, mas sem consumidor Vue — o selo do convite depende dela).
- **Sprint 13** — FECHADO, tag `v1.0-sprint13` (`1d63371`), 5 entregas: **Refactor de roles** (PR #125: `moderador` separado de `admin`, fila humana dedicada `/moderacao/*` sob `role:moderador`, em vez do antigo `/admin/reports` sob `role:admin` — pré-requisito da fila de moderação de verdade e do Curador das FC Sessions); **Evidence viewer** (PR #126: a fila de moderação passou a exibir a PROVA retida — bytes congelados de Story/Foto Efêmera denunciados —, fechando o achado da revisão de 30/07 "a fila não tem como VER a prova"); **Múltiplas localizações** (PR #127: até 3 por performer, com migração das linhas de UF única existentes; só UF é público, `city` segue interno — mesma regra da localização opt-in do Sprint 9A); **Photo permissions** (§ abaixo, PR #128: cada foto da galeria pode ser pública ou privada; foto privada aparece borrada no perfil e só é servida a quem tem `photo_grant` aprovado — ou à dona; o membro solicita, a performer aprova/revoga pelo FanAlias, `member_id` nunca vaza; Hard Delete nos dois sentidos); **Stories feed carousel** (§ abaixo, PR #129: a UI que consome `stories.feed` — carrossel tipo Instagram no topo do catálogo, buscado por fetch para não pagar o `canView` por story no caminho crítico; o selo do Convite via Stories do Sprint 12 finalmente tem tela). **Deploy de staging: as 5 pendentes.** Fora das PRs, nesta janela o **modelo de monetização** foi fechado e documentado (§ "Modelo de monetização — DECISÕES FECHADAS", commit `f6aa9a3`).
- **Sprint 14** — FECHADO, tag `v1.0-sprint14` (`0f6aefb`), 8 entregas — a **implementação do modelo de monetização M.13**: **PR #130** (invariantes M.13: `config/monetization.php` como fonte canônica dos números + `TokenCreditPolicy` dona única de teto por entry_type/M.13.9, fila de pendência/M.13.8, split round-half-up com `applied_rate` congelado/M.13.7, sinais de chat/gift/payout; migrations de `token_ledger.applied_rate` e `token_wallets.pending_grant_tokens`); **PR #131** (rewire de gorjeta/pacotes/desconto para M.13: `TipService` usa `policy.applyRate/creditWithSplit` 80/20 por evento e dropou `split_pct`, desconto de compra vem da config M.13.3, pacotes achatados M.13.2 no seeder); **PR #132** (chat M.13.1: fim do chat grátis de assinante — todo tier paga abertura via `policy.chatCost`, performer +1 token FIXO `chat_access_credit` never-cap, `memberHasChatWith` virou só `ChatAccess::exists`); **PR #133** (subscription grant com fila de pendência M.13.4/M.13.8: franquia mensal com teto escalonado, webhook de cobrança PRIMÁRIO + `subscriptions:grant-monthly` como rede de reconciliação, marca por-ciclo `last_grant_period_start` fecha o double-grant); **PR #134** (payout mensal R$0,60/token M.13.5/M.10: `calculatePayoutCentavos` = `tokens × 60`, sweep `payouts:process-monthly` dia 1 idempotente por (performer, ano, mês) + on-demand, **só ganhos sacáveis** via allowlist estrito, não paga banida); **PR #135** (conteúdo permanente com acesso por tier M.13.13/M.4 — § abaixo: foto v1, níveis Aberto/Premium/Exclusivo/FC Only, desbloqueio permanente via `ContentUnlock`, `ContentVisibilityService` dona única, split 80/20, denunciável, Hard Delete dois sentidos); **PR #136** (fix de copy dos founding members: gênero-neutro, position counter removido); **PR #137** (catálogo de presentes virtuais M.13.6 — § abaixo: 6 presentes fixos da Limen múltiplos de 4, `GiftService` espelha Tip/ContentUnlock, split 75/25 `applied_rate=75`, idempotência por remetente via UNIQUE composto, performer só vê FanAlias, `gift_credit` no allowlist de payout). **Deploy de staging: as 8 pendentes.** Não iniciado (foi para Sprint 15): live/chamada LiveKit, gorjeta/presente na live com animação, feed de conteúdo permanente, verificação de documento, sanitização de upload de vídeo.
- **Sprint 15** — FECHADO, tag `v1.0-sprint15` (`bf1c3dd`), 8 entregas — **vídeo em tempo real (LiveKit)**, planejado desde a fundação e finalmente implementado: **PR #138** (infra LiveKit + token service: `LiveKitService` dona única de rooms/JWTs HS256 assinados localmente, `config/livekit.php`, `config/features.php` com flags de dark launch, `feature:*` middleware, identity OPACA por live e FanAlias handle por par na chamada, room_name imprevisível nunca em URL/log/resposta, backstop interno da flag no createRoom/generateToken); **PR #139** (live pública GRÁTIS com gorjeta/presente: `LiveSession`/`LiveSessionService`, serving autorizado por sessão sem URL assinada, reconciliação na leitura da live abandonada, badge "AO VIVO" + ordenação no catálogo, gorjeta 80/20 e presente 75/25 pelas rotas existentes; sem `Crypt` de propósito — 1:N); **PR #140** (chamada privada 1:1 com cobrança por minuto: split 70/30 `applied_rate=70`, request→accept→active, heartbeat pré-pago idempotente por minuto via `minutes_billed`, `MinuteBiller` como motor único, saldo nunca negativo, exclusividade do membro sob lock-âncora, ban/kill-switch, `calls:expire-pending` + `calls:reap-stale`); **PR #141** (group show 1:X + upgrade para 1:1: 1 `call_sessions` `type=group` com `member_id` nullable + N `call_session_participants` de cobrança independente, `MinuteBiller` compartilhado com o 1:1, upgrade que vira `type=private` + revoke dos outros por job de 10s, exclusividade bidirecional 1:1↔group, `closeForMember`); **PR #142** (animação de gorjeta/presente na live: evento broadcast `LiveReaction` no canal privado `live.{slug}` disparado pós-commit pelo Tip/GiftService só durante live ativa, `<LiveOverlay>` com fila sequencial, payload não-sensível — FanAlias label, valor, tipo, nunca member_id/saldo); **PR #143** (preview animado no catálogo: frame JPEG por sessão capturado do canvas do `<LiveRoom>` a cada 10s, validação sem decode server-side, disco privado `live_previews` fora do backup, serving por ServesPhotoBytes autenticado, delete no fim da live + `live-previews:purge`); **PR #144** (toast global estilo Seeking: `<MessageToast>` no AppLayout escuta `user.{id}`, `NewMessage` ganhou `sender_name`/`sender_avatar_url` mascarados por destinatário — FanAlias à performer, stage_name+avatar ao membro —, nunca o corpo, máx. 3 empilhados, auto-dismiss 8s); **PR #145** (**"Em breve"** em produção: flags `features.live_enabled`/`features.call_enabled` compartilhadas como props Inertia globais, `<ComingSoon>`, badge/hover do card gateados na flag, varredura de teste garantindo que TODA rota de live/call/group carrega `feature:*`). **Resolução do § 2.5:** o serving sem cifra em memória que travou as FC Sessions **deixou de existir por arquitetura** — não há serving HTTP de bytes de vídeo; o LiveKit SFU faz o relay via WebRTC (DTLS-SRTP), o backend só emite tokens JWT e controla permissão. **Deploy de staging pendente**; sobe com as flags **off** (liberação é jurídica, muda só o `.env`). Não iniciado (foi para Sprint 16): feed de conteúdo permanente, sanitização de upload de vídeo, verificação de documento, animações elaboradas de presente, preview via WebRTC real, som de notificação.
- **Sprint 16** — FECHADO, `main` em `37d8cec`, **sem tag** (16 PRs, #151–#166) — duas frentes: **redesign maison** do front (design system `limen-*`) e **superfícies novas de produto**. **PR #151** (fix: PanicButton estava invisível para o membro em certas telas); **PR #152** (card de catálogo v2 nos tokens `limen-*`); **PR #153** (landing redesenhada); **PR #154** (grid v2 + trilha "Agora" agregando lives/stories no topo + slot de Destaque do Boost); **PR #155** (PanicButton vira **link de texto no header** — o disco flutuante sozinho era lido como "fechar" e o membro não achava a saída; achado do UAT — § "PanicButton"); **PR #156** (**crop interativo** de avatar 1:1 e capa 3:1 com cropperjs; o avatar passou a ser higienizado pelo `ImageProcessingService` como o resto); **PR #157** (fix de ruído de log: reconciliação pula pagamentos `pay_fake_` que davam 404 no Asaas, e `LivePreviewService::purgeOrphans` protege contra disco ausente); **PR #158** (perfil da performer redesenhado na estética maison); **PR #159** (**feed do membro** `/feed` — consome o conteúdo permanente do PR #135; `FeedController`, desbloqueio/serving reusam `content.*`); **PR #160** (**dashboard admin de receita** `/admin/dashboard` — `AdminMetricsService`, agregados do ledger + contadores + payouts em `needs_review`, `role:admin` (moderador não vê receita), **zero PII de membro**); **PR #161** (**anti-CSAM MVP** — `CsamScanService`/`PerceptualHashService`, dHash em toda imagem no upload nos 6 caminhos, § abaixo); **PR #162** (preview WebRTC real no hover do card, v2 do snapshot JPEG); **PR #163** (animações de presente por partícula/sprite no `<LiveOverlay>`, v2 da CSS simples); **PR #164** (**receita real**: o dashboard soma `payments` confirmados por `confirmed_at`, não estimativa do ledger); **PR #165** (**catálogo de membros para a performer** — `MemberCatalogService`, Interesse Controlado INVERTIDO, § abaixo); **PR #166** (**som de notificação + preferências** — `users.notification_preferences` JSON, § abaixo). **Mergeados após o fecho inicial (PRs #167/#168, `main` em `55de8cd`):** sanitização de upload de vídeo (PR #167 — `performer_content.kind` ganhou `'video'` + coluna `status`; ffmpeg re-encode H.264/AAC) e o selo de curadoria (PR #168). **Não iniciados:** verificação de documento (Didit), hCaptcha em produção, pin PHP 8.5→8.4 no `deploy.yml`. Deploy de staging pendente para tudo.
- Fora da trilha numerada: **Waitlist** (double opt-in, drip, painel admin) e **Círculos** (assinaturas por tier — Fase A Explorador→Prestige, Fase B Black/FC).

> **Sprint 2 não tem registro** nos docs; a numeração pula de 1 para 3 de propósito.
> Não é lacuna de documentação a preencher — é como o histórico ficou.

> **Sprint 13 (registrado como backlog) foi ENTREGUE** — o refactor de roles, o
> evidence viewer, as múltiplas localizações, as permissões de foto e o feed UI
> viraram os PRs #125–#129 (ver a lista de Sprints acima). O que ficou de fora
> daquele backlog e ainda vale carregou para o Sprint 14.

> **Sprint 14 (registrado como backlog) foi ENTREGUE** — a implementação do
> modelo de monetização M.13 (invariantes, rewire de tip/pacotes/chat, grant com
> pendência, payout mensal, conteúdo permanente, presentes) virou os PRs #130–#137
> (ver a lista de Sprints acima). O que ficou de fora daquele backlog — tudo que
> depende de LiveKit (live, chamada, gorjeta/presente na live) mais a verificação
> de documento e a sanitização de vídeo — carregou para o Sprint 15 abaixo.

### Sprint 15 — FECHADO
Todo o bloco de **vídeo em tempo real (LiveKit)** — live pública, chamada 1:1,
group show 1:X, gorjeta/presente na live com animação — foi entregue nos PRs
#138–#145 (ver "Entregue — Sprints" acima e o § "Estado atual"). O **§ 2.5 está
resolvido** (não há serving HTTP de bytes de vídeo; o LiveKit SFU faz o relay via
WebRTC/DTLS-SRTP e o backend só emite tokens JWT + controla permissão). O que ficou
de fora daquele backlog carregou para o Sprint 16 abaixo.

### Sprint 16 — FECHADO (PRs #151–#166 + #167/#168, `main` em `55de8cd`, sem tag)
O que era backlog do Sprint 16 foi entregue. Os dois itens que fecharam em branch
não-mergeada (vídeo e selo de curadoria) foram mergeados APÓS o fecho inicial —
PRs #167 (`feat/video-sanitization`) e #168 (`feat/profile-curation-seal`), `main`
em `55de8cd`. Estado item a item:

- ✅ **Feed/timeline de conteúdo permanente** — entregue (PR #159): rota `/feed`
  (`FeedController`, `throttle:60,1`, grupo de membro verificado), consome o backend
  do PR #135; desbloqueio/serving reusam `content.*`.
- ✅ **Sanitização de upload de vídeo** — **entregue (PR #167, `main` em `55de8cd`).**
  Pipeline ffmpeg: job assíncrono `ProcessVideoContent` re-encoda para H.264/AAC a
  partir dos streams DECODIFICADOS (nunca stream copy), mapeia só o 1º vídeo + 1º
  áudio e derruba data/subtitle/attachment (`-dn -sn`) e toda metadata
  (`-map_metadata -1`, mata GPS/device); thumbnail auto ~1s (640px JPEG, fallback
  frame-0). Limites 500MB (Form Request) e 10min (ffprobe no upload → 422); não-vídeo
  → 422. Upload volta na hora com `status=processing`; só é servível em `status=ready`
  (o `ContentVisibilityService` e o feed exigem `ready`, então processing/failed
  nunca é visto/listado/desbloqueável — nem pela dona). ffmpeg ausente → fail-closed.
  `performer_content.kind` ganhou `'video'` + coluna `status` (migration
  `2026_08_10_000001`); `content.video` faz streaming (BinaryFile, Range/seek,
  `video/mp4`+nosniff). GC `content:purge-orphan-raw` (horário). Foto segue no
  pipeline GD síncrono. (NÃO se aplica ao vídeo ao vivo do LiveKit, que é relay
  WebRTC, não upload — § 2.5.)
- ❌ **Verificação de documento como produto** (R$ 9,90) — **não iniciada.** Depende
  da Didit (a mesma integração do KYC da performer).
- ✅ **Animações elaboradas de presente** — entregue (PR #163): sprites/partículas
  por presente no `<LiveOverlay>`, v2 da CSS animation simples do PR #142.
- ✅ **Preview via WebRTC real** — entregue (PR #162): stream real no hover do card,
  v2 do snapshot JPEG a cada 10s do PR #143.
- ✅ **Som de notificação + preferências** — entregue (PR #166): § abaixo,
  `users.notification_preferences` (JSON por-usuário).
- ✅ **Selo de curadoria "maison/select"** — **entregue (PR #168, `main` em `55de8cd`).**
  `<CurationSeal>` (fonte única) mostra o tier de curadoria como pílula dourada
  discreta ao lado do nome na `Catalog/Show` (membro) e na `Performers/Show` (guest),
  espelhando o card: Maison = pílula com borda, Select = pílula sutil preenchida.
  Renderiza só para tiers com selo (nada para os demais); usa a prop `performer.tier`
  já existente. **É a curadoria DA PERFORMER — nenhum tier de membro é exposto.**
- ❌ **hCaptcha habilitado em produção** — não feito; `HCAPTCHA_ENABLED` segue off.
- ❌ **Pin PHP 8.5→8.4 no `deploy.yml`** — não feito (exige token com escopo
  `workflow`; o servidor de dev não tem). Alvo de produção é 8.4.24.

Além do backlog, o Sprint 16 trouxe **superfícies novas não previstas ali**:
**catálogo de membros para a performer** (PR #165 — § abaixo), **anti-CSAM MVP**
(PR #161 — § abaixo), **dashboard admin de receita** (PRs #160/#164), e o **redesign
maison** do front com o design system `limen-*` (PRs #152–#158 — § "Convenções").

> **O "Toast notification estilo Seeking" já foi entregue** (PR #144, Sprint 15) —
> se aparecer em lista antiga de backlog, está feito.

## Privacidade do membro — decisões locked (não rediscutir sem o PO)
Regra central do produto, não detalhe de implementação. Fonte única:
`app/Services/FollowerVisibilityService.php`. A tela de seguidores e o envio de
Interesse **têm** que consultar o mesmo serviço: se discordarem, o par 404/201 do
envio vira oráculo para reconstruir a lista que a tela esconde.

1. **Piso de Anonimato:** a performer só vê a lista a partir de 5 seguidores.
2. **Modo Discreto** (Black/FC): o membro conta para o piso mas nunca é listado.
   `discrete_mode` **NÃO** está em `$fillable` do `User` (anti mass assignment);
   a troca passa pelo endpoint dedicado, que checa o tier.
3. **Perder o tier não desativa** o Modo Discreto — quem está discreto continua
   (não reexpomos por lapso de pagamento), sempre consegue DESLIGAR, mas não
   religar sem o tier.
4. **Piso vs. faixa:** o piso conta só contas com 7+ dias **e** e-mail verificado
   (mitigação de sybil); a faixa exibida conta **todos** os ativos. Logo, "5+" com
   a lista escondida é estado **legítimo**, não bug. Os cortes valem para
   *destravar*, não para filtrar: aberta a lista, conta nova aparece nela.
5. Contagem de seguidores é sempre exibida **em faixa**, inclusive para a própria
   performer — faixar só as telas públicas deixaria a correlação de pé.

### Piso de visitantes (`profile_visits`, painel do dashboard)
O painel "visitantes recentes" é a segunda superfície que expõe membro à
performer, e o piso de seguidores sozinho não a cobre: ele libera a tela, não
limita quem aparece nela. Por isso o painel tem **dois** cortes — `canRevealList()`
(seguidores) **e** um piso de visitantes distintos.

6. **O piso de visitantes conta só elegíveis:** conta com 7+ dias, e-mail
   verificado, `role=consumer` e `status=active`. É a mesma mitigação de sybil do
   item 4, e pelo mesmo motivo: contando todo visitante distinto, a performer com
   o piso de seguidores já destravado criava 4 contas de véspera, visitava o
   próprio perfil com cada uma e o quinto alias — o único que ela não plantou —
   saía identificado por eliminação (casando o horário de cada visita própria com
   a linha correspondente). Como o `FanAlias` é estável por par, esse vínculo ia
   junto para as gorjetas e para a lista de seguidores.
   O critério tem uma dona só: `FollowerVisibilityService::applyFloorEligibility()`.
   **Não copie o número nem a regra** para outro service.
7. **Elegibilidade destrava, não filtra** (item 4 vale aqui): aberto o painel, a
   lista sai **completa** — visitante de conta nova aparece nela normalmente. Só
   o CONTADOR do piso aplica os cortes.
8. **`limit < piso` lança `LogicException`** (`ProfileVisitService::panelFor()`),
   nunca clamp silencioso. O piso é contado sobre a janela inteira e a lista sai
   cortada em `$limit`: se `$limit` for menor, o painel abre exibindo menos
   aliases do que o piso exige. É erro de chamador — nenhum request alcança isso —
   então quebra alto em teste e staging.
9. **O guard do Ghost Mode vive no Service**, em `ProfileVisitService::record()`,
   não nos controllers. São dois pontos de entrada hoje (`CatalogController` e
   `PublicCatalogController`) e ambos só delegam; a checagem no controller viraria
   duas cópias, e a terceira rota que aparecesse nasceria vazando. `record()`
   também barra Modo Discreto (item 2) e a própria performer.
   **Não existe coluna `hidden`/`ghost` em `profile_visits`:** visita de quem tem
   o perk não é gravada. A ausência de linha É o produto — guardar a visita
   marcada como oculta deixaria o dado a um JOIN de distância, e um bug de query
   viraria o vazamento exato que o perk vende.
10. **O painel usa `FanAlias::label(performer_profile_id, visitor_id)`** — nunca o
    `visitor_id`. `visitor_id` é chave interna e não sai do service.
11. **`profile_visits` são apagadas no Hard Delete** (`DeletionService::purgeProfileVisits()`),
    com `DELETE` real dentro da transação. É o mapa de interesses do titular, sem
    valor fiscal nem trilha legal — não há o que preservar. Retenção normal são
    7 dias (`visits:purge`), enquanto o painel consome 24h. As visitas RECEBIDAS
    pelo perfil saem junto quando a **performer** encerra
    (`purgeVisitsToOwnProfile()`) — as FKs `cascadeOnDelete` de `profile_visits`
    **nunca disparam**, porque os dois lados são soft-delete. Não escreva código
    contando com o cascade.
12. **Horário só em FAIXA, nunca em relógio.** O painel devolve `visited_slot`
    (Madrugada/Manhã/Tarde/Noite, faixas de 6h; só a data fora do dia corrente).
    **`visited_at` não é exposto.** Com `d/m/Y H:i`, a performer mandava o link
    para UMA pessoa às 14:31, via o alias novo carimbado 14:32 e ligava o
    pseudônimo a um nome — e o `FanAlias` é estável por par, então o vínculo ia
    junto para gorjetas e seguidores.
    A faixa é derivada de `ProfileVisitService::DISPLAY_TIMEZONE`
    (`America/Sao_Paulo`), **não** de `config('app.timezone')`, que é `UTC`:
    derivar dali rotularia 21:00 em São Paulo como "Madrugada".
13. **Ordem embaralhada dentro da faixa** (`revealableSlots()`). Sem isso a lista
    saía por recência e a POSIÇÃO entregava o que o relógio entregava. A ordem
    ENTRE faixas fica (mais recente primeiro) — essa é a informação legítima.
14. **k-anonimato por faixa: a faixa só aparece com `SLOT_MIN_K` (3) aliases.**
    Faixa incompleta some por inteiro — **sem** placeholder, contador ou "1 visita
    oculta", que reporiam o sinal que o k tira. Pela mesma razão, a copy de lista
    vazia na tela é deliberadamente ambígua ("Nada a mostrar"), e **não** afirma
    que não houve visita: distinguir "zero" de "abaixo de k" diria à performer que
    alguém passou.
    O k é filtro DENTRO da lista, **não** substituto do piso: `visible` continua
    decidido só pelos pisos, e `visible: true` com lista vazia é estado legítimo.

> **Ressalvas conhecidas — o painel de visitantes NÃO é anônimo contra um
> adversário ativo.** Registrado para não ser redescoberto como novidade:
>
> - **Polling numa faixa já visível.** O k protege a transição escondida→visível:
>   a faixa surge já com 3 aliases, e quem chegou no intervalo é um entre 3. Mas
>   uma faixa **já visível** que ganha um visitante o entrega por diferença entre
>   dois refreshes — verificado em teste: o diff devolve exatamente 1 alias novo.
>   Fechar isso exigiria só revelar a faixa depois de encerrada (release em lote),
>   o que não está implementado.
> - **A2 — eliminação com contas envelhecidas.** Os cortes do piso (7 dias +
>   e-mail verificado) são custo de setup ÚNICO, não recorrente: pagos uma vez, o
>   painel fica destravado e cada visitante real seguinte sai por eliminação
>   contra os aliases que a performer plantou. O k e a faixa encarecem; não
>   eliminam.
>
> Consequência prática: **não descreva este painel como anônimo** em copy de
> produto, política de privacidade ou auditoria. Ele reduz correlação passiva.

## Pseudônimo do membro — `FanAlias` (fechado no Sprint 6)
Toda exposição de membro à performer passa por `app/Support/FanAlias.php`:
pseudônimo derivado por par (performer_profile_id, member_id) com HMAC sobre a
`APP_KEY`. Antes, `Membro #12345` (seguidores) e `Fã #2345` (gorjetas,
`consumer_id % 10000`) correlacionavam de forma determinística — a lista de
gorjetas não passa por piso nenhum, então bastava mandar uma gorjeta.

Duas saídas, e a distinção importa:
- `for()`/`label()` → 4 dígitos, **exibição**. Colide; nunca use como chave.
- `handle()` → 16 hex, **identificação**. É o que a tela de Seguidores manda no
  lugar do `member_id` e o que volta no POST do Interesse, resolvido contra os
  seguidores listáveis do perfil. Trocar só o rótulo teria sido maquiagem: o id
  cru continuaria legível nas props do Inertia.

Nova superfície que mostre membro à performer usa `FanAlias`, não o id.
O id segue sendo a chave interna (ledger, audit log) — isto é apresentação.
Registro completo em `docs/SECURITY_ISSUES.md`.

## Favoritos do membro — Sprint 10 (bookmark PRIVADO)

**A performer NUNCA sabe que foi favoritada.** Não é preferência de UI — é o
produto, e é a única razão de a tabela `favorites` existir ao lado de `follows`.
O follow é o gesto PÚBLICO (a performer conta seguidores e, a partir do Piso de
Anonimato, vê a lista); o favorito é o gesto PRIVADO ("salvar para ver depois"),
e a assimetria entre os dois é a invariante. Dona única da regra:
`app/Services/FavoriteService.php`; o model `Favorite` carrega o mesmo cabeçalho.

Consequências, e nenhuma é opcional:
- **Nenhuma superfície do lado dela.** Sem relação inversa em `PerformerProfile`
  (`$profile->favorites` seria a porta por onde um `withCount` entraria num
  resource dela), sem coluna `favorites_count`, sem contador em lugar nenhum —
  nem em faixa. Faixa resolve k-anonimato de PERTENCIMENTO; aqui o problema é
  outro: o número em si não é dela. Um teste varre os props de todas as telas da
  performer e falha se a string `favorit` aparecer.
- **O serviço não tem, e não pode ganhar, um método que responda pelo lado
  dela** ("quem me favoritou", "quantos"). Superfície nova pergunta a este
  serviço — nunca uma segunda cópia da regra, mesma disciplina do
  `FollowerVisibilityService`: duas cópias divergem, e a divergência é o
  vazamento.
- **Nada entra em `audit_logs`.** É a única tabela que o `DeletionService`
  preserva intacta, com o IP do membro em claro ao lado: uma linha
  `favorite.added` com `subject_id = performer_profile_id` seria a cópia
  permanente do mapa de interesses que o Hard Delete apaga — o mesmo raciocínio
  que mantém o slug do Estilo de Vida fora do audit (ver `App\Support\LifestyleTier`)
  e que apaga `profile_visits` inteira.
- **O gate de "perfil no ar" mora no `FavoriteService::toggle()`, não no
  controller.** O toggle reconsulta que o perfil existe, está `active` e não está
  soft-deletado antes de gravar; um perfil fora do ar dá `ModelNotFoundException`
  (404), indistinguível de um slug que nunca existiu, para o par não virar
  oráculo de existência. O `findBySlug()` do controller segue como resolvedor do
  slug (e mantém a paridade do 404 para perfil não-verificado), mas a invariante
  de liveness deixou de depender da porta de entrada.
- **O toggle é idempotente sob duplo-submit.** `lockForUpdate` serializa os dois
  requests e o catch do `UniqueConstraintViolationException` é a segunda linha de
  defesa: a corrida perdida devolve o estado final (favoritado), nunca um 500 nem
  linha duplicada. Testado de verdade (injeção no gancho `creating`), não só no
  ramo do DELETE.
- **O Hard Delete leva os favoritos NOS DOIS SENTIDOS** — os do membro e os
  apontados para o perfil da performer. As FKs `cascadeOnDelete` **nunca
  disparam** (os dois lados são soft-delete/anonimização), então a varredura é
  explícita no `DeletionService`; sem ela a linha ficaria órfã para sempre, pois
  favorito não tem retenção que o varra depois.

## Notas da performer sobre membros — Sprint 11 (anotação PRIVADA)

Inspirada nas "Notas dos membros" do Seeking: a performer anota observações
sobre um FanAlias (detalhes de conversa, preferências) para lembrar depois. **O
membro NUNCA vê a nota** — é o oposto exato do favorito (privado do lado do
MEMBRO); aqui o privado é do lado da PERFORMER. Dona única da regra:
`app/Services/MemberNoteService.php`; o model `MemberNote` carrega o mesmo
cabeçalho. Uma nota por par `(performer_profile_id, user_id)` (UNIQUE).

- **`content` é cifrado em repouso** (cast `encrypted` sobre a APP_KEY) — é
  opinião pessoal sobre alguém, PII sensível que não pode ficar legível no banco
  nem em log. `user_id` é `$hidden`; FKs fora do `$fillable` (a linha só nasce no
  serviço, nunca de array de request — mesma disciplina de `favorites` e do 2FA).
- **O id do membro nunca chega ao front:** a tela e o payload usam
  `FanAlias::handle`/`label`, e o PUT/DELETE recebem o **handle** (16 hex) na
  rota, não o id. Quem resolve `member_handle` → membro é o trait
  `ResolvesMemberNoteTarget`, contra os **seguidores listáveis E os visitantes
  reveláveis** — o padrão do Interesse, unido porque a nota alcança qualquer
  membro que a performer vê por qualquer das duas telas. **Abaixo do Piso de
  Anonimato nada resolve** (a lista está escondida), e todo modo de falha é o
  **mesmo 404** — distinguir "não existe" de "escondido" viraria oráculo.
- **Handle é por par:** a nota de outra performer sobre o mesmo membro é uma
  linha SEPARADA, e o handle de um perfil **não resolve** no outro (HMAC por
  par). Não há como uma performer tocar a nota de outra — 404, não 403.
- **O upsert é idempotente sob duplo-submit** (`lockForUpdate` + catch do
  `UniqueConstraintViolationException`), como o toggle do favorito. O DELETE é
  idempotente (apagar o que não existe é no-op).
- **Nada entra em `audit_logs`, e a nota NÃO aparece em denúncia/`Report`** —
  seria a cópia permanente do dossiê que o Hard Delete apaga, na única tabela que
  o `DeletionService` preserva com o IP em claro. Mesmo raciocínio do favorito e
  do slug do Estilo de Vida.
- **O Hard Delete leva a nota NOS DOIS SENTIDOS** — as escritas SOBRE o membro
  (`purgeMemberNotes`, por `user_id`) e as escritas PELA performer
  (`purgeMemberNotesByPerformer`, por `performer_profile_id`). As FKs
  `cascadeOnDelete` **nunca disparam** (os dois lados são
  soft-delete/anonimização), e não há retenção que varra depois — sem as duas
  varreduras a linha ficaria órfã para sempre (item 11, mesma armadilha de
  `favorites`/`profile_visits`).

> **Ressalva de segurança — a nota adensa o dossiê por FanAlias.** O `FanAlias` é
> estável por par, e a nota cola no MESMO pseudônimo que já carrega as gorjetas e
> a linha de seguidor. Somando **lifestyle_tier + nota + gorjetas + horário de
> visita em faixa**, a performer monta um perfil persistente do "Membro #0042 de
> sempre". É correlação DENTRO de um perfil (o alias não cruza perfis, por
> construção do HMAC — ver `FanAlias`), e é o preço de dar à performer memória
> sobre o membro: decisão de produto, registrada para não ser redescoberta como
> novidade. Não muda nada do isolamento ENTRE perfis; adensa o de dentro.

## Boost pago — Sprint 11 (destaque no catálogo por tokens)

Monetização INDIRETA: a performer gasta **tokens** (não dinheiro) para o perfil
aparecer no topo do catálogo por uma janela curta. A receita vem de ela precisar
**comprar** tokens (PIX) para ter o que gastar — o boost não credita ninguém, é
100% plataforma, como o desbloqueio de Interesse. Dona única da regra:
`app/Services/BoostService.php`; política em `config/boost.php` (custo 50,
duração 6h, teto de 20 destaques simultâneos — todos por env).

- **Estado DERIVADO na leitura, um carimbo só.** `boosted_until` guarda o FIM do
  destaque; `PerformerProfile::isBoosted()` é `boosted_until` não-nulo E no
  futuro. Sem job de expiração — vence sozinho na leitura, como `is_live` e o
  `available_for_chat_at`. Guardar o fim (não início+duração) mantém a leitura
  trivial e imuniza um boost ativo contra uma mudança de `BOOST_DURATION_HOURS`.
- **`boosted_until` é `$hidden` E fora do `$fillable`.** O público vê só o
  booleano `is_boosted` (PerformerPublicResource); expor o carimbo diria a hora
  exata do fim (e, com o config, do início) — presença ao minuto, a mesma
  disciplina de `available_for_chat_at`. A escrita só acontece no BoostService,
  por `forceFill`, DEPOIS do débito.
- **Ledger append-only (princípio nº 2).** O débito é uma linha nova
  `spend_boost` via `TokenService::debit` — NUNCA UPDATE de saldo. Migration
  própria abre o valor no enum de `entry_type`. `balance_after` calculado na
  hora; saldo insuficiente (`InsufficientBalanceException`) é lançado ANTES de
  carimbar, então nunca há destaque sem pagamento.
- **A ordem das guardas do `boost()`, tudo numa transação que começa travando o
  perfil (`lockForUpdate`):** (1) elegível? — verificada + conta ativa, o recorte
  do catálogo, reconferido pela chave; (2) já boostada? → rejeita, NÃO empilha;
  (3) tem vaga? → teto global; (4) debita; (5) carimba. O lock do perfil + o "já
  boostada?" são o que tornam o duplo-submit seguro: o segundo request espera,
  relê "já boostada" e recusa antes de debitar — sem débito dobrado nem destaque
  esticado.
- **O teto de slots é SOFT-cap sob concorrência.** O lock do perfil serializa
  boosts do MESMO perfil, não de perfis diferentes, então dois boosts simultâneos
  de perfis distintos podem passar juntos e chegar a `max+1`. É limite de
  NEGÓCIO, não de dinheiro/segurança — o débito e o "não empilha" seguem exatos.
  Fechar de vez exigiria um lock global, caro para o que o teto vale. Registrado
  para não ser lido como bug.
- **A ordenação "boostados primeiro" mora em `scopePublicCatalog`**, como PRIMEIRA
  cláusula (`ORDER BY CASE WHEN boosted_until > now() THEN boosted_until END
  DESC`): boostados no topo, ordenados entre si pelo destaque mais recente; boost
  VENCIDO e nunca-boostado caem em NULL (por último em DESC) e a ordenação normal
  do serviço (seguidores/rating), acrescentada DEPOIS, decide o desempate. **Sem
  boost ativo, todos caem em NULL (empate) e a ordem existente governa tudo** —
  por isso a mudança não mexe em quem não usou o boost.
  - **Consequência deliberada: `scopePublicCatalog` é compartilhado.** A mesma
    ordenação vale para toda superfície de card público que passa pelo escopo —
    catálogo, lista de Favoritos, prévia de "Seguindo" do painel do membro. Boost
    é visibilidade em TODA vitrine pública, não só na busca; é intencional, não
    um vazamento do escopo. (Não afeta lookups por slug: uma linha só, ordem
    irrelevante.)
- **Hard Delete zera `boosted_until`** (mesma natureza de `available_for_chat_at`
  — presença sem valor fiscal/legal). O débito que pagou o boost FICA no ledger
  (append-only, lastro fiscal), só desvinculado como o resto.
- **UI:** badge dourado discreto "⚡ Destaque" + borda dourada sutil no card (só
  se boostado); seção "Destaque" no dashboard com três estados (boostada →
  faixa de tempo; pode boostar → botão + custo + "N de 20 vagas"; sem tokens →
  aviso + link de comprar). Faixa de tempo, nunca relógio, como a disponibilidade.

## Convite via Stories — Sprint 12 (isca para o chat pago)

Caminho 2 do Interesse Expandido: ao publicar um Story, a performer pode marcá-lo
como **convite**. Para os SEGUIDORES que ainda não têm chat com ela, o feed exibe
esse Story com destaque (badge "💌 Convite" + CTA para o funil pago — comprar
acesso de chat ou assinar Círculo). É monetização INDIRETA, como o Boost: o
convite não credita ninguém; ele empurra o membro para uma transação que passa
pelo ledger. Coluna `performer_stories.is_invite` (bool, default false).

- **`is_invite` é dado da PERFORMER, não do membro, e NÃO existe lista de "quem
  recebeu".** O alvo é derivado na LEITURA do feed (seguidor sem chat), nunca
  materializado — mesma disciplina da ausência de linha em `profile_visits` e
  `story_views` (§ 2.7). Uma tabela de destinatários seria o dossiê
  membro→performer que o produto recusa. O membro não sabe que foi "selecionado"
  porque **não há seleção**: o selo acende para a categoria inteira "seguidor sem
  chat", não para uma lista escolhida.
- **O selo é POR ESPECTADOR, resolvido em `StoryVisibilityService::feedFor()`.**
  `is_invite:true` só volta para quem NÃO tem chat com ela; quem já conversa vê o
  Story normal (`is_invite:false`); quem não segue não vê o Story de forma alguma.
  O feed é do próprio membro — nada aqui expõe membro à performer, então FanAlias
  não ganha superfície nova (a feature não cria exposição membro→performer).
- **"Já tem chat?" é `ChatAccessService::memberHasChatWith()` — dona única, NÃO
  `canMemberSendTo()`.** São perguntas diferentes: `canMemberSendTo` é "pode
  ENVIAR agora numa conversa ativa" (exige `Conversation`); o convite pergunta "já
  está no funil pago". A resposta é a UNIÃO de dois vínculos: assinatura de Círculo
  ativa (chat livre — não é alvo) OU qualquer linha de `chat_access` do par (já
  comprou algum dia). "sem ChatAccess, sem Subscription" da spec, literal.
  Reescrever isso no serviço de story seria a segunda cópia que diverge.
- **Teto de 2 convites ATIVOS por performer** (`PerformerStoryService::MAX_ACTIVE_INVITES`),
  não "2 por dia de calendário": como o TTL é fixo em 24h, "2 ativos" ≈ "2 por
  dia" e evita o oráculo de fuso (UTC no banco, SP na exibição). A vaga se libera
  na LEITURA quando o convite vence (escopo `active()`), sem job — § 2.8. A guarda
  roda ANTES do Store (não desperdiça o re-encode num request recusado) e lança
  `StoryException::INVITE_LIMIT` → 422. É **soft-cap sob concorrência**, como o
  Boost: dois publishes simultâneos podem chegar a 3. Limite de NEGÓCIO, não de
  dinheiro; a rota já leva `throttle:10,1`. O teto NÃO barra Story normal.
- **`is_invite` fica FORA do `$fillable`** (nasce só no `publish()`, como bool já
  validado — disciplina de `discrete_mode`/2FA), apesar de ser escolha da
  performer como o nível. Entra no audit `story.published` (dado dela sobre a
  própria publicação, ao lado do nível); **nada de membro, nada de "quem recebeu".**
- **UI:** checkbox "Enviar como convite para novos seguidores" no `StoryPanel`,
  com contador "N/2 convites hoje" derivado dos próprios cards (cada Story traz
  `is_invite`) — sem segunda fonte para divergir. O selo/CTA no feed do membro
  depende de uma tela de feed que **ainda não existe** (o endpoint `stories.feed`
  é testado mas sem consumidor Vue hoje); o contrato — `is_invite` por item no
  payload — está entregue e testado.

## Buscas salvas do membro — Sprint 12 (combinações de filtros do catálogo)

O membro salva combinações de filtros do catálogo ("Fitness SP") para reaplicar
depois. **Decisão do PO (R3 do Sprint 9): quem salva filtros é o MEMBRO, nunca a
performer** — a direção segura, a mesma do `CatalogFilterRequest` (membro filtra
performers; o inverso não existe e não deve passar a existir). Dona única:
`app/Services/SavedSearchService.php`; model `SavedSearch` (`MAX_SAVED = 10`).

- **É privado do membro, e a performer não tem lado nenhum** — sem rota irmã, sem
  relação inversa, nada em `audit_logs`. Mesma assimetria de `Favorite`. Os
  `filters` são o allowlist do catálogo (slugs de tag, enums de bebida/fumo, faixa
  de altura, UF, nível, ordenação) mais o texto que o PRÓPRIO membro digitou:
  nenhuma PII de terceiro, nada que volte para superfície da performer.
- **O allowlist de filtros tem uma dona só:** `SavedSearchService::allowedFilterKeys()`
  deriva de `PerformerCatalogService::filterRules()` (a fonte única das facetas),
  nunca uma lista à mão. O Form Request valida o TIPO de cada faceta conhecida
  (reusando `filterRules()` com o prefixo `filters.`); o service faz `Arr::only`
  antes de gravar, então **chave desconhecida é descartada** e o JSON nunca vira
  blob arbitrário. Faceta nova no catálogo passa a ser salvável sem tocar aqui.
- **O teto de 10 é DURO, imposto sob lock** (`save()` trava a linha do `users`,
  que sempre existe, e reconta) — diferente do soft-cap do Boost, porque aqui há
  uma linha-âncora natural para serializar os saves concorrentes do membro. Excede
  → `SavedSearchException::LIMIT` → 422.
- **Busca de outro membro → 404** (não 403): o escopo por `user_id` no
  `deleteFor`/controller faz o erro cair do lado seguro, indistinguível de
  inexistente, para o par de respostas não virar oráculo. Gate: `auth` + `2fa` +
  `role:consumer` + `member.verified` (grupo da área de membro).
- **Hard Delete varre `saved_searches`** (`DeletionService::purgeSavedSearches`,
  DELETE real): a FK `cascadeOnDelete` NÃO dispara porque `anonymizeUser()` só
  soft-deleta o `users` (item 11 do CLAUDE.md) — mesma família de `favorites` e
  `otp_codes`. Só do lado do membro (não há "recebidas").
- **UI:** o `FilterPanel` do catálogo ganha, **só no catálogo do membro**
  (`canSave` — o público `/performers` não recebe), o botão "💾 Salvar busca"
  (quando há faceta ativa) com modal de nome, e um dropdown "Buscas salvas" que
  aplica ao clicar e apaga por item. A lista chega como prop do `CatalogController`
  (mesma fonte do endpoint `saved-searches.index`), recarregada após salvar/apagar.

## Filtro de cidade consentido — `feat/city-autocomplete-filter` (item 4 da fila, PR #177, mergeado na `main`)

**Item 4 da fila de melhorias.** Autocomplete de município no filtro do catálogo de
performers (estilo Seeking): o membro digita, escolhe uma cidade do IBGE e filtra.
**O item pedia "os dois catálogos"; o PO decidиu (Opção 2) por um filtro CONSENTIDO
só de performers** — porque a cidade da performer é dado INTERNO travado (§ da
localização: só UF é público, `city` nunca é exibida, `PerformerLocationTest`), e o
catálogo de MEMBROS não expõe cidade nenhuma (`MemberCatalogService::mask` = FanAlias
+ faixa de atividade). Então: **filtro de cidade só no catálogo de performers, e só
alcança quem OPTOU por ser encontrável por cidade.**

- **Base do IBGE SELF-HOSTED, zero asset externo.** Os ~5.570 municípios (nome + UF)
  vivem em **`public/data/ibge-municipios.json`** (dado público oficial, commitado). O
  front busca por **fetch RELATIVO** (`/data/ibge-municipios.json`, client-side,
  memoizado — não bate no servidor a cada tecla), o backend lê o MESMO arquivo por
  **`App\Support\BrazilianCities`**. Uma fonte só, sem cópia para divergir.
  `ExternalAssetPolicyTest` verde (o pattern dele só casa `//host`/`http://`, não `/data/…`).
- **Opt-in `performer_profiles.findable_by_city` (default OFF) é o PORTÃO.** A cidade
  já digitada pela performer só passa a FILTRAR a busca se ela ligar o toggle — e
  **continua não sendo EXIBIDA em lugar nenhum** (nem card, nem API, nem Show; só a
  tela em que ela mesma edita). Quem não opta se comporta EXATAMENTE como hoje: cidade
  100% interna, invisível ao filtro. Fora do `$fillable` + `$hidden`, escrito só por
  `forceFill` no endpoint dedicado (disciplina de `discrete_mode`/`available_for_chat_at`).
  Toggle: `PATCH /performer/encontravel-por-cidade` (`FindableByCityController`,
  `role:performer` + `throttle:10,1`, `FailsValidationAsJson` para 422 JSON), UI no
  editor de localização com copy de consentimento explícita.
- **`scopeInCity` é a dona única do filtro, e o consentimento é a 1ª cláusula:**
  `where('findable_by_city', true)->whereHas('locations', city_normalized=... [+ state])`.
  **SEM fallback para o cache `state`/`city` do perfil** (ao contrário do `scopeInState`):
  findability por cidade exige linha em `performer_locations` E opt-in. Em
  `applyFilters` a cidade tem PRECEDÊNCIA e **não empilha `scopeInState`** — não há
  caminho por `state` que traga um não-consentidor.
- **`performer_locations.city_normalized`** é a chave de busca acento-insensível
  (mantida pelo `PerformerLocationService::setLocations`, backfill na migration, fora
  do `$fillable`). A UF vem junto do município escolhido e **desambigua homônimos**
  (232 nomes se repetem entre UFs). Normalização PHP (`Str::ascii`) e JS (NFD+strip)
  espelhadas — mudou de um lado, muda do outro (`BrazilianCities::normalize` ↔
  `normalizeCity` em `resources/js/lib/brazilianCities.js`).
- **Sem oráculo novo:** o "match/no-match" para um município só revela quem CONSENTIU
  ser encontrado por cidade — o produto que ela escolheu. A cidade não volta no
  payload. **Hard Delete** leva `performer_locations` (com `city_normalized`) junto
  (`purgePerformerLocations`, DELETE real). Revisão de segurança rodada, **sem 🔴/🟡**.
- **UI:** `<CityAutocomplete>` (client-side, prefixo+contém, acento-insensível, ≤10
  sugestões, escopo por UF quando há) montado no `FilterPanel` (as duas portas do
  catálogo de performers) e no editor de localização (para a cidade gravada ser
  canônica). O catálogo de MEMBROS (`Members.vue`) **NÃO** recebe filtro de cidade.

## Catálogo de membros para a performer — Sprint 16 (PR #165, superfície INVERTIDA)

Primeira vitrine **membro→performer** do projeto: a performer navega um catálogo de
MEMBROS e sinaliza interesse (Interesse Controlado **invertido** — ela sinaliza, o
membro decide se paga para abrir o chat; `performer_interests.source='catalog'`,
enum ampliado por migration). É a direção OPOSTA de todo o resto (membro descobre
performer), então herda as invariantes de privacidade do membro. Dona única:
`app/Services/MemberCatalogService.php` — a MESMA query alimenta a lista E a
resolução do handle no envio de interesse (se divergissem, o par 404/201 viraria
oráculo para reconstruir quem a lista esconde — disciplina do
`FollowerVisibilityService`).

- **`visible_to_performers` é TRI-STATE (LOCKED, decisão do PO).** Coluna nullable,
  **sem default no banco**. O efetivo é resolvido em `User::isVisibleToPerformers()`:
  valor explícito (`true`/`false`) manda; **`null` = padrão POR TIER — Black/FC
  ocultos, demais visíveis.** O `MemberCatalogService` espelha isso em SQL (evita
  N+1). **Backfill conservador:** contas existentes viraram `true` explícito
  (visíveis, podem desligar) EXCETO Black/FC ativo, que ficou `null` de propósito
  (cai no oculto — não reexpõe quem paga pela discrição). Conta nova nasce `null`.
- **Só FanAlias, tier invisível (M.13.10).** A performer vê `label` (4 díg.) +
  `handle` (16 hex) + faixa de atividade (`ActivitySlot`) e **nada mais** — nunca
  nome, e-mail, tier/Círculo, saldo ou id. O catálogo NÃO revela "assinante".
- **Modo Discreto exclui do catálogo** (override da visibilidade — invisível às
  performers mesmo com `visible_to_performers=true`, é o perk Black/FC). **Status
  Invisível não exclui, mas suprime a faixa de atividade** (presença não exposta).
- **Só membro verificado e ativo** (`role=consumer`, `status=active`,
  `email_verified_at` não-nulo) — throwaway não é exposto à performer.

## Catálogo de membros como HOME + motor de engajamento — `feat/member-catalog-home-engagement` (PR #173, mergeado na `main`)

Evolução do PR #165. **O catálogo de membros virou a HOME da performer** (antes era o
painel): ao logar, a performer ativa cai em `performer.members`
(`RedirectsToHome::homeRouteFor`), e o **logo do header leva a essa tela** (AppLayout
`homeRoute` — simétrico ao catálogo do membro). O painel segue acessível por "Meu
Painel"; o link "Membros" saiu da nav (volta pelo logo). A tela foi redesenhada na
estética maison, espelhando `Catalog/Index.vue` (grid full-width 2/3/4, card retrato
3:4 full-bleed via `<MemberCard>`, tokens `limen-*`, FanAlias + faixa de atividade na
barra). **NÃO regride a privacidade do #165** — a máscara do `MemberCatalogService`
segue igual (FanAlias sempre, tier NUNCA, Black/FC ocultos por padrão).

Duas ações grátis por card — o **motor de engajamento** —, e as duas são o OPOSTO do
Interesse Controlado pago/anônimo (que continua nas superfícies de
seguidores/visitantes; no catálogo o coração o substitui):

- **[CORAÇÃO] Interesse grátis e ilimitado.** Tabela DEDICADA `performer_hearts`
  (UNIQUE por par, idempotente), `PerformerHeartService` dona única — **não** é
  overload de `performer_interests` (semântica oposta: grátis, ilimitado, sempre
  visível; misturar enroscaria a máquina de suppressed/opt-out/desbloqueio-pago). O
  membro vê **quem o curtiu, COM a identidade da performer** (não anônima — é o
  produto do coração), na tela `/interessadas` (`consumer.hearts.index`), **sem pagar
  nada**. Não move tokens (fora do ledger); o único freio é o throttle da rota. O que
  protege o membro não é cota aqui — é o catálogo, que já só expõe quem optou por
  aparecer, então a performer só curte quem já via.
- **[MENSAGEM PERSONALIZADA] texto livre, franquia diária grátis.** A performer tem
  uma franquia diária de mensagens grátis **por performer** (não por par;
  `config/member_engagement.php` → `free_messages_per_day`, hoje 15; contador em
  `performer_message_quotas`, uma linha por (performer, dia), reset implícito).
  `ChatService::sendCatalogMessage` cria a Conversation + 1ª Message (é a PRIMEIRA
  superfície em que a performer inicia o canal — o Interesse abre só no unlock do
  membro). O membro vê **QUE recebeu e DE QUEM**, mas o **CORPO fica bloqueado** até
  ele **abrir o chat pago** (`ChatAccessService`, a MESMA economia M.13.1: 1-2 tokens
  por janela, performer recebe o crédito fixo de abertura). **A performer NÃO paga
  para enviar; só o membro paga para LER.** Franquia esgotada → 422
  `daily_message_limit` ("você usou suas N mensagens grátis de hoje").

Invariantes travadas (revisão de segurança rodada, sem 🔴):
- **Alvo pela MESMA fonte da lista.** Coração e mensagem resolvem o `member_handle`
  pelo trait `ResolvesCatalogMember` (contra `MemberCatalogService::visibleMemberIds`,
  a mesma `visibleQuery` da tela) — a lista e a ação concordam por construção, o par
  404/sucesso não vira oráculo de quem a lista esconde. Todo modo de falha é o mesmo
  404. (O antigo `SendInterestFromCatalogRequest` foi refatorado para reusar o trait —
  fim da cópia divergente.)
- **Corpo atrás do paywall.** A mensagem reusa `sendMessage`/`ChatController`, então
  herda o gate: `accessState.can_read` false → corpo não trafega, e o broadcast manda
  preview `null` a quem não pagou. O filtro de conteúdo roda ANTES de consumir a
  franquia (mensagem barrada não gasta cota).
- **Franquia à prova de corrida:** `consumeDailyMessageQuota` sob `lockForUpdate` da
  linha do perfil (1ª instrução da transação) + lock da linha do contador, UNIQUE
  (perfil, dia).
- **M.13.10 mantido:** o membro nunca vê tier; a performer nunca vê id/nome/e-mail/
  saldo do membro (só FanAlias). O coração expõe só a identidade PÚBLICA da performer
  ao membro (nome/slug/avatar por rota assinada).
- **Hard Delete varre os dois sentidos:** corações RECEBIDOS pelo membro
  (`purgePerformerHearts`) e DADOS pela performer (`purgePerformerHeartsByPerformer`)
  + o contador (`purgePerformerMessageQuotas`). FKs `restrictOnDelete` não disparam
  (soft-delete) — DELETE explícito.

## Visitas bidirecionais — `feat/bidirectional-visits` (item 5 da fila, PR #178, mergeado na `main`)

**Item 5 da fila.** O sistema de visitas era unidirecional — **membro → performer**
(`profile_visits`, `ProfileVisitService::record()`; a performer vê "visitantes
recentes" no painel, sob FanAlias/piso/k-anonimato). Esta feature adiciona o
**sentido inverso — performer → membro**: a performer abre o "perfil" de um membro
no catálogo dela (a home), a visita é registrada, e o **membro vê "Quem visitou seu
perfil"** (`/quem-me-visitou`, `consumer.visitors.index`). Dona única da regra
segue sendo o `ProfileVisitService` — **ESTENDIDO, não duplicado**. Revisão de
segurança rodada.

- **Tabela SEPARADA `member_profile_visits`** (`performer_profile_id` = quem
  visitou, `member_id` = quem foi visitado, `visited_at`), NÃO uma coluna de direção
  em `profile_visits`. Aquela tabela carrega o piso de anonimato, o k por faixa e a
  mitigação de sybil — invariantes travadas cujo shape é o do membro→performer;
  misturar o inverso ali arriscaria regredir aquele produto. O sentido antigo fica
  **100% intocado** (o `record()` e o `panelFor()` não mudaram).
- **Assimetria deliberada de privacidade (o ponto da feature).** Quem é exposto no
  sentido novo é a **PERFORMER — pública**: o membro vê a identidade REAL dela
  (nome artístico, slug, avatar por rota assinada), **sem FanAlias, sem piso, sem k,
  sem paywall** (`memberVisitorsPanelFor`). É o mesmo contrato da lista de corações
  recebidos (`PerformerHeartService::listForMember`). **Ghost Mode/Modo Discreto NÃO
  se aplicam a este sentido** — são perks que escondem o MEMBRO da performer, e aqui
  o membro não é exposto a ninguém: é o inbound DELE. Um membro Black pode ser
  visitado e vê a visita normalmente. **M.13.10 é irrelevante aqui** (não há tier de
  performer que o membro não possa ver).
- **A performer NUNCA vê PII do membro.** O registro é `POST performer.members.visit`
  (`role:performer` + `throttle:60,1` + `can('performer-active')`), resolve o alvo
  pelo trait **`ResolvesCatalogMember`** — a MESMA fonte da lista do catálogo e das
  outras ações (coração/mensagem/interesse) —, então **o par 404/sucesso não vira
  oráculo** de quem a lista esconde, e nunca se registra visita a um membro que a
  performer não podia ver. A resposta é **204 vazio**: gravou ou caiu na dedup, é a
  mesma resposta (mesma disciplina de `record()` — a página não muda conforme a
  visita foi ou não gravada).
- **Dedup de 30min** (a MESMA `DEDUPE_MINUTES` do sentido atual): reabrir o perfil
  não gera linha nova nem dá ao membro um cronômetro do tempo que a performer passou
  ali. **Guard de borda** no service: só grava visita a membro `consumer`+`active`
  (o catálogo já filtra; é a rede redundante). `member_id`/`performer_profile_id`
  vêm das entidades do request, fora do `$fillable`.
- **A lista do membro traz UMA linha por performer** (a visita mais recente,
  agrupada no banco), só de **performers de pé** (perfil verificado + conta ativa —
  visita de performer depois suspensa/em KYC some, como um coração dela sumiria),
  ordenada por recência, com o horário em **FAIXA** ("Hoje" / `d/m/Y`), nunca
  relógio. Janela de leitura limitada à retenção de 7 dias.
- **v1 SEM monetização.** A lista sai completa para todo membro (é segura — performer
  pública). Gate por tier ("monetizar quem-visitou") é decisão futura de PO, outro PR
  (registrado no backlog).
- **GC e Hard Delete nos dois sentidos.** `purgeExpired()` (`visits:purge`) foi
  estendido para varrer TAMBÉM `member_profile_visits` fora dos 7 dias. O Hard Delete
  leva as visitas **recebidas** pelo membro (`purgeMemberProfileVisits`, por
  `member_id`) E as **feitas** pela performer (`purgeMemberProfileVisitsByPerformer`,
  por `performer_profile_id`) — as FKs `cascadeOnDelete` nunca disparam (os dois
  lados são soft-delete), como em `profile_visits`.
- **UI:** o card do catálogo de membros (`<MemberCard>`) ganhou o gesto "abrir
  perfil" (corpo do card clicável; as ações coração/mensagem param a propagação),
  que abre um **modal de perfil** (só o que o card já mostra — FanAlias, atividade,
  selo Novo; a privacidade NÃO regride) e **dispara a visita** (fire-and-forget). A
  tela do membro `Consumer/Visitors/Index.vue` espelha a de corações; link "Quem me
  visitou" na nav do membro. **Não toca o catálogo de performers nem mobile.**

## Sinais de atividade nos catálogos — `feat/activity-badges` (PR #175, mergeado na `main`)

Para o site "parecer vivo" (inspirado no "New member"/contadores do Seeking), duas
superfícies novas, ambas em cima de dado que JÁ existe — **sem** rastrear presença
nova. **Item 2 da fila de melhorias.**

- **[SELO "Nova/Novo"] entrada recente (≤7 dias).** BOOLEANO derivado `is_new`,
  **nunca o timestamp** — mesma disciplina do `is_boosted`/`is_available`.
  **Dona única da janela: `app/Support/NewBadge.php`** (`WINDOW_DAYS = 7`, rolante),
  consumida pelo `PerformerPublicResource` (a partir do `created_at` do perfil — só
  performer verificada entra no catálogo, então "entrou" ≈ está na vitrine há pouco)
  e pelo `MemberCatalogService::mask` (a partir do `users.created_at`). O selo apaga
  sozinho na LEITURA ao vencer, sem job. Card: pílula dourada discreta (`limen-gold`,
  **nunca `limen-live`**), empilhada no canto superior esquerdo abaixo do sinal de
  tempo real (AO VIVO/story). O "Novo" do membro **NÃO** é suprimido por Status
  Invisível — é idade de conta, não presença; e o catálogo já ordena por id desc
  (mais novos no topo), então o selo só rotula o que a posição implica.

- **[CONTADOR DE NÃO-VISTOS na nav] bolinhas ao lado de "Mensagens" e "Interessadas".**
  **Dona única: `app/Services/NavBadgeService::for(User)`** → `{messages, hearts}`,
  exposto como prop **LAZY** `nav_counts` do `HandleInertiaRequests` (closure avaliada
  no render, DEPOIS do controller — abrir a seção que zera o watermark já devolve a
  bolinha zerada na MESMA resposta). Cada número reusa a regra que já tem dona:
  - **mensagens** (`ChatService::unreadCountFor`): não lidas = mensagem do OUTRO
    participante sem `read_at`, dos DOIS papéis. **Respeita o paywall do chat** — a
    performer sempre lê; o membro só conta conversas com `chat_access` pleno vigente
    (o `hasFullAccess()` em SQL). Sem isso a nav diria "3" e a lista de mensagens 0
    (que zera a contagem atrás do cadeado, `ChatController::index`) — o par viraria a
    contagem-atrás-do-paywall que o index recusa de propósito. É o irmão-agregado da
    contagem POR conversa do index; os dois têm que concordar. Zera POR conversa ao
    abrir (`show()` marca `read_at`) — não há "marcar tudo lido".
  - **corações** (`PerformerHeartService::unseenCountForMember`): recebidos com
    `created_at` **estritamente após** o watermark `users.hearts_seen_at` (`>`, então
    coração no mesmo segundo da visita é "já visto"). SÓ para o membro (coração é
    performer→membro; a performer não tem "recebidos"). Espelha o recorte de
    `listForMember` (performer verificada + ativa), senão a bolinha e a tela
    discordariam. Zera ao abrir `/interessadas` (`markSeenForMember` assenta o
    watermark em `now()`).
  - **`hearts_seen_at`**: `$hidden` e fora do `$fillable` (escrita só no service);
    nunca sai como timestamp, só o CONTADOR derivado. Nulo no Hard Delete
    (`anonymizeUser`), como o `last_active_at`.

- **"Online agora" (~5 min) NÃO foi implementado — decisão do PO.** Colidiria com
  invariantes LOCKED: o `ActivitySlot` cobre resolução só até "hoje" e exclui o "agora"
  de propósito (o único tempo real é `is_live`, a bolinha verde), e o catálogo de
  membros não expõe presença de membro à performer (ordena por id desc de propósito;
  o contrato `is_invisible` do `HandleInertiaRequests`). **Não reintroduzir** um
  indicador de presença ao minuto sem nova decisão de PO.

## Teaser da mensagem bloqueada — `feat/message-preview-teaser` (PR #176, mergeado na `main`)

**Item 3 da fila de melhorias.** Antes, a mensagem que a performer manda ao membro
(o motor de engajamento do #173, e todo chat pré-desbloqueio) ficava 100% bloqueada
até o membro pagar a abertura do chat (M.13.1). Bloqueio total converte menos — o
membro não tem gancho de curiosidade. Agora ele vê as **primeiras palavras** em
claro + "desbloqueie para ler"; o resto continua pago. **A economia NÃO muda** — o
gate de chat (1-2 tk, M.13.1) é o mesmo; só o preview.

- **O corte é SERVER-SIDE, e isso é a invariante crítica.** O membro que não pagou
  **nunca** recebe o corpo completo em payload nenhum. Mandar o corpo inteiro e
  borrar via CSS deixaria o texto legível no DevTools — então é o BACKEND que corta
  e só o trecho trafega. Dona única do corte: **`app/Support/MessageTeaser::for()`**;
  número de palavras em **`config/message_teaser.php`** (`words`, default 3,
  ajustável sem deploy).
- **Piso de segurança: o teaser nunca revela a mensagem inteira.** `config.words` é
  um TETO. Em mensagem curta mostra no máximo **metade** das palavras (`intdiv(total,
  2)`): 3 palavras → 1, 2 → 1, 4 → 2. Mensagem de **1 palavra** mostra só um pedaço
  dela (metade dos caracteres) + reticências — nunca a palavra inteira. Corpo
  vazio/só espaços/null → `null` (o chamador cai no cadeado sem gancho).
- **Três superfícies, uma dona:** `ChatController::index` (preview da lista),
  `ChatController::show` (campo `teaser` no banner do paywall — teaser da ÚLTIMA
  mensagem via `value('body')`, **sem vazar a CONTAGEM**, que segue paginador vazio
  atrás do paywall) e `ChatService::broadcastListUpdate` (o `preview` do broadcast →
  lista em tempo real + toast). Regra nova de teaser entra no MessageTeaser, nunca
  copiada numa superfície.
- **`NewMessage` ganhou `locked`** (bool): distingue GANCHO (teaser, membro sem
  acesso) de mensagem legível. Sem ele, o front inferia "bloqueado" de `preview ===
  null` — e agora o preview do bloqueado é o teaser (não-null). A performer (lê
  sempre) e o membro com acesso pleno recebem `locked=false` + preview completo.
- **O broadcast chaveia em `locked`, NÃO em `can_read`.** Na CARÊNCIA (grace) o
  `can_read` é true mas o corpo é retido em todo lugar (index usa `hasFullAccess` →
  teaser; show devolve `body=null`). Usar `can_read` mandaria os 60 chars do preview
  ao membro em grace — mais do que o teaser que o resto da superfície concede. Chavear
  em `locked` (false só com acesso pleno) alinha broadcast/lista/toast ao index/show.
  (Achado da revisão de segurança; coberto por teste.)
- **Front:** `Chat/Index.vue` usa `e.locked` e mostra "teaser · desbloqueie para
  ler"; `MessageToast.vue` passou a exibir o `preview` (teaser/legível, já
  paywallado no servidor) em vez do genérico "Enviou uma mensagem"; `Chat/Show.vue`
  mostra o `teaser` no banner de desbloqueio. Nenhum deles recebe o corpo — o corte
  já veio pronto do backend.

## Microinterações premium — `feat/micro-interactions` (PR #179, mergeado na `main`)

Camada **puramente visual** para o site "sentir caro" — CSS puro, **zero
biblioteca** (sem GSAP/Three.js) e **zero asset externo** (`ExternalAssetPolicyTest`
verde). Regra de ouro: a animação guia o usuário ou reforça a marca, senão não
existe. Não toca lógica de negócio, ledger, privacidade nem mobile-layout — só
adiciona classes. **Dona única das regras: `resources/css/micro-interactions.css`**
(importada por `app.css`), classes prefixadas `mi-*` para não colidir com Tailwind
nem com os tokens `limen-*`.

- **GPU-only:** anima só `transform`/`opacity`/`box-shadow` — NUNCA
  `width/height/top/left` (forçam layout, engasgam no mobile). Sombras são preto/
  dourado TRANSLÚCIDO (o token `limen-gold` com alfa), **não cor nova**.
- **`prefers-reduced-motion: reduce` desliga TUDO** num bloco único no fim da
  folha — inclui os utilitários `animate-spin/pulse/ping` do Tailwind, que o
  framework não desativa sozinho. Travado por `MicroInteractionsTest` (existência
  da folha, guard de reduced-motion, ausência de `url()`, import no `app.css`).
- **Cards do catálogo (`.mi-card` em PerformerCard/PublicPerformerCard/MemberCard):**
  hover `translateY(-4px)` + sombra que cresce (~200ms). **Só em `(hover:hover) and
  (pointer:fine)`** — no toque o card fica idêntico ao de hoje, sem `:hover` grudado.
- **Botões (`.mi-press`, `Button.vue` + FavoriteButton + ações do MemberCard):**
  micro-pulso `scale(0.98)` no `:active` (~120ms), sensação tátil (vale no tap).
  O CTA dourado (`Button` variant `primary`) ganha `.mi-glow` — lift `-1px` + brilho
  dourado no hover (só desktop). FollowButton/landing herdam por usar `Button.vue`.
- **Coração (`.mi-pop`, favoritar E interesse):** "pop" `scale 1→1.3→1` (~300ms,
  estilo Instagram) ao MARCAR (não ao desmarcar). O componente reinicia a animação
  no ícone (remove classe → reflow → readiciona) quando o prop `saved`/`hearted`
  vira `false→true` — o estado real chega depois do reload, então dispara na
  confirmação, não no clique otimista.
- **Fade de página (`.mi-page-enter` no `<main>` das duas layouts):** as layouts
  NÃO são persistentes (re-montam a cada navegação Inertia), então um `animation:
  fade-in` de 150ms no `<main>` dispara sozinho em toda troca de página — sem
  fiação de router, imune a regressão. (A barra de progresso global do Inertia em
  `app.js` já era dourada.)
- **Loading de seção (`LoadingBar.vue` / `.mi-loading-bar`):** barra fina dourada
  indeterminada em vaivém, no lugar do disco genérico. Trocou o spinner do
  `StoryViewer`; a barra de NAVEGAÇÃO segue sendo a do Inertia. O spinner compacto
  DENTRO do `Button` foi mantido de propósito (uma barra num botão de 40px é pior
  ergonomia que o disco de cor corrente).
- **Erro de formulário (`<transition name="mi-error">` no `Input.vue`):** a
  mensagem desliza de cima com fade (~150ms) em vez de piscar. Cobre os forms de
  auth que usam o `Input` compartilhado.
- **Anel "ao vivo" do `NowStrip` (item 7):** o pulso de respiração `now-live-pulse`
  (1.8s, opacity/scale sutil, já com guard de reduced-motion) **já existia** — foi
  mantido, não reintroduzido. `limen-live` segue EXCLUSIVO do estado ao vivo.

## Intro de voz da performer — `feat/voice-intro` (PR #180, mergeado na `main`)

**PRIMEIRO áudio do projeto** (greenfield — não havia nada de áudio até aqui). A
performer grava/envia um clipe curto (**≤20s**) no perfil; é isca de engajamento — o
membro ouve e fica curioso. **GRÁTIS** para qualquer um ouvir (membro OU visitante
deslogado); não move token, fora do ledger. **OPT-IN** (nunca obrigatório) com aviso
explícito de que a voz é identificável. Dona única do ciclo:
`app/Services/PerformerVoiceIntroService.php`; tabela `performer_voice_intros` (**UMA
por performer**, UNIQUE em `performer_profile_id` — regravar SUBSTITUI). Revisão de
segurança rodada.

- **MODERAÇÃO HUMANA OBRIGATÓRIA — é o controle central, não um detalhe.** O áudio
  **NÃO vai ao ar direto**. Ciclo de status: `processing` (job de ffmpeg) →
  `pending` (aguardando humano) → `approved`/`rejected` (decisão do moderador);
  `failed` é falha técnica do job (distinta da recusa humana, como o vídeo #167).
  **Só `approved` é servível** — o serving público 404 para todo o resto. **Motivo
  (decisão de PO):** áudio dribla o filtro de texto do chat — por voz a performer
  poderia negociar encontro/passar contato (**risco art. 228**). **Anti-CSAM não se
  aplica a áudio; o humano é o gate.** O job marca `pending`, **nunca `approved`
  sozinho** — não existe caminho de publicação sem aprovação humana (travado por
  teste).
- **Fila em `/moderacao/apresentacoes-de-voz`** (`moderator.access` — moderador OU
  admin, a MESMA porta da fila de denúncias). O moderador OUVE por endpoint dedicado
  throttlado (`moderacao.voice-intros.audio`, os bytes nunca entram na prop da
  página) e aprova/**recusa com motivo obrigatório** (`ModerateVoiceIntroRequest`,
  `required_if:status,rejected`). `moderated_by`/`moderated_at`/`status` por
  `forceFill` no serviço, autoridade do servidor. **Sem PII de membro** — a intro é
  conteúdo da PERFORMER (identidade pública verificada), não há membro no fluxo.
- **Sanitização por ffmpeg — `VoiceProcessingService` (SEPARADO do vídeo).** Job
  assíncrono `ProcessVoiceIntro` (`processing`): re-encode para **MP3 mono
  normalizado** a partir do stream decodificado, mapeando **só o 1º áudio**
  (`-map 0:a:0 -vn -dn -sn` — derruba vídeo/capa/data/subtitle, vetores de metadado
  num `.m4a`), **strip de TODO metadado** (`-map_metadata -1 -write_id3v2 0
  -write_id3v1 0 -fflags +bitexact` — mata ID3/GPS/device/timestamps e a tag
  "encoder"), `loudnorm`. O arquivo servido deixa de ser o enviado. **Fail-closed:**
  ffmpeg ausente → `assertAvailable` lança e o upload é RECUSADO (não se aceita áudio
  sem sanitização). Config PRÓPRIO `config/voice.php` (20s/5MB/MP3), não o de vídeo.
  Codecs conferidos no servidor (libmp3lame presente).
- **Gate de duração (≤20s) + tamanho (≤5MB) ANTES do processamento caro.** Tamanho:
  Form Request (`max`). Duração: ffprobe no upload → 422 `too_long`. Gravação de
  navegador (webm de MediaRecorder) às vezes não traz duração no header →
  `probeDurationSeconds` devolve `null` e o gate é **deferido ao job**, que reconfere
  sobre o MP3 já processado e **REJEITA (`failed too_long`), nunca trunca**. Arquivo
  não-mídia (renomeado) → `unreadable` (422).
- **Serving por disco privado `performer_voice_intros`** (`serve => false`), áudio
  **EM CLARO** sem Crypt (1:N como Story/Content), bytes só pela camada de controller
  com **Content-Type FIXO `audio/mpeg` + nosniff + no-store** (nós produzimos o MP3),
  **nunca URL assinada** (autorização por request; approval/atividade podem mudar —
  URL assinada viraria bearer token). `response()->file()` dá Range/seek. Disco sob
  `storage/app/private` (permanente, entra no backup allowlist — o OPOSTO de
  story/foto efêmera). `content_hash` (SHA-256 dos bytes processados, prova) e `path`
  são `$hidden`; `$fillable` vazio (disciplina de PerformerContent/2FA).
- **Serving público** (`voice-intro.audio`, `/performers/{profile}/apresentacao-de-voz`,
  sem auth — isca pública): só `approved` **E** performer de pé (verificada + conta
  ativa + não soft-deletada); qualquer outro estado/performer → 404. **Preview da
  dona** (`performer.voice-intro.audio`): qualquer status COM bytes (ela ouve a
  própria pendente/recusada), gate `performer-active`, só a PRÓPRIA (query por
  `performer_profile_id` dela).
- **Notificação de mudança de status por e-mail (`feat/voice-intro-polish`).** A
  performer é AVISADA quando o áudio muda de estado — sem isso a feature morria em
  silêncio (ela achava "no ar" e estava recusada). Reusa o padrão do KYC (Job
  `ShouldQueue` → `Mailable` → view blade), **não** inventa mecanismo novo. Três
  e-mails, três significados distintos: **aprovado** (`SendVoiceIntroApprovedEmail` →
  `VoiceIntroApprovedMail`, "está no ar"); **recusado** (`SendVoiceIntroRejectedEmail`,
  disparado do `reject()` do serviço, ancora a recusa nos **Termos/Contrato** +
  mostra o `reject_reason` do moderador + convida a regravar — é recusa de CONTEÚDO);
  **falha técnica** (`SendVoiceIntroFailedEmail`, disparado do `markFailed()` do JOB,
  mensagem DELIBERADAMENTE distinta — "houve um problema ao processar, tente de novo",
  **não** cita Termos, não é recusa). **Sem `afterCommit` de propósito:** `approve()`/
  `reject()` não rodam em transação (o controller chama direto) e `afterCommit` não
  dispara sob `RefreshDatabase` — mesma convenção do dispatch do upload. Disparo só na
  transição real: moderação no-op (intro não-`pending`) **não** notifica.
- **UI:** botão dourado de play (`<VoiceIntroPlayer>`) ao lado do nome em
  `Catalog/Show` e `Performers/Show` (só quando `voice_intro_url` presente = há
  aprovada — injetada pelos controllers, FORA do resource para o card não carregar a
  URL). Ícone discreto "tem áudio" no card (`has_voice_intro`, BOOLEANO derivado via
  `hasApprovedVoiceIntro`, barato no catálogo por `withCount` no `scopePublicCatalog`;
  dourado, nunca `limen-live`). Tela de gestão `Performer/VoiceIntro/Edit` (MediaRecorder
  **e** upload de arquivo, status + motivo de recusa, aviso de consentimento). O bloco
  **"Antes de gravar"** orienta (não trava): a voz é o **convite/isca** para atrair
  (despertar curiosidade), é **pública/identificável** (opt-in consciente), **não** é
  canal de contato (telefone/redes/encontro → não é aprovado), e passa por **análise**
  antes de publicar — travado por `tests/Unit/VoiceIntroGuidanceTest.php`. Fila
  `Moderacao/VoiceIntros/Index` com player. Links: "Áudios" na nav (moderador),
  "Apresentação de voz" no painel da performer.
- **Hard Delete varre a intro** (`purgePerformerVoiceIntro` por perfil + bytes em
  `collectFilePaths`) — a FK `cascadeOnDelete` NÃO dispara (perfil é soft-delete,
  item 11). **GC `voice:purge-orphan-raw`** (horário) varre os crus órfãos em `tmp/`,
  como o do vídeo.
- **Zero asset externo** (`ExternalAssetPolicyTest` verde): player em SVG/`<audio>`
  inline, sem lib. **Não toca mobile-layout nem outras features.**

## Landing cinematográfica — foco em lista de espera (`feat/landing-cinematic` #184 + `feat/landing-waitlist-focus`)

A raiz pública `/` deixou de ser o hero-maison + lista de espera (PR #153) e virou
a **PORTA do clube**: uma landing cinematográfica de 5 cenas de tela cheia com
scroll-storytelling — mistério, luxo, dourado. Impressiona quem chega por convite.
**Só a raiz pública muda; nenhuma tela interna (login, cadastro, catálogo) é tocada.**
Dona única da tela: `resources/js/Pages/Landing.vue` (reescrita); o `LandingController`
só troca o cartão social. O gate de marketing do Nginx segue valendo (o `^~ /landing/`
serve os assets — ver § "Ambiente de dev").

> **Duas entregas:** a base cinematográfica mergeou na `main` como **PR #184**
> (`feat/landing-cinematic`). A branch **`feat/landing-waitlist-focus`** (a partir da
> `main`, PR pendente) faz o ajuste de PRÉ-LANÇAMENTO: **a landing não oferece mais
> cadastro** — o único CTA vira a lista de espera. Os itens abaixo já refletem o
> estado pós-waitlist-focus; as diferenças estão marcadas.

- **As 5 cenas (scroll-storytelling):** (1) ABERTURA — vídeo `abertura.mp4` em loop
  mudo full-bleed no desktop / `porta.webp` estática no mobile, texto "Alguns portais
  não se anunciam." surgindo ~1,5s depois; (2) O PORTAL — `portal.webp`, "Cruze o
  limiar."; (3) A VERIFICAÇÃO — `digital.webp` (impressão digital) centralizada em
  fundo escuro, "Verificado. Real. Discreto." (a impressão comunica "verificado" sem
  prometer número absoluto); (4) O MISTÉRIO — `silhueta.webp` + `mascara.webp` (lado a
  lado no desktop, empilhadas no mobile), "Um clube para poucos."; (5) O CONVITE —
  `moldura.webp` (wordmark LIMEN) **full-bleed `object-cover`** (waitlist-focus: antes
  era `contain`/pequena) + tagline "O portal do desejo, verificado e real." + o **único
  CTA** "Entre na lista de espera".
- **PRÉ-LANÇAMENTO: o único CTA é a lista de espera** (waitlist-focus). O botão
  "Solicitar convite" → `/cadastro` **saiu** da cena do convite; no lugar, o botão
  dourado "Entre na lista de espera" (`scrollToForm()`) rola para a banda
  `#lista-de-espera` abaixo das cenas — o wizard de 2 passos (papel + e-mail + 18+ →
  campos por papel), nos tokens `limen-*`, postando em `route('waitlist.store')`. **O
  backend de `/cadastro` fica INTACTO — só saiu da landing (volta no lançamento);
  nenhum `route('register')` na tela** (travado por `LandingCinematicAssetsTest`). O
  waitlist e o `/convite/{code}` seguem intactos (rotas, admin, nurture, atribuição por
  sessão); a prop `referral` acende o selo "Você foi convidado por X" na cena 1 **e**
  sugere o papel no wizard. Após enviar, a tela de sucesso avisa para **conferir a caixa
  de SPAM e marcar como "não é spam"** (evita perder o aviso de lançamento).
- **Cena 2 (arco) — fade dirigido por SCROLL + arco em brilho pleno** (waitlist-focus).
  O texto "Cruze o limiar." fica no **terço inferior, ABAIXO do LIMEN da imagem** (nunca
  cobrindo-o) e sua opacidade é **ligada ao scroll**: quase invisível quando a cena
  entra pela base, plena quando ela preenche a viewport (`[data-scroll-fade]`, calculado
  no laço `requestAnimationFrame` a partir da posição da CENA, roda também no mobile).
  O arco **não é mais escurecido** por um véu central — só um gradiente na BASE
  (`scene-veil--bottom`) escurece atrás do texto; a imagem fica em destaque. Sob
  `prefers-reduced-motion` o texto fica em `opacity:1` (fallback CSS).
- **Seção da lista de espera com identidade de mármore** (waitlist-focus). Antes era
  fundo liso; agora a banda `#lista-de-espera` é `min-h-screen` com `moldura.webp`
  `object-cover` **fortemente escurecido** (`wl-bg` + véu ~0,9) atrás do card, que fica
  centralizado, com respiro e `bg-limen-surface/95`. `moldura.webp` reusa o mesmo asset
  da cena 5 (cache do browser, um fetch só).
- **Header da landing sem botões de conta no pré-lançamento** (waitlist-focus). Flag
  `features.landing_prelaunch` (env `LANDING_PRELAUNCH`, **default TRUE** — diferente
  das flags de dark launch, porque o pré-lançamento é o estado de HOJE), compartilhada
  como prop Inertia global em `features.landing_prelaunch`. A **Landing** lê a flag e a
  passa ao `GuestLayout` via `:hide-account-nav="prelaunch"`; o layout esconde **Entrar
  / Criar conta** (fica só o logo LIMEN). **Escopo é só a landing** — o `GuestLayout` é
  compartilhado por 10 telas guest (Auth/\*, Performers/\*, Entrada), e só a Landing
  passa a prop (default `false`), então as demais telas seguem com os botões. No
  lançamento, `LANDING_PRELAUNCH=false` traz os botões de volta (e junto voltará o CTA
  de cadastro) — **só o `.env` muda, sem rebuild do front**. `LandingCinematicTest`
  trava o contrato flag→prop (true por default, false quando a flag desliga).
- **Mídia 100% SELF-HOST, otimizada por ffmpeg** — invariante que mantém
  `ExternalAssetPolicyTest` verde (tudo é caminho relativo `/landing/…`, zero asset de
  terceiro). Os PNGs originais (2–7MB cada) e o MP4 (7,6MB) foram convertidos e
  **descartados** do repo; ficam só: WebP desktop (`min(1600px)`, `<400KB` cada) +
  WebP mobile (`~800px`, `*-mobile.webp`) servidos por `<picture>` com `<source
  media="(max-width:767px)">`, e `abertura.mp4` re-encodado (H.264 mudo, 1280px, CRF
  25, `+faststart`, ~0,9MB). Um teste (`tests/Unit/LandingCinematicAssetsTest.php`)
  trava a existência e o teto de peso de cada peça e falha se um PNG-fonte pesado
  voltar ao repo.
- **Performance e movimento:** vídeo de abertura **só no desktop** (`matchMedia
  '(min-width:768px) and (pointer:fine)'`); mobile e `prefers-reduced-motion` caem na
  `porta.webp` estática (o vídeo nem baixa). Imagens abaixo da dobra são `loading="lazy"`
  (a porta/poster da 1ª dobra é `fetchpriority="high"`). Reveal-on-scroll via
  `IntersectionObserver` (fade + subida, uma passada), **parallax leve** via um único
  laço `requestAnimationFrame` de scroll — ambos **desligados** sob `prefers-reduced-
  motion` (bloco único no fim do `<style scoped>` + guarda no JS). Tokens `limen-*`
  (`limen-bg`/`limen-gold`/`limen-ink`), Cormorant nos títulos; véu/gradiente escuro
  atrás de todo texto para legibilidade; `alt` descritivo, foco visível no CTA.
- **URL de asset público é BOUND, não estática** (`:src="'/landing/x.webp'"`,
  `:srcset`, `:poster`) — precedente do `PortalLogo.vue`. Atributo `src`/`srcset`
  ESTÁTICO com caminho `/landing/…` faz o `@vitejs/plugin-vue` tentar resolvê-lo como
  import de módulo e **quebra o `npm run build`**. Bind com string literal é o idioma
  do projeto para arquivo de `public/`.
- **Cartão social (`LandingController`):** `og:description` e `description` = a tagline
  "O portal do desejo, verificado e real."; `og:image` = `…/landing/moldura.webp` (o
  wordmark dourado é a prévia no WhatsApp/Google). Renderizado SERVER-SIDE pelo
  `app.blade.php` a partir da prop `meta` (Inertia SSR está off — `<Head>` do cliente é
  invisível ao scraper).

## Anti-CSAM — Sprint 16 (PR #161, MVP fail-open)

Toda imagem no upload passa por hash perceptual conferido contra uma lista local.
Dona única: `app/Services/CsamScanService.php` (usa `PerceptualHashService`, dHash).
Config `config/csam.php` (`CSAM_SCAN_ENABLED`, default **on** — é bloqueador de
go-live). **MVP: match EXATO do dHash** (indexado); near-match por Hamming e
PhotoDNA real são follow-up (a lista real depende de parceria NCMEC/IWF + CNPJ — a
seed sobe VAZIA, então "ligado com lista vazia" nunca bloqueia upload legítimo).

- **Escaneia os 6 caminhos de imagem** (bytes JÁ higienizados, ANTES do put/serving):
  `ContentStore`, `MemberPhotoStore`, `PerformerPhotoStore`, `PerformerStoryStore`,
  `PerformerProfileService` (avatar/capa) e o `PerformerContentController`.
- **MATCH → bloqueia o upload** (`CsamDetectedException`, antes de qualquer gravação),
  **`Log::critical('csam.match')`**, `Audit::log('csam.match')` e **sinaliza a conta**
  (`users.csam_flagged_at`, fora do `$fillable`, `forceFill`) para review imediato.
- **FAIL-OPEN com WARNING.** Se o hasher NÃO decodifica a imagem (serviço
  indisponível), **NÃO bloqueia** — loga `Log::warning('csam.unverified')` e registra
  `verified=false` numa linha de trilha (`content_hash_checks`). Decisão do MVP: não
  travar upload legítimo por falha do hasher; o não-verificado fica registrado para
  varredura posterior. **Toda chamada grava uma linha em `content_hash_checks`.**
- **Hard Delete PRESERVA as linhas com match** como evidência
  (`DeletionService::purgeContentHashChecks` apaga as demais, mantém a com match) —
  mesmo raciocínio da prova retida na moderação.
- **Import:** `php artisan csam:import-hashes {file.csv} {--source=}` carrega hashes
  conhecidos em `csam_hashes` (default source em `config/csam.php`).

## Som de notificação — Sprint 16 (PR #166, preferências por-usuário)

v2 do toast silencioso do PR #144: som discreto opcional + toggles. Preferências em
**`users.notification_preferences`** (JSON, nullable, **sem default no banco** — MySQL
não aceita default literal em JSON). `NULL ≡ "nunca escolheu"`, resolvido na LEITURA
por `User::notificationSoundPreferences()` como **todos ON** (o "{} default" da spec
vale por construção). Toggles por tipo (message/tip/live). Coluna fora do `$fillable`
(mesma disciplina de `discrete_mode`/2FA); a troca passa por endpoint dedicado.

## Agendamento de chamada — `feat/scheduled-call-v1` (PR #170, evolução do PR #140)

**ENTREGUE (PR #170, `main` `db007b3`).** Evolução da chamada 1:1: em vez de pedir a
chamada AGORA, o **membro agenda** performer + data/hora e o sistema **trava um
depósito** (o preço de 1 min, congelado). **Dona única: `app/Services/CallReservationService.php`**
— reserva, trava, refund, no-show e strike passam SÓ por ela (nenhuma outra classe
move o depósito de uma reserva). Controller próprio (membro e performer) + **job de
cron `reservations:process`** (a cada minuto). Config das janelas/buffer/teto em
`config/scheduled_call.php`; a economia (rate `call_noshow`=100, allowlist de payout)
segue em `config/monetization.php`. **Tudo sob a flag `FEATURE_CALL_ENABLED` — a MESMA
do PR #140 (dark launch: sobe DESLIGADO em produção; liberar é `.env`, sem deploy).**
Spec completa e decisões do PO em `docs/MASTER_HANDOFF_FINAL.md`, § "Agendamento de
chamada".

- **Primeiro mount da chamada 1:1 na UI (neste PR).** Os componentes `CallRequest` e
  `PrivateCall` do PR #140 existiam mas **nunca tinham sido montados em página
  alguma**. O PR #170 os monta pela PRIMEIRA VEZ no perfil da performer
  (`Catalog/Show.vue`): os botões **"Chamada privada"** (on-demand, aceite → token →
  `<PrivateCall>`) e **"Agendar chamada"** (depósito + fila) ficam lado a lado. A
  chamada privada on-demand só passou a ser ACESSÍVEL pela UI aqui.

- **Tabela DEDICADA `call_reservations`** (NÃO é overload de `call_sessions` — o
  ciclo de vida reserva→confirmação→janela→no-show é outro). Quando os dois entram,
  nasce uma `call_sessions type=private` (ligada por `call_session_id`) e os
  **minutos 2+ são 100% o motor do PR #140** (`call.heartbeat`/`token-refresh`/`end`,
  `MinuteBiller`, 70/30). O **1º minuto é pago pelo depósito**: crédito `call_credit`
  70/30 à performer, **sem novo débito** (o membro já pagou no `spend_call_reservation`).
- **Ledger append-only (princípio nº 2), 3 `entry_type` novos** (migration no enum,
  nunca `UPDATE` de saldo): **`spend_call_reservation`** (débito/trava do depósito),
  **`call_reservation_refund`** (refund 100% ao membro — **nunca respeita teto** M.13.9,
  **fora** do payout: é devolução, não ganho), **`call_noshow_credit`** (no-show do
  MEMBRO → depósito 100% à performer, `applied_rate=100`, **ganho sacável**).
- **Idempotência do depósito por `deposit_settled`** sob `lockForUpdate`: todo
  movimento one-time (refund / no-show / minuto-1) só ocorre com `deposit_settled=false`
  e o marca `true`. O cron (a cada minuto), duplo-submit e a corrida
  memberEnter↔cron **nunca** duplicam saldo nem refundam E creditam o mesmo depósito
  (o lock da linha serializa; quem perde relê o status e sai).
- **Ciclo:** reserva `pending` (débito+trava, sob lock do perfil p/ buffer e da linha
  do membro p/ teto de 5) → performer **confirma** (manual) ou **recusa** (refund) →
  T-5min aviso aos dois → **performer entra primeiro** (abre a sala LiveKit; a
  `call_session` ainda não existe) → membro tem **2min** para entrar → membro entra:
  nasce a `call_session`, minuto-1 pelo depósito, e a performer recebe o `call_id`
  por broadcast (`reservation.call_started`) para renovar/encerrar pelas rotas do #140.
- **Janela de confirmação/cancelamento = T-2h** (ou **T-30min** se agendado com <2h),
  o MESMO instante para "performer confirma até" e "membro cancela grátis até"
  (`CallReservation::commitmentDeadline()`). Não confirmar → cron cancela+refund
  (silêncio = devolução, garantia do membro). Cancelar depois de T-2h numa confirmada
  = no-show do membro (depósito à performer).
- **No-show:** performer confirma e não entra → refund + **strike** (`performer_profiles.noshow_strike_count`,
  fora do `$fillable`); **3 strikes → review de admin** (dashboard, `AdminMetricsService::strikeReviewPerformers`,
  **sem PII de membro**) + audit `reservation.performer_noshow_strike` (subject = a
  performer, zero linkagem de membro — disciplina do audit preservado no Hard Delete).
  A janela do membro é `max(scheduled_at, performer_entered_at)+2min` — performer
  entrando cedo NÃO força no-show do membro.
- **Slot flexível:** `performer_profiles.call_slot_minutes` (default 15, config; escrito
  pelo `CallService::updateSettings`). Se há PRÓXIMO agendamento na fila, o slot é teto
  DURO (`max_duration_minutes` da call_session) e a performer é avisada T-3min do fim
  (`reservation.slot_warning`, o membro não vê); senão a chamada é flexível (teto geral
  da performer). Buffer de 5min entre slots da mesma performer (`hasSlotConflict`,
  filtrado por janela no SQL).
- **M.13.10 / anti-oráculo:** a performer vê o membro **só por FanAlias**
  (`CallReservationPresenter`, broadcasts) — nunca id/tier/saldo/nome. Reserva de
  terceiro/inexistente/estado incompatível → o MESMO 404 (`CallReservationException::notFound`),
  autorização de dono no service sob lock (não no route-binding — armadilha do
  SubstituteBindings). `room_name` é `$hidden` e nunca sai em resposta/log/prop.
- **Hard Delete** varre `call_reservations` **nos dois sentidos** (do membro por
  `member_id`, da performer por `performer_profile_id`; a FK cascade não dispara —
  soft-delete). O ledger fica (append-only).
- **Cron `reservations:process`** (a cada minuto, `withoutOverlapping`): confirmação
  vencida, no-shows, aviso T-5min, aviso T-3min do slot. Broadcasts despachados DEPOIS
  da transação (precedente do CallService — não `afterCommit`, que não roda sob
  RefreshDatabase).
- **UI:** modal "Agendar chamada" no perfil (`ScheduleCallModal`, só membro, com preço
  visível), telas `Consumer/ScheduledCalls` (lista + cancelar + entrar) e
  `Performer/ScheduledCalls` (fila + confirmar/recusar + entrar), `<ReservationNotice>`
  no `AppLayout` (aviso não-modal T-5min + "performer entrou" com contador de 2min;
  divide o canal `user.{id}` com o `MessageToast` — `stopListening`, nunca `Echo.leave`).

## PanicButton — 3 saídas redundantes (Sprint 6, reforçado no Sprint 16)

Botão de saída rápida da sessão. O Sprint 16 (PRs #151/#155/#17344cf) fechou um
achado do UAT: o disco flutuante sozinho era lido como "fechar modal" e o membro
não achava a saída. Hoje há **três saídas redundantes**, todas disparando a mesma
ação:

1. **Link de texto no header** (pedido do PO) — montado inline no fluxo do header,
   nomeado, para o membro achar a saída sem adivinhar. Um modal pode cobri-lo.
   **Atualização (`feat/member-catalog-home-engagement`, PR #173, mergeado):** o link é
   agora **PROMINENTE e empilhado ABAIXO do nome** (nome em cima, "Panic Button"
   embaixo, numa coluna no header desktop), com **borda dourada `limen-gold`
   visível** e rótulo legível `text-limen-gold` (antes era `muted`/`border-frame`,
   discreto) — outro achado do UAT: o tom apagado escondia a saída. O hover puxa para
   `danger` e o ícone de saída fica. **Só desktop** (mobile é PR de responsividade
   separado). `PanicButtonVisibilityTest` foi atualizado para a nova prominência
   (`border-limen-gold`/`text-limen-gold`), preservando todos os invariantes de
   segurança (rótulo, aria-label, disco, duplo-Escape).
2. **Disco flutuante** teleportado para a raiz em **`z-[10001]`** — a via SEMPRE
   VISÍVEL, o fallback que o link não é. **Inalterado** (o disco mantém
   `bg-surface`/`text-[#6f6a62]`; a prominência acima é só do link inline).
3. **Duplo-Escape** (dois `Escape` em < 500ms). Um Escape sozinho não faz nada
   (senão fechar um modal viraria evasão acidental).

- **O `z-10001` continua RESERVADO ao disco** — fica UM acima do teto do projeto
  (IntroAnimation 10000, AgeGateModal 9999): nada o cobre nem engole o clique.
  **Overlay novo entra ABAIXO de 10001; não suba o disco** para acomodar outra
  camada.

## Foto Efêmera do Membro — Sprint 9B (implementada, NÃO liberada)

Foto privada que o membro manda para a performer no chat: cifrada em disco
privado, TTL de 24h/72h/7d escolhido por ele, revogável. Services:
`MemberPhotoService` (regra), `MemberPhotoStore` (bytes), `ImageProcessingService`
(ingestão — **de fato compartilhado com os Stories desde o Sprint 9C**: mudança
ali afeta as duas superfícies de upload).

**Não é feature de privacidade — é des-anonimização consentida.** O `FanAlias`
deriva o pseudônimo por PAR para que nada correlacione entre perfis, e **o rosto é
uma chave de join global**: duas performers que receberam foto do mesmo membro
comparam as imagens fora da plataforma e desfazem exatamente esse isolamento. O
TTL protege o arquivo, **não a memória nem o print**. A UI diz isso no momento do
envio, não nos Termos. **Nunca descreva como "a performer não guarda sua foto"** —
mesma disciplina do painel de visitantes e do geobloqueio.

- **A expiração vale na LEITURA; o command é só GC.** Se o único mecanismo que
  corta o acesso fosse o job apagando o arquivo, job parado não custaria disco —
  custaria privacidade. `readForPerformer()` confere os dois prazos a cada
  request. É o precedente do `ChatAccess`.
- **Tempo restante só em FAIXA** (`app/Support/ExpirySlot.php`), nunca em relógio,
  e **o TTL escolhido não é exibido**. Um countdown "expira em 71h48" com TTL de
  72h devolve o `granted_at` ao minuto — é o oráculo do `visited_at` (item 12)
  voltando por uma barra de progresso, e pior, porque a performer conhece a base
  da subtração. `ExpirySlot` tem dois consumidores e **uma dona só**.
- **O gate de compartilhar é chat ativo**, e vive em
  `MemberPhotoService::shareWith()`. `grantTo()` segue sem ele de propósito — é o
  primitivo. **Chamador novo entra por `shareWith()`.**
- **"Chat ativo" é `ChatAccessService::canMemberSendTo()` — fonte única, lida
  pelo chat E pela foto.** Ela pergunta se o membro pode ENVIAR agora
  (`can_send`), nunca "existe linha em `chat_access`": assinante de Círculo tem
  chat livre e **não gera linha** — a leitura literal recusaria quem paga mais.
  Carência não passa. A recusa é sempre `no_active_chat`, para não devolver ao
  membro o estado da conta dela.
  **Regra nova sobre "o membro pode falar com esta performer" entra LÁ** e fecha
  as duas portas de uma vez. O que NÃO está lá, de propósito: a performer estar
  de pé (perfil encerrado / conta suspensa) é gate exclusivo da foto e continua
  em `shareWith()` — trazê-lo para a fonte única passaria a impedir o membro de
  responder no chat de uma performer suspensa, que é mudar o chat, não unificar.
- **Foto recebida é denunciável pela performer** (`Report::REPORTABLE_TYPES`
  conhece `member_photo`), pela porta `/reportar` que já existe. **O handle é o
  `access_id`, nunca o id da foto** — este é comum a todas as performers com quem
  o mesmo membro compartilhou, e exibi-lo daria um identificador correlacionável
  entre perfis, que é o que o `FanAlias` existe para impedir. Quem traduz é
  `Report::resolveFromHandle()`; quem autoriza é `MemberPhotoService::performerCanView()`,
  a mesma regra do serving. **Foto vencida não é mais denunciável** — a janela de
  denúncia é a de exibição, igual ao story.
- **Denúncia em aberto retém os BYTES e congela o GC** (`Report::OPEN_STATUSES`,
  a mesma constante do story). Sem isso, quem envia conteúdo ilegal tem o botão
  de destruir a prova contra si a um clique. A retenção **não** dá visibilidade:
  foto retida e vencida não é legível por ninguém — nem pela performer que
  denunciou —, senão denunciar viraria a forma de esticar o próprio acesso.
- **O revoke SEMPRE responde sucesso, e o corpo é idêntico com e sem denúncia.**
  Diferente do story, que RECUSA a deleção da denunciada: story é 1:N, e a
  performer saber que "alguém entre os seguidores denunciou" não identifica
  ninguém; a foto costuma ter **uma** destinatária, então recusar entregaria a
  denunciante ao denunciado — com o chat entre os dois ainda aberto. Sob
  denúncia o revoke faz o que o titular pediu (some da lista, acessos vencem na
  hora, ninguém mais lê) e retém apenas bytes e linha para a revisão.
  **A copy de retenção na tela é UNIFORME**, para toda foto revogada: uma frase
  condicional seria o mesmo oráculo com outra roupa. Achado da revisão de
  segurança de 30/07 — **não reintroduza a recusa** sem resolver aquele canal.
- **Audit no fluxo: `member_photo.shared`, `.viewed`, `.revoked` — id e nada
  mais.** Sem caminho, sem nome de arquivo e sem `performer_profile_id`. O
  `.viewed` é gravado só na PRIMEIRA abertura (a tela é uma `<img>`; sem a dedup,
  recarregar a página enterra a trilha — mesma disciplina do filtro de chat).
- **O SUJEITO do `.viewed` é o ACESSO, não a foto — e isso é o controle, não um
  detalhe.** `Audit::log()` grava o ATOR em `user_id` e o alvo em `subject_id`.
  Como o ator do `.shared` é o MEMBRO e o do `.viewed` é a PERFORMER, apontar os
  dois para a mesma foto deixaria o par (membro, performer) a um
  `JOIN ... ON subject_id` de distância — **cópia permanente do mapa que morre
  com a foto**, na única tabela que o `DeletionService` preserva intacta.
  Apontando para o acesso, a ligação depende da linha de `member_photo_access`,
  que morre em hard delete junto com a foto. **Omitir o id da performer do
  metadata não fecha isso sozinho** (foi o achado da revisão de 30/07): evento
  novo neste fluxo escolhe o sujeito pelo LADO de quem age.
- **A linha morre em HARD delete**, e só depois de confirmar que os bytes saíram
  do disco. Linha soft-deletada guardaria "o membro X mandou 43 fotos, nestes
  horários" e faria o GC decifrar `path_encrypted` de todas a cada hora só para
  pular. `deleted_at` é estado intermediário (linha ida, bytes de pé).
  **Exceção: a foto DENUNCIADA sobrevive ao encerramento de conta** — encerrar a
  conta era o terceiro e mais poderoso botão de destruir a prova. A linha fica
  (soft-deletada e vencida, para o GC nunca mais tocá-la), **os bytes vão embora
  como os de todo mundo**, e o que resta é o `content_hash` + os carimbos:
  **prova sem conteúdo**, a mesma resposta do story. Reter os bytes de quem
  exerceu o direito de exclusão seria trocar um problema por outro.
- **`content_hash` é SHA-256 dos bytes processados, calculado ANTES do `Crypt`.**
  O ciphertext muda a cada gravação (IV aleatório), então hashear o que está no
  disco daria um valor diferente para o mesmo conteúdo — inútil para casar contra
  listas de hash conhecidas, que é o que de fato bloqueia o re-upload. `$hidden`
  e fora do `$fillable`: prova escolhida pelo acusado não é prova.
- **Ingestão re-encoda para matar EXIF/GPS** e, no mesmo passo, polyglot — o
  arquivo servido deixa de ser o arquivo enviado. `Content-Type` sai de re-sniff
  no **servidor**, nunca do que o upload declarou. **Nada de URL assinada:** a
  autorização é por sessão, a cada request.
- **Teto de imagem-bomba lido do HEADER, antes de qualquer decodificação**
  (`config/image.php`). 13 MP não é número de gosto: estourar `memory_limit` é
  **fatal error, não `Throwable`** — não cai no catch, o worker morre e o
  temporário EM CLARO fica órfão em `/tmp`. E não pode ser menor: 4 MP recusariam
  a foto de iPhone (12,2 MP), que é a entrada primária do produto. A conta está no
  config. **Não suba o número sem refazê-la contra o `memory_limit` real.**
- **`user_id` e `expires_at` são `$hidden`; FKs e `expires_at` do acesso ficam
  fora do `$fillable`** — mesma regra de `discrete_mode` e do 2FA.
- **O disco `member_photos` fica FORA do backup** — foto efêmera não pode
  sobreviver ao TTL dentro de um tarball. Hoje isso vale porque `docs/backup.sh`
  é **allowlist** (só `storage/app/private` e `storage/app/kyc`), não porque haja
  exclusão escrita: **quem converter aquele script para denylist reintroduz o
  problema em silêncio.**

> **Os 4 🔴 que bloqueavam o go-live foram FECHADOS** (**PR #110**): (1) foto
> denunciável pela performer via `member_photo`; (2) denúncia retém os bytes —
> contra o GC, contra o revoke e contra o encerramento de conta; (3) audit em
> share/view/revoke; (4) `canMemberSendTo` como fonte única. Detalhe acima.
>
> **Fechar os 🔴 não é o mesmo que liberar** — a decisão de ligar para usuário
> real é do PO, e continua valendo tudo o que esta seção diz sobre a natureza da
> feature (des-anonimização consentida, o rosto como chave de join global).
> **Segue em aberto**, agora como 🟡 e não como bloqueador: o cap de performers
> por foto (§ 1.1), a varredura de órfãos no disco (§ 1.5), e os achados da
> revisão de segurança de 30/07 registrados em `MASTER_HANDOFF_FINAL.md` — **a
> fila do admin não tem visualizador da prova retida** e **não há prazo máximo de
> retenção**. Os três caminhos de destruição de prova (revoke, GC e encerramento
> de conta) estão fechados.

## Stories da Performer — Sprint 9C (entregue, tag `v1.0-sprint9`)

**É a primeira publicação de conteúdo do projeto** — 1:N, com paywall por nível.
Imagem só (v1), TTL **fixo** de 24h, três níveis (`public` / `subscribers` /
`exclusive`). Três services com fronteira deliberada: `PerformerStoryService`
(ciclo de vida), `PerformerStoryStore` (bytes), **`StoryVisibilityService`
(paywall)**. Registro completo em `MASTER_HANDOFF_FINAL.md`, "Sprint 9C".

- **`StoryVisibilityService` é a dona única de "quem alcança este nível", e serve
  o feed E o serving.** Se as duas superfícies discordarem, o par vira oráculo
  (feed mostra + imagem 403 anuncia o que foi publicado para quem não pode ver) ou
  buraco de paywall (o inverso). `LEVEL_CAPABILITIES` mapeia nível → capacidades;
  o predicado de linha intersecta o mapa e o filtro SQL pergunta ao mapa. **Nível
  não mapeado falha FECHADO dos dois lados.** Regra nova de visibilidade entra
  lá — nunca no controller, nunca no Vue.
- **Não existe lista de "quem viu meu story" — em superfície nenhuma.** A única
  saída é a **faixa de membros únicos**, reusando `PerformerProfile::followersLabelFor`
  (a mesma dona da faixa de seguidores). Story **exclusivo retorna `null`, nunca
  zero**: zero já contaria quantos Black existem. `DISTINCT member_id` vem **antes**
  da faixa — faixar aberturas devolveria comportamento do membro, e quem reabre 5
  vezes empurraria a faixa sozinho.
- **Nenhuma URL assinada para os bytes.** Autorização por sessão, com follow e
  tier resolvidos **a cada request** — assinatura não amarra espectador, então a
  URL do membro Black seria bearer token. Há teste cobrando a stack de middleware
  para impedir a regressão. Disco `performer_stories`, privado, `serve => false`.
- **Sem `Crypt`, ao contrário da foto efêmera** (§ 2.5): story é 1:N e o `Crypt`
  carregaria o arquivo inteiro por espectador simultâneo, sem `Range`/seek. A
  autorização por request num disco não servido faz o papel. **Não "corrija" isso
  cifrando** — foi decidido contra o precedente do 9B de propósito.
- **A expiração vale na LEITURA; `stories:purge` é só GC** (precedente do
  `ChatAccess` e da foto). TTL fixo em 24h, então o relógio **não** é oráculo de
  TTL e não há prazo a faixar — `ExpirySlot` não se aplica aqui.
- **Denúncia aberta (`pending`/`reviewed`) congela GC E delete manual.** O
  auto-delete de 24h seria destruição de prova embutida no produto; GC educado ao
  lado de um botão de apagar manual não protege nada. `content_hash` (SHA-256 dos
  bytes **já processados**, calculado no store) é a prova que sobrevive ao
  arquivo — `$hidden` e **fora do `$fillable`**: prova escolhida pelo acusado não
  é prova.
- **Story entra pela porta `/reportar` existente**, não por rota própria: o dedup,
  o lock anti-duplo-submit, a recusa de autodenúncia e a resposta uniforme já
  vivem no `ReportController`. `Report::visibleTo` delega a
  `StoryVisibilityService::canView`, senão o POST vira **oráculo de existência**.
- **Blur de CSS não é paywall.** Tile bloqueado não recebe `image_url` nenhum —
  miniatura "borrada" está intacta no DevTools. A tela desenha placeholder.
- **404 para vencido ou performer fora do ar, 403 para tier insuficiente**, e a
  escolha vive na regra (`denialFor()`), não no controller. O estado da conta dela
  não é assunto do membro; o 403 é o upsell que o Modelo C monetiza.
- **Audit: `story.published` (id + nível) e `story.deleted` (id).** Sem caminho,
  sem hash, sem bytes — mesma disciplina do filtro de chat. **`story.reported`
  NÃO existe, de propósito:** poria o IP do denunciante em claro ao lado da
  acusação (ver `ReportController`). Decisão registrada para o PO, não silenciada.
- **O disco `performer_stories` fica FORA do backup**, como `member_photos`, e
  pela mesma razão: TTL de 24h não pode sobreviver num tarball de 14 dias. Vale
  hoje porque `docs/backup.sh` é **allowlist** — convertê-lo para denylist
  reintroduz o problema **nas duas features de uma vez**.

> **Ghost Mode: o ponto dourado nunca apaga para membro Black/FC — e isso é o
> perk funcionando.** O guard do § 2.7 vale para `story_views` (a "terceira rota"
> do item 9): a visualização **não gera linha**, não há coluna `hidden`. Logo ele
> não entra em contador nenhum e o feed marca todo story como não visto, para
> sempre. **Quem for "consertar" isso está desligando o Ghost Mode.**

> **Atualização (Sprint 13):** o **refactor de `role` FOI feito** (PR #125) — a
> fila humana agora é `/moderacao/*` sob `role:moderador`, com o evidence viewer
> da prova retida (PR #126). O texto acima (e outros trechos que dizem
> "moderador = admin, `/admin/reports`") descreve o estado ATÉ o Sprint 12 e fica
> como histórico. **Atualização (Sprint 15):** vídeo (live/chamada/group) FOI
> implementado (PRs #138–#145) e o § 2.5 está RESOLVIDO — não há serving HTTP de
> bytes de vídeo, o LiveKit SFU faz o relay via WebRTC (DTLS-SRTP) e o backend só
> emite tokens. O "serving sem cifra que travou as FC Sessions" deixou de existir
> por arquitetura.

## 2FA da performer — TOTP (Sprint 6)
A conta da performer guarda o KYC (documento + selfie) e é a identidade
verificada sob a qual o conteúdo é publicado: um take-over vaza PII sensível E
deixa terceiro publicar como ela. Senha não é fator suficiente para isso.

Fortify **não** está instalado (e não é dependência do core do Laravel). O TOTP
é `pragmarx/google2fa` direto; o QR é desenhado **localmente** em SVG inline
(`bacon/bacon-qr-code`) — nunca por serviço externo de QR, porque a `otpauth://`
carrega o segredo em claro. Regra em `app/Services/TwoFactorService.php`.

- **`two_factor_confirmed_at` é o que liga o 2FA**, não a presença do secret:
  entre `enable()` e `confirm()` a performer ainda não provou o autenticador, e
  gatear nesse intervalo trancaria a conta com um QR nunca escaneado.
- Secret e recovery codes: cast `encrypted` (APP_KEY), `$hidden`, **fora do
  `$fillable`** (mesma regra de `discrete_mode`). Rotacionar APP_KEY derruba os
  dois — a performer cai no re-cadastro do autenticador.
- **Recovery code é de uso único, sob `lockForUpdate`.** Dois POSTs simultâneos
  com o mesmo código autenticariam duas sessões sem o lock.
- **TOTP também é de uso único** (`two_factor_last_used_ts`, `verifyKeyNewer`).
  Sem isso o código valia os ~90s da janela: o capturado no desafio servia em
  seguida para `/2fa/disable` e desligava o próprio fator.
- **`confirm()` NÃO aceita recovery code** — o passo existe para provar que o
  app autenticador funciona. `disable()` e a reemissão de códigos aceitam, e
  **exigem** um fator: quem só tem a sessão não remove o segundo fator.

### O gate vale nas DUAS portas de auth — e a prova é diferente em cada uma
Middleware `2fa` (`TwoFactorChallenge`). Ignora quem não é performer com 2FA
confirmado, então pode ser aplicado em grupo compartilhado, como o
`documents.accepted`.

- **Web (sessão):** marca na sessão, que guarda o **id do usuário**, não `true`
  — assim não é herdável por uma sessão que trocou de dono. Aplicado no grupo
  `auth` INTEIRO, não só em `performer.*`: a sessão da performer alcança chat e
  catálogo, e gatear só o dashboard deixaria a conta sequestrada conversando
  com membros.
- **API (Sanctum):** não há sessão onde marcar, então o fator vem **antes do
  token**. `POST /api/v1/auth/login` de quem tem 2FA devolve `two_factor_required`
  + um token com a habilidade `2fa:challenge` e mais nada (10 min);
  `POST /api/v1/auth/2fa/challenge` troca por código e devolve o token real. O
  middleware testa a habilidade com `in_array` **e não `$token->can()`** — o
  `can()` do Sanctum responde true para qualquer coisa num token `*`, o que
  barrava justamente quem tinha passado pelo desafio.
- `/broadcasting/auth` entra pelo `withBroadcasting` com `['web','auth','2fa']`.
  No padrão (`channels:` no `withRouting`) ele sai só com `web` e a sessão
  mandada ao desafio ainda assinava `conversation.{id}`.
- **Fora do gate ficam só o desafio e o logout** (senão o redirect aponta para
  rota que ele mesmo bloqueia, e quem perdeu o autenticador não sai da conta).

**Rota autenticada nova entra no gate** — nas duas portas. Foi a lição do
`documents.accepted`: gate que fecha uma porta só não é gate.

> Ressalva conhecida: o login da web COMPLETA antes do fator (`Auth::login` e
> depois o middleware barra). É mais fraco que desafiar antes de estabelecer a
> sessão; o que fecha o buraco na prática é o gate cobrir o grupo `auth`
> inteiro. Trocar por um login em dois passos é follow-up.
>
> Não implementado: alerta em N falhas de desafio (hoje só grava
> `performer.2fa_challenge_failed` no audit e ninguém consome).

## Login OTP passwordless — Sprint 11 (PR #118)
Porta ALTERNATIVA ao login por senha (convive, não substitui): código de 6 dígitos
por e-mail. Dona única das regras: `app/Services/OtpService.php`. As duas portas
(web/sessão e API/Sanctum) chamam os MESMOS dois métodos — uma segunda cópia da
regra numa das portas reabriria a enumeração ou o reuso.

- **Anti-enumeração é o eixo.** `requestCode` responde igual para e-mail existente
  e inexistente; o rate limit de **3/hora** conta a STRING do e-mail (normalizada
  `mb_strtolower(trim())`) **antes** do lookup — senão o próprio limite viraria
  oráculo. Suspensa/banida não recebe código (mesmo corte do `AuthService`), e a
  resposta é a mesma. `verifyCode` devolve `null` para TODO modo de falha.
- **O código é credencial efêmera.** TTL 5 min, uso único, 5 palpites por código,
  os anteriores morrem quando um novo nasce. `code` é `$hidden` e **fora do
  `$fillable`** (mesma regra de `discrete_mode`/2FA — nasce só no service, por
  atribuição direta, nunca de um payload). Comparação com `hash_equals`. **NUNCA
  entra em `audit_logs`:** os eventos (`auth.otp_requested`, `auth.otp_login`)
  gravam o usuário e o fato, jamais o dígito.
- **`verifyCode` roda sob `DB::transaction` + `lockForUpdate`.** Sem serializar,
  palpites concorrentes furariam o teto de 5 e dois acertos logariam duas vezes o
  mesmo código de uso único — mesma disciplina do recovery code do 2FA.
- **2FA da performer se aplica DEPOIS do OTP.** O OTP prova o e-mail, não o segundo
  fator: acerto de OTP para performer com 2FA devolve o **token de desafio**
  (`2fa:challenge`), nunca o token cheio (idêntico ao login por senha da API). Na
  web, o gate `2fa` desafia depois de a sessão nascer.
- **A porta WEB confia no e-mail da SESSÃO, não do corpo do request** — um POST
  forjado não troca o alvo da verificação. A API lê do corpo de propósito (não há
  sessão). O `VerifyOtpRequest` exige `email` para a API; um teste trava a
  regressão de a web voltar a lê-lo do corpo.
- **`otp:purge` (de hora em hora) é só GC** — a expiração já vale na LEITURA
  (`isConsumable`). Sem ele, material de auth vencido em claro se acumularia.
  Precedente de `stories:purge` / `visits:purge`.
- **Hard Delete varre `otp_codes`** (`DeletionService::purgeOtpCodes`, DELETE real):
  a FK `cascadeOnDelete` não dispara porque `users` é soft-delete/anonimização.
- **E-mail discreto** (`OtpCodeEmail`, `ShouldQueue`): assunto neutro, remetente
  "Limen", **sem imagem remota** (pixel audit). O dígito viaja no corpo porque é o
  produto do e-mail — não em audit, não em log.

## Captcha — driver abstrato hCaptcha/Turnstile (Sprint 16)
Anti-bot em login e cadastro. O que era só hCaptcha (Sprint 9) virou **driver
abstrato** com dois provedores intercambiáveis — **hCaptcha** e **Cloudflare
Turnstile** —, motivado pelo fim do trial Pro do hCaptcha (11/08/2026, cairia para
o plano free e seus limites; o Turnstile é gratuito e sem eles). **Sobe DESLIGADO**
(`CAPTCHA_PROVIDER=none`, o padrão versionado — NO-OP total). Detalhe completo em
`docs/CAPTCHA.md`.

- **Um interruptor:** `CAPTCHA_PROVIDER=none|hcaptcha|turnstile`. `none` é no-op
  (campo não exigido, widget não monta, zero byte para terceiro). Chaves por
  provedor: `HCAPTCHA_SITEKEY`/`HCAPTCHA_SECRET` e `TURNSTILE_SITE_KEY`/
  `TURNSTILE_SECRET_KEY`. **Ponte de compat:** sem `CAPTCHA_PROVIDER`, um
  `HCAPTCHA_ENABLED=true` legado ainda seleciona o hCaptcha (não desliga um gate
  ativo em silêncio no deploy).
- **Dona única da ESCOLHA do provedor:** `App\Services\Captcha\CaptchaManager` —
  resolve o driver do config. A regra de validação e as props do Inertia falam só
  com ele, nunca com um driver concreto. **A lógica NÃO é duplicada por provedor:**
  `RemoteCaptchaDriver` tem o POST de siteverify + fail-open compartilhado
  (hCaptcha e Turnstile têm contratos server-side IDÊNTICOS — POST `secret`+
  `response`, resposta `success`); `HcaptchaDriver`/`TurnstileDriver` só declaram a
  chave de config; `NullCaptchaDriver` é o `none`.
- **Dona única do CONTRATO nas portas:** `App\Rules\CaptchaValid` (era
  `HCaptchaValid`), consumida por `LoginRequest`, `RegisterWebRequest`,
  `RegisterConsumerRequest` (o performer herda) e `RequestOtpRequest`. Campo NEUTRO
  `captcha_token` (`CaptchaValid::FIELD`) — o front captura o token pelo callback e
  o envia via `useForm`, então UM nome serve aos dois provedores. **Rota de auth
  nova entra pela regra, não reimplementa o captcha** (lição do `documents.accepted`).
- **Frontend:** `resources/js/Components/Captcha.vue` (era `HCaptcha.vue`) — widget
  único que carrega o SDK do provedor ativo. **As duas URLs de SDK são LITERAIS**,
  uma por provedor (`js.hcaptcha.com` e `challenges.cloudflare.com`), para a
  varredura de origem externa (`ExternalAssetPolicyTest`, ambos em
  `ALLOWED_JS_ORIGINS`) enxergá-las — escondê-las atrás de um mapa/variável faria o
  terceiro passar despercebido pela auditoria. Montado só em /login e /cadastro
  (públicas, deslogadas), **nunca** no `app.blade.php` (docs/PIXEL_AUDIT.md).
- **Segurança preservada do provedor único:** segredo nunca vai ao frontend,
  `remoteip` nunca vai ao siteverify (vale p/ os dois — a Limen não retransmite o
  IP ao subprocessador), fail-OPEN em queda do provedor (5xx/timeout passa, como o
  GeoBlock), token de uso único com reset no `onError`. **CSP não mudou** — só há
  `frame-ancestors 'self'`, sem `script-src`/`frame-src` restritivo, então nada a
  adicionar (e um `script-src` parcial quebraria o Vite).
- **Conformidade (bloqueia ativação, não merge):** QUALQUER provedor é
  subprocessador e vê o IP de quem abre as telas de auth. Antes de sair de `none`
  em produção, o provedor escolhido entra na política de privacidade + registro de
  subprocessadores + DPA assinado (Intuition Machines p/ hCaptcha; Cloudflare, Inc.
  p/ Turnstile). Mesma disciplina de linguagem do painel de visitantes: **captcha
  não é garantia de ausência de bot** — encarece, não elimina.

## Geobloqueio (FOSTA-SESTA) — montado, NÃO ativo
Middleware `GeoBlock` nos grupos `web` e `api`, 451. **Com `GEO_DRIVER=none` (o
padrão e o valor de hoje) ele não bloqueia ninguém** — falta a fonte de
geolocalização. Fail-OPEN de propósito: fail-closed sem fonte derruba o site.
Detalhe e passos de ativação em `docs/GEOBLOCKING.md`.

- `/up` fica fora — monitor de uptime sonda dos EUA e viraria alarme falso.
- O driver `cloudflare` **só funciona com o origin fechado aos ranges do CF**:
  `CF-IPCountry` é header, e um `curl -H` direto no IP do servidor passa.
- Audit `access.geo_blocked` **deduplicado por IP/hora** — sem isso um bot em
  laço num endpoint não autenticado enterra a trilha.
- **Não é garantia jurídica: VPN contorna.** Não escreva "americanos não
  acessam" em política, contrato ou auditoria — mesma disciplina de linguagem
  do painel de visitantes.

## Filtro de conteúdo do chat — duas categorias
Listas em `config/chat_filters.php`; casamento em `app/Support/ChatContentFilter.php`.

- **TIPO 1 `legal`** — encontro mediante pagamento e transação fora do ledger.
  422 com mensagem que **cita os Termos de Uso**.
- **TIPO 2 `conduct`** — ameaça/sextorsão e insulto **direcionado**. 422 com
  mensagem de política de conduta + `flagged_for_review` no audit.

### O que o filtro deliberadamente NÃO barra (decisão do PO — não "consertar")
1. **Troca de contato é PERMITIDA.** WhatsApp, telefone, Instagram, endereço:
   legítimo num produto de conteúdo adulto/dating. A versão anterior barrava
   isso e derrubava "comprei um fone de ouvido" e "vi seu instagram".
2. **Palavrão em contexto sexual consentido é PERMITIDO.** "que puta gostosa"
   é o vocabulário do produto. Só entra insulto DIRECIONADO (pronome +
   xingamento), e um qualificador consensual na mensagem — `safada`, `gostosa`,
   `linda` — **desarma** o casamento: "sua puta safada" passa, "sua puta
   nojenta" não. Heurística: erra no elogio seco ("sua puta"). O caminho para
   o caso ambíguo é a denúncia (`Report`), que tem contexto e um humano.
3. **Encontro SEM valor monetário é PERMITIDO.** "vamos num motel" passa;
   "motel, 300 reais" não. Termo ambíguo (`programa`, `motel`, `presencial`)
   só bloqueia junto de `money_signals` na MESMA mensagem — é o único jeito de
   usar `programa` sem barrar "qual seu programa favorito".

### Invariantes
- **O filtro roda ANTES da máscara de opt-out** em
  `ChatService::performerMessageFromInterest`. Depois dela, o suprimido daria
  202 e o normal 422 — o par viraria oráculo do opt-out. Guardado por teste.
- Normalização fecha ZWSP e **fullwidth** (achados da revisão de segurança):
  `\p{Cf}` sai ANTES do `Str::ascii` (que virava o ZWSP em espaço real) e
  NFKC colapsa fullwidth (que o `Str::ascii` DESCARTAVA, zerando a mensagem).
- `audit_logs` leva **categoria + `rule_hash` (HMAC)**, nunca a regra em claro
  (a lista está no repo: `sha256` seria revertido por tabela) e **nunca o
  corpo** — seria 2ª cópia do conteúdo do chat, fora do soft-delete do LGPD.
  Deduplicado por (usuário, regra), senão enumerar a lista enterra a trilha.
- **A moderação age por REPETIÇÃO**, não por caso isolado: sem o corpo, o
  admin vê "usuário X disparou conduta 9x". Fila humana de verdade (com
  contexto) é follow-up — `reports` exige `reporter_id` e um alvo morfável, e
  mensagem bloqueada não é persistida.

> **Não é anti-evasão, e o "segredo" nunca foi real.** A lista está no repo e o
> remetente distingue as categorias pela resposta. A mensagem de erro é
> específica de propósito: dizer o que foi violado vale mais do que uma
> vaguidade que o evasor contorna em duas tentativas e que só prejudica quem
> agiu de boa-fé. Ausência de bloqueio **não** é prova de que nada foi combinado.

## Aceite de documentos da performer — `documents.accepted`
Política de Conteúdo Proibido + Contrato de Performance. Versão vigente em
`config/documents.php`; **bumpar a versão força re-aceite de todas** — não bumpe
por typo. A versão nunca vem do request: o servidor resolve pelo config, senão
bastaria postar a versão velha para satisfazer o gate sem ver o texto novo.

`document_acceptances` é append-only (o model recusa `update`): versão nova é
LINHA nova, é o histórico que dá o lastro jurídico. IP e user-agent entram como
HMAC (`app/Support/ClientFingerprint.php`), nunca crus — mas o `audit_logs` do
mesmo evento ainda grava o IP em claro; a ressalva está em `docs/SECURITY_ISSUES.md`.

**Rota nova de performer entra no grupo `documents.accepted`.** Vale para as
duas portas de auth: web (redirect) e API Sanctum (403 JSON). O middleware ignora
quem não é performer, então rota compartilhada (chat) pode recebê-lo direto sem
afetar o membro. Fora do gate ficam só a própria tela de aceite (senão o redirect
dá loop) e as páginas públicas dos textos.

O texto jurídico ainda é placeholder (aguardando Opice Blum) — **não descrever
para auditoria como "contrato aceito"** até o texto definitivo entrar.

## Ambiente de dev (atualizado 31/07/2026) e suas limitações
- **O dev roda NO SERVIDOR via SSH** (`deploy@62.238.46.212`, `~/limen-dev`). A
  **VM local (`~/teste`) foi descontinuada.** `~/limen-dev` (dev) e
  `/var/www/limen` (staging/prod) são **clones SEPARADOS** — não presuma estado
  comum entre eles.
- **`git credential store` configurado no servidor:** push/pull não pedem senha.
  Mas **segue sem `gh` CLI** — abrir PR ou issue por código continua impossível; o
  push devolve a URL de `pull/new` para o PO abrir manualmente.
- **Sem `pdo_sqlite`**, e o `phpunit.xml` aponta para sqlite. **Não edite o
  `phpunit.xml`** — prefixe os `DB_*` no comando (é o que o CI faz). A senha do
  banco de teste está no **`.env` do servidor** (usuário `limen`, banco
  `limen_test`) — **fora do Git** (princípio 5), **não** é o `limen_dev_pw` que
  este exemplo trazia antes (placeholder, não funciona):
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD='<ver .env>' \
  HCAPTCHA_ENABLED=false php artisan test
  ```
- **`HCAPTCHA_ENABLED=false` ao rodar testes localmente:** o `.env` do servidor
  tem o captcha LIGADO (é dev real), e com ele ligado os Form Requests de auth
  exigem o campo `captcha_token` — a suíte inteira de auth quebra. O CI roda com
  ele desligado; reproduza isso no comando (acima), **não** editando o config.
  **Desde o driver de captcha (§ "Captcha"), o interruptor é `CAPTCHA_PROVIDER`;**
  `HCAPTCHA_ENABLED=false` no comando continua funcionando pela ponte de
  compatibilidade (sem `CAPTCHA_PROVIDER` definido, `HCAPTCHA_ENABLED=false` cai
  em `none`). Equivalente e mais explícito: `CAPTCHA_PROVIDER=none`.
- Migration quebrada faz o Pest re-rodar `migrate:fresh` a cada teste e **parece
  hang**, não erro. Rode `php artisan migrate:fresh` sozinho para ver a exceção.
- **Ressalva de suíte local:** `GeoBlockTest` "bloqueia com 451" falha **só neste
  clone de dev** — a view custom de erro 451 não está compilada aqui, então cai na
  página de erro padrão do Symfony. É verde no CI. Não é regressão; não persiga.

## Nota operacional — 06/08/2026 (ambiente de teste)

- **PHP subiu de 8.4.22 para 8.4.24** no servidor (upgrade via apt junto com
  `php8.4-sqlite3`). A stack no topo deste arquivo já cita 8.4.24.
- **`php8.4-sqlite3` agora ESTÁ instalado** (antes ausente por premissa). Isso
  torna o `phpunit.xml` (que aponta para sqlite `:memory:`) uma armadilha: rodar
  `php artisan test` PURO agora executa as migrations em sqlite e quebra em massa,
  porque várias migrations usam SQL específico de MySQL (`IF()`, `MODIFY ... ENUM`,
  `UPDATE ... JOIN`). **Isso NÃO é bug** — as migrations estão corretas para MySQL.
- **Regra:** rode a suíte SEMPRE com as variáveis MySQL prefixadas, nunca
  `php artisan test` puro:
```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD='<ver .env>' \
  HCAPTCHA_ENABLED=false php artisan test
```
  Resultado esperado (`main` `92ba2c7`, pós-PRs #173–#180): **1999 passam, 1 falha**
  de **2000 testes / 15960 asserts** (o `GeoBlockTest` da view 451, falha documentada
  só neste clone de dev; verde no CI). Este é o número consolidado da sessão de fecho
  da fila de melhorias — Turnstile (#174), sinais de atividade (#175), teaser (#176),
  filtro de cidade (#177), visitas bidirecionais (#178), microinterações (#179) e
  intro de voz (#180), todos mergeados na `main`.
