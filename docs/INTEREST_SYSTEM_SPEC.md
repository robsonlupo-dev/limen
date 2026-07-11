# Sistema de Interesse Controlado (Performer → Membro)

> **Status:** Especificação · **Implementação prevista:** Sprint 3
> **Pré-requisitos:** catálogo público de performers + sistema de mensagens

## 1. Objetivo

Permitir que uma **performer** sinalize interesse em um **membro** de forma
controlada e sem ruído, invertendo o fluxo tradicional (em que o membro aborda a
performer). O membro decide se quer desbloquear e iniciar contato, pagando em
tokens. O modelo cria escassez, protege a performer de spam e monetiza a
descoberta.

## 2. Modelo escolhido

**Performer envia interesse (sem texto). Membro paga tokens para desbloquear
quem enviou.**

- O interesse é um sinal binário — **não carrega mensagem, foto ou texto livre**.
  Isso elimina o vetor de assédio/spam e mantém o custo de moderação baixo.
- O membro vê que "alguém demonstrou interesse" e, ao **desbloquear** (pagando
  tokens), descobre **qual performer** e abre o canal de conversa.

### Por que este modelo (e não os alternativos)

| Modelo | Descartado porque |
|---|---|
| Performer manda mensagem livre | Vetor de spam/assédio; alto custo de moderação |
| Membro paga para *enviar* interesse | Inverte o incentivo; performer vira alvo de investidas pagas |
| Interesse mútuo grátis (tipo "match") | Sem monetização; enche a caixa da performer |

## 3. Parâmetros

| Parâmetro | Valor | Observação |
|---|---|---|
| Custo de desbloqueio | **15 tokens** | Pago pelo membro, uma vez por performer |
| Limite de envio | **5 interesses/dia por performer** | Sobe conforme o tier da performer |
| Anti-spam (cooldown) | **30 dias** | Mesma performer não pode enviar interesse ao mesmo membro novamente antes de 30 dias |
| Opt-out do membro | **Sim** | Membro pode desativar o recebimento de interesses nas configurações |

### Limite por tier da performer

O teto diário de 5 é o piso. Tiers superiores (definidos no programa de
performers) elevam o limite — a tabela exata será fixada na Sprint 3 junto ao
sistema de tiers de performer. Regra: o limite **nunca** diminui com o tier.

## 4. Fluxo

```
Performer                          Plataforma                        Membro
   │                                   │                               │
   │ envia interesse (sem texto) ─────▶│                               │
   │  · checa limite diário            │                               │
   │  · checa cooldown 30d p/ membro   │                               │
   │  · checa opt-out do membro        │                               │
   │                                   │── notifica "alguém tem        │
   │                                   │   interesse em você" ────────▶│
   │                                   │                               │
   │                                   │◀──── desbloquear (15 tokens) ─│
   │                                   │  · debita 15 tokens (ledger)  │
   │                                   │  · revela a performer         │
   │◀──── canal de conversa aberto ────┼──── canal aberto ────────────▶│
   │ 1ª mensagem GRÁTIS ──────────────▶│──────────────────────────────▶│
   │                                   │◀──── responde (custo em       │
   │                                   │      tokens se não assinante) │
```

## 5. Regras da conversa pós-desbloqueio

- Após o desbloqueio, o **canal fica aberto** entre as duas partes.
- A **performer envia a primeira mensagem gratuitamente** (incentivo ao contato
  real após o membro ter pago o desbloqueio).
- O **membro responde pagando tokens por mensagem** — **exceto** se for
  assinante (planos SELECT/BLACK/PRESTIGE incluem chat livre; ver
  [SUBSCRIPTION_TIERS.md](SUBSCRIPTION_TIERS.md) e
  [COMMUNICATION_ECONOMY.md](COMMUNICATION_ECONOMY.md)).

## 6. Anti-abuso

- **Cooldown de 30 dias:** uma performer não pode enviar interesse ao mesmo
  membro mais de uma vez a cada 30 dias, mesmo que o membro não tenha
  desbloqueado. Evita "cutucadas" repetidas.
- **Limite diário:** teto de envios por performer por dia (5, escalando por
  tier).
- **Opt-out:** o membro pode desligar o recebimento de interesses; performers
  não conseguem enviar para quem optou por sair (e não recebem sinal de que o
  opt-out existe, para não vazar comportamento).
- **Idempotência do débito:** o desbloqueio debita 15 tokens exatamente uma vez;
  reprocessar a ação nunca cobra em dobro (regra do ledger append-only).

## 7. Modelo de dados (esboço — detalhar na Sprint 3)

- `performer_interests`: `performer_id`, `member_id`, `sent_at`,
  `unlocked_at (nullable)`, `unlock_ledger_entry_id (nullable)`.
  Índice único parcial por `(performer_id, member_id)` dentro da janela de
  cooldown.
- Débito de tokens **sempre** via `token_ledger` (append-only). Nunca
  `UPDATE saldo`.
- Configuração de opt-out em preferências do membro.

## 8. Dependências e sequência

1. **Catálogo público de performers** (identidade/descoberta).
2. **Sistema de mensagens** (canal de conversa).
3. **Este sistema** (interesse + desbloqueio) — Sprint 3.

## 9. Questões em aberto (resolver na Sprint 3)

- Tabela definitiva de limite diário por tier de performer.
- O desbloqueio expira? (proposta: não expira; é permanente por par.)
- Reembolso se a performer nunca responder após o desbloqueio? (proposta:
  primeira mensagem grátis da performer é o compromisso; sem reembolso se ela
  não escrever — reavaliar com dados.)
