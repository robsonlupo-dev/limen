# Monetização Limen — Onda 4 & o fork da assinatura

> **Status:** modelo de produto **travado com o PO** (01/10/2026). Este doc é a fonte
> canônica do **Fã-Clube da performer** (a assinatura por-performer da Onda 4). Ainda
> **não há código** — o próximo passo é o modelo de dados + ledger + telas, que sai
> daqui. Números de preço (piso/teto) e faixas de baleia marcados **(a calibrar)**.
>
> Precedência: o modelo fechado da economia (`docs/ECONOMIA.md`) vence qualquer coisa
> aqui que o contradiga. Este doc **adiciona** uma superfície nova; não altera Círculo,
> payout, teto nem split existentes.

---

## 0. A decisão do fork: HÍBRIDO

O Limen é **clube _e_ marketplace**. O **Círculo global** (`docs/ECONOMIA.md` §3 —
Explorador → FC) continua sendo a **espinha dorsal**: assinar o Limen, com franquia de
token, desconto na compra, acesso a conteúdo por tier, e o anonimato (Modo Discreto do
Black/FC). Em cima dele entra uma segunda superfície, **por-performer**: o **Fã-Clube**
(estilo OnlyFans). Os dois mundos convivem — a performer pode estar em um, no outro, ou
nos dois.

**Premissa 0 intacta:** o Círculo continua global (`Subscription` = `user_id` +
`circle_id`, sem `performer_id`). O Fã-Clube é uma relação **nova e separada**
(`fanclub_subscriptions`, ver §9), não um `performer_id` enfiado no Círculo.

**Norte do desenho:** tudo aqui favorece mais a **performer** que o membro — é dar a
ela, dentro de uma casa só, as fontes de renda que hoje a obrigam a estar em 5
plataformas (dating + stripper virtual + OnlyFans + "sugar camuflado", sempre dentro
dos trilhos de conteúdo/interação da plataforma — ver guard-rail legal em §10).

---

## 1. O que é o Fã-Clube

