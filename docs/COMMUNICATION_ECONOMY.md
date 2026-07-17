# Economia de Comunicação da Plataforma

> **Status:** Especificação · Referência para Sprints 3–7
> Complementa [SUBSCRIPTION_TIERS.md](SUBSCRIPTION_TIERS.md) e
> [INTEREST_SYSTEM_SPEC.md](INTEREST_SYSTEM_SPEC.md).

Este documento define como membros e performers se comunicam e como o dinheiro
(tokens) flui em cada interação. Todos os valores em **tokens**; conversão
tokens↔BRL e retenção da plataforma seguem as regras do ledger append-only.

## 1. Perfis de membro

| Perfil | Descrição |
|---|---|
| **Gratuito** | Vê o catálogo público; paga cada interação em tokens avulsos |
| **Assinante Select** | Chat livre + franquia mensal de tokens |
| **Assinante Black** | Tudo do Select + mais tokens, descontos e prioridade |
| **Assinante Prestige** | Tudo do Black + benefícios premium e concierge |

Detalhamento de preços e franquias em
[SUBSCRIPTION_TIERS.md](SUBSCRIPTION_TIERS.md).

## 2. Tabela de custos por funcionalidade

Custos de referência para o **membro gratuito**. Assinantes têm chat livre e
descontos (coluna à direita).

| Funcionalidade | Custo (gratuito) | Assinante |
|---|---|---|
| Mensagem de chat (texto) | 2 tokens/msg | **Grátis** (chat livre) |
| Gorjeta (tip) | Valor livre (mín. 5 tokens) | Igual |
| Desbloqueio de Interesse Controlado | 15 tokens | **Gratuito** (qualquer Círculo ativo); BLACK+ também tem prioridade no envio |
| Assistir live pública | Grátis | Grátis |
| Conteúdo desbloqueável (PPV) durante live | Preço definido pela performer | Igual |
| Live privada (pré-paga) | Por duração — ver §4 | 15% desc. (BLACK), 30min/mês incluída (PRESTIGE) |
| Videochamada 1:1 | Live privada + 30% — ver §5 | Descontos de assinatura aplicáveis |
| Conteúdo desbloqueável no perfil (PPV) | Preço definido pela performer | Igual |
| Assinatura de performer individual | Preço definido pela performer | Igual |
| Mimo recorrente (gorjeta mensal) | Valor definido pelo membro | Igual |

## 3. Lives públicas

Transmissão aberta a qualquer membro (inclusive gratuito).

- **Assistir:** grátis.
- **Gorjeta:** qualquer espectador envia tokens; aparece no feed da live.
- **Goals coletivos:** meta em tokens exibida na live (ex.: "meta 5.000 tokens").
  Gorjetas de todos os espectadores somam para a meta; ao atingir, a performer
  cumpre o combinado.
- **Conteúdo desbloqueável durante a live:** a performer pode ofertar PPV ao
  vivo (foto/vídeo/áudio) que o espectador desbloqueia por tokens sem sair da
  transmissão.

## 4. Lives privadas

Sessão 1:1 (ou 1:poucos) **pré-paga** em tokens.

- **Durações:** 10 / 20 / 30 minutos.
- **Pré-pago:** o membro reserva e os tokens ficam retidos antes do início.
- **Aceite em 2 minutos:** a performer tem 2 minutos para aceitar. Se **não
  aceitar**, os tokens são **devolvidos integralmente** ao membro (estorno via
  ledger, idempotente).
- **Comissão:** 15% (reduzida — ver §7).
- **Descontos de assinatura:** BLACK −15%; PRESTIGE inclui 1 live privada de
  30min/mês.

## 5. Videochamada 1:1

Chamada de vídeo bidirecional entre membro e performer.

- **Preço:** **30% mais cara** que a live privada de mesma duração.
- **Bidirecional:** ambos com câmera (diferente da live privada, que pode ser
  unidirecional).
- **Requisito:** **CPF confirmado** de ambos os lados (verificação reforçada,
  por ser interação com vídeo recíproco).
- **Comissão:** padrão 20% (não é live privada).

## 6. Assinatura de performer individual

Além dos planos da plataforma, o membro pode assinar uma **performer específica**.

- A **performer define o preço** da própria assinatura.
- A **plataforma retém 20%**; o restante credita a performer (via ledger).
- Dá acesso ao conteúdo exclusivo daquela performer conforme ela configurar
  (feed, PPV incluso, etc. — a detalhar na Sprint de conteúdo).

## 7. Tabela de comissões

| Interação | Retenção da plataforma |
|---|---|
| Gorjeta / tip | **20%** (padrão) |
| Mensagem paga | 20% |
| Conteúdo desbloqueável (PPV) | 20% |
| Assinatura de performer individual | 20% |
| Videochamada 1:1 | 20% |
| **Live privada** | **15%** (reduzida) |

> **Regra:** 20% é a retenção padrão da plataforma; **lives privadas** têm
> retenção reduzida de **15%** para incentivar o formato de maior valor. O split
> é sempre registrado no `token_ledger` no momento do gasto.

## 8. Mimo recorrente

Gorjeta **mensal automática** configurada pelo membro para uma performer.

- O membro define o valor e a performer alvo.
- Cobrança recorrente mensal (em tokens), enquanto o membro mantiver ativo.
- Cancelável a qualquer momento pelo membro.
- Split e retenção seguem a regra de gorjeta (20%).
- Cada cobrança é idempotente por ciclo (não duplica em reprocessamento).

## 9. Princípios transversais

- **Tokens são inteiros.** Nunca float.
- **Todo movimento é uma linha no ledger append-only.** Saldo é a soma; nunca
  `UPDATE saldo = saldo + x`.
- **Estornos são explícitos e idempotentes** (ex.: live privada não aceita em
  2min).
- **Retenção/split calculados no gasto** e persistidos junto ao movimento.
