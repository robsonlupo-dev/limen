# Programa de Indicação ("Indique e Ganhe") — desenho (proposta do CTO)

> **Status:** desenho aprovado pelo PO em 25/09/2026. Nenhum código de produto
> ainda — este documento é o ponto de partida para abrir `feat/referral-program`.
> Decisões abaixo foram travadas em conversa com o PO e não devem ser reabertas
> sem justificativa nova.

## 1. O que é

Quem já está na Limen (membro ou performer) indica alguém para se cadastrar.
Quando essa indicação **converte de verdade** (não é só cadastro — é gerar
receita real para a plataforma), os dois lados ganham tokens de bônus.

| Quem indicou | Quem converteu | Indicador ganha | Indicado ganha |
|---|---|---|---|
| Membro ou performer | Membro fez a 1ª compra confirmada | 10 tokens | 5 tokens |
| Membro ou performer | Performer aprovou KYC + 1º ganho de terceiro | **40 tokens** | **25 tokens** |

**Bônus é sempre não-sacável** (crédito de plataforma, nunca vira saque em
reais) e **fixo por conversão qualificada** — não é percentual, não é
recorrente, não some se a pessoa indicar de novo.

## 2. Atribuição

- `users.referral_code` — código único gerado no cadastro de cada usuário
  (ex.: `LM-7F3K2`), existe para todo mundo desde o dia 1, ativo ou não o
  programa.
- Link de indicação: `https://thelimen.com.br/cadastro?ref=LM-7F3K2`. O
  parâmetro `ref` é capturado e guardado (cookie de curta duração) até o
  cadastro ser concluído.
- Campo "Código de indicação (opcional)" no formulário de cadastro — cobre
  quem recebeu o código por fora (print, mensagem, boca a boca) e não clicou
  no link.
- `users.referred_by_user_id` é **gravado uma única vez, no momento do
  cadastro, e nunca mais muda** (imutável). `users.referred_via` registra
  `link` ou `code` só para métricas.
- Convite por e-mail com token assinado (a pessoa recebe um link
  personalizado por e-mail em vez de copiar/colar) fica para **Fase 2** — fora
  do MVP.

### 2.1 UI mínima do MVP — "meu link"

Uma tela simples, sem dashboard: mostra o link e o código do usuário, com
botão de copiar (e compartilhar, se a plataforma já tiver esse componente).
**Sem lista de indicados, sem contador, sem status de conversão** — isso é
Fase 2. O usuário só sabe que ganhou algo quando o e-mail de recompensa chega
(§7).

## 3. As duas conversões qualificadas

O cadastro em si **nunca** gera recompensa — só cadastro é grátis e fácil de
fraudar. A recompensa só existe quando a pessoa indicada gera receita real.

### 3.A — Indicado é (ou virou) membro

Conversão = **1ª compra de pacote de tokens confirmada** pelo Asaas
(`PAYMENT_CONFIRMED`/`PAYMENT_RECEIVED`), mesmo caminho que
`PaymentService::confirmPayment` já usa. Qualquer pacote conta (mesmo o
Starter).

### 3.B — Indicado é (ou virou) performer

Conversão = **KYC aprovado** (`performer_profiles.is_verified = true`) **e**
**primeiro ganho vindo de um pagador terceiro** — ou seja, um membro que não é
o próprio indicador nem uma segunda conta da própria indicada (checagem de
CPF, instrumento de pagamento e fingerprint de dispositivo).

> **Por que exigir um terceiro:** sem essa trava, o ataque mais óbvio é
> "indico uma conta fake e eu mesma pago para ela" para fechar o ciclo e
> embolsar o bônus sem nenhuma receita real ter entrado na plataforma. Exigir
> que o primeiro dinheiro venha de alguém fora do laço indicador↔indicada
> fecha essa porta. Essa é a mesma lógica que justifica manter o bônus
> **não-sacável** (§8) — ver a análise completa abaixo.

## 4. Ciclo de vida da recompensa

```
pending → qualified → hold (14 dias) → rewarded
                                     ↘ clawed_back (se a compra/ganho de base for estornado)
```

- **pending:** indicação registrada no cadastro, aguardando conversão.
- **qualified:** conversão (3.A ou 3.B) aconteceu.
- **hold (14 dias):** cobre a janela de contestação PIX (MED) e chargeback
  antecipado. Nenhum token é creditado ainda.
- **rewarded:** hold expirou sem estorno → crédito automático nos dois lados
  (indicador e indicado), sem necessidade de resgate manual (diferente do
  modelo Wise, onde o indicador precisa resgatar o bônus manualmente em até 1
  ano — aqui é automático).