A performer abre o **Fã-Clube dela**: um conjunto recorrente de conteúdo ("o set de
fã-clube") que o membro acessa **por assinatura mensal paga em token**.

- **Assinar = acesso TOTAL ao set**, enquanto a assinatura estiver ativa. Sem acesso
  parcial, sem "escolhe N peças", sem picks incluídos. (A ideia de picks/curadoria foi
  descartada por complexidade — não entra nem na v1 nem depois sem nova decisão.)
- **Recorrente e revogável (puro):** enquanto paga, vê **tudo** — o que foi postado
  antes e o que for postado durante. **Parou de pagar → perde o acesso ao fã-clube
  inteiro** (passado _e_ futuro). Reassinou → vê tudo de novo. O membro **não possui**
  nenhuma peça do fã-clube; ele aluga acesso ao fluxo dela.
- **O permanente vive FORA do fã-clube:** quer possuir pra sempre? compra **avulso**
  (PPV no chat / unlock de peça do cofre — `content_unlocks`, permanente, já existe).
  Quer o fluxo dela? **assina o fã-clube.** Dois mundos limpos, nunca confundidos.

### 1.1 O "set de fã-clube" é um balde novo

O conteúdo de fã-clube é um **bucket separado** do resto, para não vazar nem se
confundir:

- **≠ cofre normal** (Aberto/Premium/Exclusivo/FC-Only — gating global por tier, unlock
  por peça, permanente).
- **≠ PPV do chat** (peça dirigida, unlock por peça, permanente).
- O acesso ao set é **só a assinatura** (não um unlock por peça). Assinatura inativa →
  o set inteiro volta a travar.

Implicação de serving: a decisão de ver uma peça de fã-clube é *"a assinatura deste
membro a esta performer está ativa agora?"* — **não** uma linha em `content_unlocks`.

---

## 2. O trilho: token, 80/20, sem BRL novo

- **Preço em token.** A plataforma inteira é token; o Fã-Clube não abre exceção. O
  preço é guardado e cobrado em token.
- **Pago do saldo do membro.** O débito sai da carteira de token dele — a mesma que
  recebe franquia, compra avulsa, etc. **Nenhum trilho BRL recorrente por-performer**
  (sem cobrança de cartão/PIX por performer — isso duplicaria o motor de cobrança e
  quebraria o anonimato do pagamento).
- **Split 80/20** (igual conteúdo permanente): a performer recebe 80%, a Limen retém
  20%. **Não** é "Assinatura de Círculo" (aquela é 100% Limen — `docs/ECONOMIA.md` §4);
  o Fã-Clube é remuneração de performer.
- **Entry types novos** (cada um é migration no enum do ledger, append-only):
  - `spend_fanclub_sub` — débito do membro no ciclo (gasto; **fora** do payout).
  - `fanclub_sub_credit` — crédito 80/20 à performer, **rate `content`**; é `*_credit`
    → **ganho sacável, entra no `payout.earning_entry_types`, ignora o teto de acúmulo**
    (mesma disciplina de `ppv_message_credit` / `custom_order_credit`).
- **Margem:** como é 80/20 pago em token, a margem é **idêntica à de qualquer conteúdo
  80/20** — herdada, não nova. O piso de 25% / R$0,625 por token (`docs/ECONOMIA.md`
  §11) vale automaticamente; o Fã-Clube **não introduz risco de margem novo**. Ver a
  simulação em §8.

---

## 3. Preço: único por padrão, desconto VIP opcional da performer

**Decisão:** **um preço só** (o público), definido pela performer. **Todos pagam a
mesma quantidade de tokens** — free, Explorador, … , Black, FC.

Por quê não forçar desconto a Black/FC: eles **já pagam menos em reais** por qualquer
token (franquia a R$0,71–0,75/token vs R$1,00 do free + desconto na compra). Forçar a
performer a cobrar **menos tokens** deles seria **desconto em cima de desconto, pago
pela performer** — justo com o cliente mais rico, contra o norte pró-performer.

**Opcional (só se ELA quiser):** a performer pode **ligar** um **preço VIP menor** para
Black/FC (desligado por padrão). É escolha de marketing dela (cortejar baleia com
entrada mais barata), **não** um imposto da plataforma. Trava do servidor: **público ≥
VIP** — ela nunca consegue pôr o preço VIP mais caro que o público.

> **Terceira via, fora da v1:** desconto VIP **bancado pela plataforma** (membro paga
> menos, performer recebe cheio, a casa come a diferença). Mais generoso dos dois
> lados, mas custa margem por assinatura — fica para depois, se a telemetria pedir.

### 3.1 A tela de preço — honesta sobre o spread

O Limen tem **dois valores de token**: compra a ~R$1,00 (âncora Starter), saque a
**R$0,60**; e a performer recebe **80%**. Então "sticker" ≠ "bolso". A tela mostra
**as duas pontas, sempre**, e deixa a performer pensar por qualquer lado:

- **Modo "quanto o MEMBRO paga":** ela digita o preço → aparece *"Você recebe no saque
  ~R$Y"*. Ex.: 50 tokens → *"Membro paga 50 tk (~R$50) · você saca ~R$24,00"*.
- **Modo "quanto EU quero receber":** ela digita o líquido → o sistema resolve de trás
  pra frente → aparece *"O membro vai pagar Z tokens"*. Ex.: R$30 líquidos → **63 tk**
  (30 ÷ 0,60 ÷ 0,80) → *"Você saca ~R$30 · membro paga 63 tk (~R$63)"*.

Nos dois modos aparecem **as duas pontas** antes de salvar. Se a performer ligar o
preço VIP, a tela mostra o **saque de cada tier** (público e VIP) para ela decidir com
os olhos abertos. Preço guardado **em token**; R$ é só auxílio de leitura.

---

## 4. Cobrança recorrente: renova sozinha, avisa-e-pausa

- **Assinar = vínculo** que **renova automático** no dia do ciclo, debitando o preço do
  **saldo de token** do membro. Ele não faz nada para continuar.
- **Desvincular** a qualquer momento → o próximo ciclo **não debita**; o saldo fica
  livre para outra performer de fã-clube ou para chat/presente/live/chamada. (Token é
  **fungível** — não há reserva/orçamento separado para fã-clube; o limite é o saldo.)
- **Saldo insuficiente no dia da renovação → avisa-e-pausa:** avisa com antecedência
  ("renova em X dias, faltam Y tokens"), dá janela de carência e, se ainda faltar,
  **pausa** a assinatura (perde acesso até recarregar e retomar). **Nunca** cai no
  cartão/PIX — o trilho é só token. Mesma disciplina (CDC-safe) da fila de pendência.
- O membro tem uma tela **"Minhas assinaturas"** para ver/gerenciar/desvincular tudo
  num lugar (evita que ele se comprometa demais sem perceber).

---

## 5. Anonimato & o sinal de baleia (a exceção escopada ao §12)

O `docs/ECONOMIA.md` §12 diz: *"o nível do membro é invisível para a performer"* (única
exceção hoje: unlock de FC-Only). O Fã-Clube cria uma **segunda exceção, deliberada e
escopada**, que é o que dá o cultivo de baleia **sem** quebrar o anonimato:

- Na lista de assinantes do fã-clube dela, a performer vê cada um como:
  **FanAlias** (ex. "Fã #4821") + **selo de tier** (Black / FC / assinante / free) +
  **faixa de gasto** (ver abaixo). **Nunca** nome, nunca contagem abaixo do piso
  k-anon, nunca gasto exato.
- Isso é um recurso (ela sabe **em quem** investir atenção — PPV, oferta, puxar papo no
  chat pago), não um vazamento. Mas **muda uma regra fechada**, então: **consentimento
  explícito do membro ao assinar** — *"ao entrar no fã-clube, a performer vê que você é
  Black/FC e sua faixa de apoio, nunca seu nome."*
- **Diferencial que o OnlyFans não copia:** cultivo de baleia **com** anonimato.

### 5.1 Faixas de gasto (baleia) — (a calibrar)

Em vez do número exato (canal lateral de deanonimização), a performer vê uma **faixa**:

- **Novo** — 1º ciclo / pouco acumulado com ela.
- **Recorrente** — 2+ ciclos ativos / gasto estável.
- **Alto apoiador** — faixa de topo (ex. top ~10% de gasto com ela no período).

Limites **calibráveis** e sempre **respeitando o piso k-anon** (não podem ser tão finos
que identifiquem). Mesma filosofia das métricas de presença dos Insights (faixa, nunca
número).

---

## 6. Quem pode assinar (o lado marketplace)

**Qualquer um** assina pagando em token — inclusive **usuário free** (ele já pode
comprar token sem assinar Círculo). Todos pagam o **preço público** em tokens (ou o VIP,
se a performer ligou e o membro for Black/FC).

**Não canibaliza o Círculo global:** o Círculo dá franquia + desconto na compra +
conteúdo exclusivo global + Modo Discreto; o Fã-Clube é por-performer e ortogonal. Um é
a casa, o outro é a bolha da criadora. É aditivo — mais um caminho de receita.

**A isca:** o preço de entrada barato (em token) puxa o membro para a bolha da
performer, onde ela ganha com **gorjeta / PPV / live / chamada** (os canais de maior
valor). A assinatura é o anzol; o peixe grande é o resto.

---

## 7. Resumo das decisões travadas (01/10/2026)

| # | Decisão | Travado |
|---|---|---|
| 1 | Identidade: **híbrido** (clube + marketplace) | ✅ |
| 2 | Fã-Clube por performer; **acesso TOTAL** ao set | ✅ |
| 3 | **Recorrente/revogável puro** (sai → perde tudo do fã-clube; permanente só no avulso) | ✅ |
| 4 | Set de fã-clube = **balde novo**, separado de cofre e PPV | ✅ |
| 5 | Trilho **token**, **80/20**, entry types `spend_fanclub_sub` / `fanclub_sub_credit` | ✅ |
| 6 | **Preço único** por padrão (todos pagam o mesmo em token) | ✅ |
| 7 | **Desconto VIP opcional** da performer (off por padrão; trava público ≥ VIP) | ✅ |
| 8 | Tela de preço com **dois modos** + spread honesto | ✅ |
| 9 | Cobrança **auto-renova**; **avisa-e-pausa**; nunca BRL | ✅ |
| 10 | **Qualquer um assina** (free no preço público) | ✅ |
| 11 | Anonimato: FanAlias + selo de tier + faixa de baleia; **exceção §12 escopada + consentida** | ✅ |
| 12 | Piso/teto do preço, passo, faixas de baleia | ⏳ a calibrar (§8) |

---

## 8. Economia & simulação de margem

Cada token que o membro gasta no fã-clube paga **R$0,48 de payout** à performer
(0,80 × R$0,60) — exatamente como qualquer conteúdo 80/20. A margem da Limen depende de
quanto o membro pagou pelos tokens (compra a R$1,00; franquia a R$0,71–0,75 já paga na
mensalidade). **Nada aqui é custo novo** além do que a tabela de margem dos Círculos já
assumiu (pior caso: toda a franquia gasta em 80/20).

**Preço do fã-clube → o que a performer saca** (independe de quem paga; é sempre 80%):

| Preço (tokens) | Membro paga (~R$ à âncora) | Performer recebe | **Ela saca (R$)** |
|---:|---:|---:|---:|
| 20  | ~R$ 20  | 16 tk  | **R$ 9,60**  |
| 30  | ~R$ 30  | 24 tk  | **R$ 14,40** |
| 50  | ~R$ 50  | 40 tk  | **R$ 24,00** |
| 80  | ~R$ 80  | 64 tk  | **R$ 38,40** |
| 100 | ~R$ 100 | 80 tk  | **R$ 48,00** |
| 150 | ~R$ 150 | 120 tk | **R$ 72,00** |

**"Quero receber R$X líquido" → preço** (back-solve, ÷0,48, arredonda p/ cima no passo):

| Líquido desejado | Preço (tokens) | Membro paga (~R$) |
|---:|---:|---:|
| R$ 30  | 63  | ~R$ 63  |
| R$ 50  | 105 | ~R$ 105 |
| R$ 100 | 209 | ~R$ 209 |

**Piso/teto propostos (a confirmar):** piso **20 tokens** (abaixo disso não paga o
encanamento), passo **5**, teto **2.000 tokens/mês** (~R$2.000 de sticker; criadora
premium). Espelha o estilo de `config/custom_order.php`.

**Combustível do Black/FC:** FC tem **2.100 tokens/mês** de franquia (teto 8.000);
Black, **1.000** (teto 5.000). Esse saldo é o combustível natural do fã-clube — franquia
que o membro já pagou, convertida em recorrência para a criadora, **sem custo novo** e
sem furar o anonimato.

---

## 9. Modelo de dados (esboço — próxima fase, ainda não implementado)

```
fanclub_memberships            (ou fanclub_subscriptions)
  id
  performer_profile_id   FK cascade
  member_id              FK (nunca exposto à performer; serving por FanAlias)
  status                 active | paused | cancelled
  price_tokens           preço congelado no ciclo corrente
  price_tier             'public' | 'vip'  (qual preço se aplicou a este membro)
  current_period_end     data da próxima renovação
  started_at / cancelled_at
  timestamps
  UNIQUE(performer_profile_id, member_id)   // uma relação por par

performer_fanclub_settings     (config do fã-clube da performer)
  performer_profile_id   PK/FK
  is_open                bool (fã-clube ativo?)
  price_public_tokens
  vip_enabled            bool (desconto VIP ligado?)
  price_vip_tokens       nullable; se set, obriga <= price_public_tokens
  timestamps

performer_content.fanclub           // o "balde novo": flag/escopo de fã-clube
  (peça marcada como fã-clube não vaza na vitrine nem no unlock avulso)
```

- **Serving:** peça de fã-clube é servida se `membership.status = active` para o par
  (member, performer) **agora** — não por `content_unlocks`. Fã-clube inativo → trava.
- **Renovação:** job diário (idempotente) percorre `current_period_end <= hoje`, tenta
  debitar `spend_fanclub_sub` do saldo; sucesso → credita `fanclub_sub_credit` 80/20 e
  empurra `current_period_end`; saldo insuficiente → `paused` + aviso (§4).
- **Carteiras:** débito do membro e crédito da performer sob lock, **cada carteira
  isolada** (mesma disciplina anti-deadlock da encomenda/`CustomOrderService`).
- **Anonimato:** nenhum broadcast/listagem leva `member_id`; a performer vê a lista só
  por FanAlias + selo de tier + faixa (§5). `DeletionService` precisa limpar o lado do
  membro no hard delete (como `content_saves`).

> Tudo em §9 é **esboço de engenharia**, não decisão travada de implementação. O PR de
> dados/ledger virá depois, com revisão de segurança dedicada (é fluxo de dinheiro).

---

## 10. Guard-rails inegociáveis

- **Margem:** nenhuma assinatura pode furar os 25% / R$0,625 por token
  (`docs/ECONOMIA.md` §11). Como é 80/20, isso vale sozinho — mas qualquer "desconto
  bancado pela plataforma" (3ª via) **tem que recalcular a margem antes**.
- **Anonimato:** FanAlias + selo de tier + faixa de gasto. Nunca nome, nunca contagem
  abaixo do piso k-anon, nunca gasto exato. Exceção §12 **escopada e consentida**.
- **Legal (o "sugar camuflado"):** o trilho é **sempre conteúdo/interação dentro da
  plataforma**, pago em token. **Nunca** intermediação de encontro/acompanhante — isso
  é prostituição intermediada e derruba a casa (o geobloqueio FOSTA-SESTA e o filtro
  anti-contato existem por isto). Pode haver o *clima* (atenção, exclusividade, mimos
  digitais); não pode haver arranjo de encontro.
- **Ledger append-only / decimal exato / arredondamento único no payout:** o Fã-Clube
  obedece as invariantes de engenharia da economia do `CLAUDE.md` como qualquer canal.

---

## 11. Próximos passos

1. Confirmar os números de §8 (piso/teto/passo do preço; limites das faixas de baleia).
2. PR de dados + ledger (tabelas de §9, os dois entry types, o job de renovação) — com
   **revisão de segurança dedicada** (fluxo de dinheiro em custódia recorrente).
3. Telas: editor do fã-clube (performer, com a tela de preço de §3.1), o set de
   fã-clube, o fluxo de assinar/gerenciar (membro), a lista de assinantes com sinal de
   baleia (performer).
4. Registrar a feature em `docs/ARQUITETURA.md` e no mapa de features do `CLAUDE.md`
   quando entrar em código.
