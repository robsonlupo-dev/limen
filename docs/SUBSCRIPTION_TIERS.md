# Arquitetura de Planos de Assinatura

> **Status:** Especificação · Referência para a Sprint de monetização
> Complementa [COMMUNICATION_ECONOMY.md](COMMUNICATION_ECONOMY.md).

Quatro planos: um gratuito e três pagos. Preços em BRL/mês; franquias em tokens.

## 1. Planos

### FREE — R$ 0
- Acesso ao **catálogo público** de performers.
- **Sem interação** (sem chat, sem lives privadas, sem desbloqueios inclusos).
- Pode comprar tokens avulsos via PIX e pagar interações unitárias
  (ver [COMMUNICATION_ECONOMY.md](COMMUNICATION_ECONOMY.md)).

### SELECT — R$ 149,90/mês
- **Chat livre** (mensagens de texto sem custo por mensagem).
- **100 tokens/mês** de franquia.
- Acesso a **lives públicas**.
- **Badge Select** no perfil.

### BLACK — R$ 349,90/mês
Tudo do **Select**, mais:
- **400 tokens/mês** de franquia.
- **Badge dourado**.
- **Prioridade no Interesse Controlado** (envios/recebimentos priorizados —
  ver [INTEREST_SYSTEM_SPEC.md](INTEREST_SYSTEM_SPEC.md)).
- **15% de desconto** em lives privadas.
- **Acesso antecipado de 48h** a novas performers.

### PRESTIGE — R$ 799,90/mês
Tudo do **Black**, mais:
- **1.200 tokens/mês** de franquia.
- **Badge platina**.
- **1 live privada de 30 min/mês incluída**.
- **Modo Discrição avançado** (privacidade reforçada).
- **Mensagem direta sem desbloqueio** (dispensa o desbloqueio de 15 tokens do
  Interesse Controlado).
- **Gerente de conta via WhatsApp** (concierge).

### Comparativo

| Recurso | FREE | SELECT | BLACK | PRESTIGE |
|---|:---:|:---:|:---:|:---:|
| Preço/mês | R$ 0 | R$ 149,90 | R$ 349,90 | R$ 799,90 |
| Tokens/mês | — | 100 | 400 | 1.200 |
| Chat livre | — | ✓ | ✓ | ✓ |
| Lives públicas | ✓ | ✓ | ✓ | ✓ |
| Badge | — | Select | Dourado | Platina |
| Prioridade Interesse Controlado | — | — | ✓ | ✓ |
| Desconto lives privadas | — | — | 15% | 15% |
| Acesso antecipado a novas performers | — | — | 48h | 48h |
| Live privada 30min inclusa | — | — | — | 1/mês |
| Modo Discrição avançado | — | — | — | ✓ |
| Mensagem direta sem desbloqueio | — | — | — | ✓ |
| Gerente de conta WhatsApp | — | — | — | ✓ |

## 2. Modalidades de pagamento

Descontos sobre o preço cheio conforme o compromisso de permanência:

| Modalidade | Desconto | Multiplicador |
|---|:---:|:---:|
| Mensal | — (cheio) | 1,00 |
| Trimestral | −15% | 0,85 |
| Semestral | −25% | 0,75 |
| Anual | −35% | 0,65 |

**PIX:** **−5% adicional** sobre qualquer modalidade (cumulativo).
Ex.: Anual no PIX = 0,65 × 0,95 = **0,6175** do preço cheio.

### Preço efetivo mensal por plano e modalidade

Valores por mês (BRL), já com o desconto da modalidade. Coluna "+PIX" aplica os
−5% adicionais.

| Plano | Mensal | Trimestral | Trim.+PIX | Semestral | Sem.+PIX | Anual | Anual+PIX |
|---|---:|---:|---:|---:|---:|---:|---:|
| SELECT | 149,90 | 127,42 | 121,04 | 112,43 | 106,80 | 97,44 | 92,56 |
| BLACK | 349,90 | 297,42 | 282,54 | 262,43 | 249,30 | 227,44 | 216,06 |
| PRESTIGE | 799,90 | 679,92 | 645,92 | 599,93 | 569,93 | 519,94 | 493,94 |

> O desconto é maior quanto maior o compromisso — troca receita unitária por
> previsibilidade de caixa (LTV e retenção).

## 3. Impacto na receita — cenário com 1.000 membros

> **Ilustrativo.** Assume uma distribuição de mix e é usado para dimensionar
> receita, não é projeção comprometida.

### Premissas de distribuição (1.000 membros)

| Plano | Membros | % |
|---|---:|---:|
| FREE | 600 | 60% |
| SELECT | 250 | 25% |
| BLACK | 120 | 12% |
| PRESTIGE | 30 | 3% |
| **Total** | **1.000** | **100%** |

### Cenário A — todos no plano mensal (cheio)

| Plano | Membros | Preço/mês | Receita/mês |
|---|---:|---:|---:|
| SELECT | 250 | 149,90 | 37.475,00 |
| BLACK | 120 | 349,90 | 41.988,00 |
| PRESTIGE | 30 | 799,90 | 23.997,00 |
| **MRR** | | | **103.460,00** |

**ARR (12×):** R$ 1.241.520,00

### Cenário B — mix de modalidades (blended, com PIX)

Assume, dentro de cada plano: 40% mensal, 25% trimestral, 20% semestral, 15%
anual (fator de modalidade = 0,86); e que **60%** dos pagamentos usam PIX (−5%,
fator PIX = 0,97). Fator de receita médio resultante ≈ **0,834** do preço cheio.

| Métrica | Valor |
|---|---:|
| MRR equivalente (0,834 × 103.460) | ≈ **R$ 86.286,00** |
| ARR equivalente | ≈ **R$ 1.035.432,00** |

> O Cenário B rende menos MRR nominal, mas antecipa caixa (trimestral/semestral/
> anual pagos à vista) e melhora retenção — o desconto é o preço dessa
> antecipação.

### Sensibilidade (MRR cheio, variando o mix de assinantes)

| Cenário | SELECT | BLACK | PRESTIGE | MRR (cheio) |
|---|---:|---:|---:|---:|
| Conservador | 150 | 60 | 15 | 55.477,50 |
| Base | 250 | 120 | 30 | 103.460,00 |
| Otimista | 350 | 200 | 60 | 170.439,00 |

## 4. Regras transversais

- **Franquia de tokens** creditada no ciclo via ledger append-only; política de
  acúmulo/expiração a definir na Sprint de monetização (proposta: não acumula
  entre ciclos).
- **Upgrade/downgrade** com proração no ciclo (a especificar).
- **Cobrança recorrente** idempotente por ciclo — reprocessar nunca duplica.
- Benefícios financeiros de programas promocionais têm teto de duração (ver
  regras do programa de fundadores); **status social** (badges) pode ser
  permanente.