- **clawed_back:** se a compra/ganho de base for estornado **depois** do
  crédito, o bônus é revertido via `referral_bonus_reversal` (nova entrada no
  ledger, nunca edição da entrada original — ledger é append-only).

## 5. Modelagem contábil

- Dois `entry_type` novos no `token_ledger`: `referral_bonus` e
  `referral_bonus_reversal`, adicionados por migration incremental (nunca
  editando o enum existente destrutivamente — mesmo padrão de
  `2026_08_09_000003_add_gift_entry_types_to_token_ledger.php`).
- **Fora do allowlist de payout** (`config('monetization.payout.earning_entry_types')`)
  — não-sacável **por construção**, não por uma checagem extra em runtime.
  `PayoutService` simplesmente nunca soma essas linhas.
- **Respeita o teto de acúmulo** (5000 tokens padrão / 8000 FC —
  `docs/ECONOMIA.md` §11): é dinheiro novo entrando na conta, mesma classe dos
  outros bônus da plataforma.
- `token_wallets.balance` é atualizado do jeito de sempre: `UPDATE` de valor
  absoluto sob `lockForUpdate`, na mesma transação da linha do ledger.

## 6. Modelo de dados

```
referrals
  id
  referrer_user_id        (quem indicou)
  referred_user_id         UNIQUE  (quem foi indicado — 1 indicador só por pessoa)
  referred_role_at_signup  enum('member','performer')
  status                   enum('pending','qualified','clawed_back')
  qualified_at             nullable timestamp
  hold_until               nullable timestamp
  rejection_reason         nullable string
  created_at / updated_at

referral_rewards
  id
  referral_id              FK → referrals
  beneficiary_user_id       (quem recebe ESTE crédito — indicador OU indicado)
  role                      enum('referrer','referred')
  trigger                   enum('member_purchase','performer_first_earning')
  amount                    int (tokens)
  ledger_id                 FK → token_ledger (a entrada referral_bonus real)
  status                    enum('pending','rewarded','reversed')
  created_at / updated_at
  UNIQUE(referral_id, role) -- garante que cada lado só é premiado 1x por indicação
```

## 7. Notificações — e-mail e extrato

### 7.1 E-mail de confirmação de cadastro via indicação

Quando alguém se cadastra usando `?ref=` ou o campo de código, o **indicador**
recebe um e-mail curto avisando que a indicação foi registrada e explicando
que a recompensa só chega quando a pessoa indicada gerar a conversão (compra
ou primeiro ganho) — para não criar expectativa de bônus imediato.

### 7.2 E-mail de recompensa creditada (os dois lados)

Quando o bônus sai de `qualified` para `rewarded` (após o hold), **ambos**
recebem e-mail:

- **Indicador:** "Você ganhou N tokens porque [pessoa indicada] fez sua
  primeira compra / começou a receber na Limen."
- **Indicado:** "Você ganhou N tokens de boas-vindas por ter se cadastrado com
  um código de indicação."

### 7.3 Identificação no e-mail e no extrato — regra de privacidade

| Quem é identificado | Como aparece |
|---|---|
| Performer (indicador ou indicada) | `stage_name` (já é público) |
| Membro com apelido definido (`feat/member-nickname`) | apelido |
| Membro sem apelido | frase genérica — "um membro que você indicou" — **sem nome real, e-mail ou CPF** |

Mesma disciplina de anonimato já usada em `FanAlias` e no resto da
plataforma: nunca vaza dado de identificação real entre usuários.

No **extrato** (histórico de tokens), a entrada `referral_bonus` usa o mesmo
gerador server-side de rótulos (`App\Support\LedgerEntryLabel` — CLAUDE.md:
rótulo sempre vem do servidor, nunca de mapa no cliente), com o texto
"Bônus de indicação — [pessoa]" seguindo a regra da tabela acima.

### 7.4 O que NÃO enviamos

Nenhum e-mail ou linha de extrato revela: nome real, e-mail, CPF, valor
pago/recebido pela pessoa indicada, ou qualquer dado que permita ao indicador
reconstruir o comportamento financeiro de quem indicou.

## 8. Por que o bônus é sempre não-sacável (inclusive para performer)

Essa pergunta foi levantada explicitamente no desenho: já que o bônus de uma
performer só pode ser gasto em Boost (100% Limen, sem contraparte recebendo
dinheiro), por que não permitir que ela **converta esse saldo em reais e
saque**, além de gastar em Boost?

**Resposta: não. Essa trava é estrutural, não uma limitação técnica.**
Tecnicamente seria simples adicionar um `entry_type` ao allowlist de payout —
mas isso reabre exatamente o ataque que a exigência de "terceiro" (§3.B) foi
desenhada para fechar:

1. Performer A se cadastra.
2. A "indica" a Performer B (conta fake ou cúmplice).
3. B passa no KYC (documento pode até ser real) e gera o primeiro ganho — o
   requisito de terceiro ainda barra o **primeiro elo do ciclo**, mas o bônus
   de **A** por essa conversão não depende de B faturar de verdade depois, só
   da conversão pontual ter acontecido.
4. Repita com N contas de indicação. Cada conversão dá 40 tokens sacáveis
   para A, praticamente sem custo de aquisição real — vira uma máquina de
   emitir dinheiro do zero, limitada só por quantas contas dá para abrir com
   KYC.

Hoje isso não funciona porque o token trava em Boost — não tem para onde
vazar. É a diferença entre "a Limen emitiu tokens que só valem algo dentro da
própria casa" e "a Limen emitiu reais que saem via Asaas para uma conta
bancária, sem receita real equivalente ter entrado". A primeira é inofensiva
mesmo em escala; a segunda é uma impressora de dinheiro cujo único limite é
"quantas contas dá para abrir".

**O valor ainda chega à performer, só que por um caminho seguro:** bônus
gasto em Boost libera o token *ganho* (sacável) dela para outros fins —
inclusive saque — em vez de ela precisar gastá-lo em Boost do próprio bolso.
O efeito prático é "mais dinheiro sacável no fim do mês", sem nunca existir
uma linha no ledger dizendo "bônus não-ganho virou saque".

### 8.1 Tabela de ataques × mitigação

| Ataque | Mitigação |
|---|---|
| Auto-indicação (usuário indica a si mesmo com 2ª conta) | `referred_user_id` único + checagem de CPF/dispositivo/instrumento de pagamento no momento da conversão |
| Indicador fecha o ciclo sozinho pagando pela conta indicada | Exigência de **terceiro pagador** na conversão de performer (§3.B) |
| Fábrica de contas para gerar bônus em cadeia | Bônus **sempre não-sacável** (§8) — mesmo em escala, não há extração de caixa real |
| Estorno/chargeback depois do bônus já creditado | **Hold de 14 dias** antes de creditar + `referral_bonus_reversal` se estornar depois |
| Extração de valor via bônus acumulado | Bônus respeita o **teto de acúmulo** (§5) — não dá para empilhar infinitamente |
| Vazamento de dado pessoal entre indicador/indicado | Identificação só por `stage_name`/apelido, nunca nome real/e-mail/CPF (§7.3) |

## 9. Fora do escopo do MVP

- Dashboard completo de indicações (lista, contador, status de cada uma).
- Convite por e-mail com token assinado.
- Modelo percentual ou recorrente (ex.: % sobre compras futuras do indicado).
- Qualquer caminho de conversão do bônus de performer em saque.

## 10. Config proposta (`config/referral.php`)

```php
return [
    'enabled' => env('REFERRAL_PROGRAM_ENABLED', false),

    'hold_days' => env('REFERRAL_HOLD_DAYS', 14),

    'rewards' => [
        'member_purchase' => [
            'referrer' => 10,
            'referred' => 5,
        ],
        'performer_first_earning' => [
            'referrer' => 40,
            'referred' => 25,
        ],
    ],
];
```

Nenhum valor hardcoded no código — tudo lido daqui, para permitir ajuste sem
deploy de lógica.

## 11. Checklist antes de construir

- [x] Bônus não-sacável — confirmado com o PO.
- [x] Modelo fixo por conversão (não %, não recorrente) — confirmado.
- [x] Os dois lados ganham — confirmado.
- [x] Atribuição MVP: link `?ref=` + campo de código — confirmado.
- [x] UI mínima: só link/código + copiar — confirmado.
- [x] Valores travados: membro 10/5, performer 40/25 — confirmado 25/09.
- [ ] Migrations: enum do ledger (`referral_bonus`, `referral_bonus_reversal`),
      tabelas `referrals` e `referral_rewards`, colunas em `users`.
- [ ] `ReferralService` (atribuição no cadastro, detecção de conversão,
      hold, crédito, clawback).
- [ ] Comando agendado `referral:process-holds` (roda o hold → rewarded).
- [ ] Entradas em `App\Support\LedgerEntryLabel`.
- [ ] Mailables para os dois e-mails (§7.1, §7.2).
- [ ] Tela "meu link" (mínima, §2.1).
- [ ] Revisão de segurança obrigatória (subagent) antes do PR de código.
- [ ] Testes Pest cobrindo: atribuição imutável, as duas conversões, hold,
      clawback, teto de acúmulo, exclusão do payout allowlist, privacidade do
      extrato/e-mail.
- [ ] Registrar em `docs/ARQUITETURA.md` e `CLAUDE.md`.
