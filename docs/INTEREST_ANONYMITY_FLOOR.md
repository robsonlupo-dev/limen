# Interesse Controlado — piso de anonimato (questão em aberto)

> **Status:** decisão de produto pendente · **Origem:** PR #25 (`feat/interest-ui-and-payouts`)
> **Relacionado:** [INTEREST_SYSTEM_SPEC.md](INTEREST_SYSTEM_SPEC.md) seção 6 (anti-abuso)

Não é regressão nem bug de código. É um trade-off que a correção de segurança do PR #25
introduziu de propósito, e que precisa de decisão antes de virar dado em produção.

## O problema

Para fechar um oráculo de enumeração de membros, o envio de interesse passou a ser restrito
a **quem já segue a performer** (`app/Http/Requests/SendInterestRequest.php`,
`resolvedMember()`). Sem essa restrição, a performer varria o espaço de ids comparando 404
(id inexistente) com 422 (cooldown / daily_limit) e aprendia quem é membro ativo da
plataforma — de graça, sem gastar cota de envio, a ~14k ids/dia.

O efeito colateral: **todo interesse recebido vem obrigatoriamente de alguém que o membro já
segue.** Logo, o conjunto de candidatos ao remetente é exatamente a lista de follows do
membro. Quando essa lista é pequena, o desbloqueio de 15 tokens deixa de comprar informação:

| Follows do membro | Chance de acertar o remetente sem pagar |
| --- | --- |
| 1 | 100% |
| 2 | 50% |
| 3 | 33% |
| 10 | 10% |

Isso morde justamente o **membro novo**, que tem poucos follows — e é quem mais interessa
converter na primeira compra de tokens.

## O que isto não é

Não é vazamento de payload. A revisão de segurança sondou as props do Inertia de `/painel` e
de `/interesses`:

- nada liga `following` a `interests` no payload;
- a ordenação da lista de follows (`is_live`, `followers_count`) não sofre influência de quem
  enviou interesse — o remetente não sobe na lista por ser remetente;
- a identidade de um interesse bloqueado nunca sai do servidor (`performer => null`).

A inferência é **estrutural**, não um defeito de implementação. Nenhuma mudança de código nas
telas atuais a elimina.

## Opções

1. **Piso de anonimato por tamanho do conjunto.** Só entregar interesse a membros com pelo
   menos N follows (N a definir; 5 é chute inicial). Simples, mas segura interesse de membro
   novo — exatamente quem se quer engajar.
2. **Reabrir o envio a não-seguidores, com resposta uniforme.** Devolve alcance, mas exige
   eliminar o oráculo de outro jeito: mesma resposta para alvo inválido e desconhecido,
   resolvida **depois** de cooldown/limite. Mais superfície de risco, e reabre a questão de
   como a performer descobre membros que não a seguem — hoje ela não tem tela para isso.
3. **Ruído no conjunto de candidatos.** Não mostrar contagem exata de interesses bloqueados
   (faixas: "1+", "vários"). Mitiga pouco: com 1 follow, qualquer sinal > 0 entrega.
4. **Aceitar e documentar.** Apostar que o conjunto de follows cresce rápido.

## Sugestão

Decidir com dado. Antes de escolher, medir a distribuição de follows por membro no banco:

```sql
SELECT follows_por_membro, COUNT(*) AS membros FROM (
    SELECT user_id, COUNT(*) AS follows_por_membro FROM follows GROUP BY user_id
) t GROUP BY follows_por_membro ORDER BY follows_por_membro;
```

Mediana alta torna a opção 4 defensável; mediana 1–2 torna a opção 1 necessária.

## Follow-ups vizinhos (mesma revisão, ainda de pé)

- **Pseudônimos conflitantes.** O painel do performer mascara gorjetas como `Fã #0042`
  (`consumer_id % 10000`, `Web/Performer/DashboardController`), enquanto a lista de
  seguidores mostra `Membro #42` com o id cru (`Web/Performer/FollowersController`). O mesmo
  membro é correlacionável entre as duas telas, o que anula o mascaramento das gorjetas. Na
  prática o `% 10000` já era pouco efetivo — o id precisa ir no POST do interesse de
  qualquer forma. Convém unificar.
- **Armadilha do auto-unlock.** Hoje o opt-out vence o auto-unlock e isso é inobservável.
  Quando existir uma tela de "quem revelou você" para a performer, as linhas `suppressed`
  vão precisar ser mascaradas ali também — senão o opt-out vaza retroativamente.
