# Limen — Guia do Projeto (leia antes de qualquer tarefa)

Plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Este arquivo é o cérebro do projeto. O Claude Code deve segui-lo em toda sessão.

## Stack
- PHP 8.4.22 + Laravel 13 (`laravel/framework: ^13.8`)
- MySQL 8.4 (via Docker) — banco principal
- Redis (via Docker) — cache/filas
- Front-end: **Inertia + Vue 3 + Tailwind v4** (+ Ziggy para rotas no JS).
  Blade sobrou só no layout raiz. Mudar de stack, só com aprovação do PO.
- Pagamento: Asaas / PIX (entregue na fundação)
- Realtime: Laravel Reverb (chat). O servidor Reverb **ainda não roda** —
  dev/staging usam o driver `log`. Ver `config/broadcasting.php`.
- Streaming de vídeo (LiveKit): **planejado, nada implementado.** Não há
  dependência no projeto — não presuma que existe.

## Princípios de arquitetura (não negociáveis)
1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados, prevenção de conteúdo ilegal. É fundação, não feature.
2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer `UPDATE ... saldo = saldo + x`. Todo movimento é uma linha nova em `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não repetir.)
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
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.

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
- **[M.10] Payout da performer:** ciclo **mensal** (não a qualquer momento), mínimo
  **100 tokens** acumulados, processado **no dia 1** referente ao mês anterior,
  via PIX (Asaas). R$ equivalente visível no dashboard.
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

## Estado atual

> **Estado atual** (`main`, `1d63371`, Sprint 13 FECHADO): **1524 testes, 7799
> asserts** (verde local e no CI; a antiga falha só-local da view 451 do GeoBlock
> não recorre depois do `npm run build`, que compila a view). **Base original**
> (PR #69, `229d852`): 556 testes, 2614. O detalhe completo vive em
> **`docs/MASTER_HANDOFF_FINAL.md`** — esse é o doc a ler antes de pegar tarefa (o
> `MASTER_HANDOFF_SPRINT6.md` é histórico). Este resumo só situa.

**Sprints 6, 7, 8, 9A, 9C, 10, 11, 12 e 13 fechados** (tags `v1.0-sprint6` a
`v1.0-sprint9a`, **`v1.0-sprint9`** no fecho do 9C, **`v1.0-sprint9.1`** no fecho
dos bloqueadores da Foto Efêmera, **`v1.0-sprint10`** (`402d29e`) no fecho do
Sprint 10, **`v1.0-sprint11`** (`11354b4`) no fecho do Sprint 11,
**`v1.0-sprint12`** (`f23368a`) no fecho do Sprint 12, e **`v1.0-sprint13`**
(`1d63371`) no fecho do Sprint 13). **O Sprint 9B não tem tag própria** e não
está fechado.

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
- Fora da trilha numerada: **Waitlist** (double opt-in, drip, painel admin) e **Círculos** (assinaturas por tier — Fase A Explorador→Prestige, Fase B Black/FC).

> **Sprint 2 não tem registro** nos docs; a numeração pula de 1 para 3 de propósito.
> Não é lacuna de documentação a preencher — é como o histórico ficou.

> **Sprint 13 (registrado como backlog) foi ENTREGUE** — o refactor de roles, o
> evidence viewer, as múltiplas localizações, as permissões de foto e o feed UI
> viraram os PRs #125–#129 (ver a lista de Sprints acima). O que ficou de fora
> daquele backlog e ainda vale carregou para o Sprint 14 abaixo.

### Backlog — Sprint 14 (registrado, não iniciado)
Ordem não é prioridade. O grosso deste bloco é a **implementação do modelo de
monetização fechado** (§ "Modelo de monetização — DECISÕES FECHADAS") — cada tipo
novo de gasto/crédito é **migration no enum de `entry_type`** do ledger
append-only (princípio nº 2), NUNCA `UPDATE` de saldo. LiveKit segue sendo o
gargalo dos itens de vídeo (§ 2.5: serving sem cifra em memória travou as FC
Sessions).

- **Conteúdo permanente** (fotos/vídeos com níveis de acesso) — a performer define
  nível (Aberto / Premium / Exclusivo / FC Only) + preço em tokens; desbloqueio é
  PERMANENTE; split 80/20. Reaproveita a disciplina de paywall por peça já provada
  no Photo Permissions (Sprint 13) e no Story (§ 2.3).
- **Live pública (LiveKit)** — X tokens por bloco de 10 min; todos pagam; split
  70/30; gorjeta/presente na live 80/20.
- **Chamada privada 1:1 / Videochamada (LiveKit)** — X tokens/minuto; todos pagam;
  split 70/30. (São o MESMO track de infra LiveKit da live pública — planejado
  desde a fundação, nada implementado, esbarra no serving sem cifra do § 2.5.)
- **Presentes virtuais** — catálogo fixo da Limen com preços fixos, split 75/25,
  animação na tela durante a live. Já no modelo como BACKLOG.
- **Desconto de tokens por tier de assinatura** — aplica sobre a COMPRA de pacotes
  (Explorador/Insider 10% · Prestige 20% · Black 30% · FC 40%).
- **Tokens inclusos nas assinaturas** — creditados no 1º dia do ciclo, não
  expiram, `subscription_grant` no ledger, sujeitos ao teto de acúmulo de 5.000.
- **Payout mensal (ciclo dia 1)** — processar no dia 1 referente ao mês anterior,
  mínimo 100 tokens acumulados, via PIX (Asaas). Hoje o payout existe mas não é
  amarrado ao ciclo mensal do modelo.

Carregados do backlog do Sprint 13 (ainda não feitos):
- **Verificação de documento como produto** (R$ 9,90) — selo de verificação pago
  para o membro. **Depende da Didit** (a mesma integração do KYC da performer).
- **Pin PHP 8.5→8.4 no `deploy.yml`** — o job de teste do CI fixa `php-version:
  '8.5'`, mas o alvo de produção é 8.4.22. Alinhar (é mudança em
  `.github/workflows/`, que exige token com escopo `workflow` — o servidor de dev
  não tem, então vai pela UI do GitHub ou por um push com escopo).

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
> como histórico. Vídeo (live/chamada) segue no backlog do Sprint 14 e ainda
> esbarra no serving sem cifra do § 2.5 que travou as FC Sessions.

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
  tem o hCaptcha LIGADO (é dev real), e com ele ligado os Form Requests de auth
  exigem o campo `h-captcha-response` — a suíte inteira de auth quebra. O CI roda
  com ele desligado; reproduza isso no comando (acima), **não** editando o config.
- Migration quebrada faz o Pest re-rodar `migrate:fresh` a cada teste e **parece
  hang**, não erro. Rode `php artisan migrate:fresh` sozinho para ver a exceção.
- **Ressalva de suíte local:** `GeoBlockTest` "bloqueia com 451" falha **só neste
  clone de dev** — a view custom de erro 451 não está compilada aqui, então cai na
  página de erro padrão do Symfony. É verde no CI. Não é regressão; não persiga.
