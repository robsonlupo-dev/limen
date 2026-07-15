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
- ~~**Armadilha do auto-unlock.**~~ **Fechada** na aba "Interesses enviados"
  (`Web/Performer/SentInterestsController`). As linhas `suppressed` são mascaradas com o
  status que teriam se o membro não tivesse optado por sair — ver
  `PerformerInterest::scopeDisplayedAsUnlocked()` e a seção abaixo.

## Canal residual da máscara de opt-out (aceito, PR da aba de interesses enviados)

A aba de interesses enviados exibe cada linha `suppressed` com o status que ela teria sem o
opt-out: `unlocked` se o par já tinha um desbloqueio **anterior ao envio**, senão `sent`. A
reconstrução é ponto-no-tempo de propósito — mascarar com base em "já desbloqueou algum dia"
faria o status virar `unlocked` no instante em que o membro pagasse por um interesse antigo,
trocando um tell estático por um dinâmico (pior). Há teste travando as duas direções.

Sobra um canal estatístico, sem correção conhecida que não piore o resto:

> Par com um desbloqueio pago **e**, depois dele, uma linha ainda em `sent`.
> Para um membro sem opt-out esse estado exige que ele tenha **recusado uma revelação
> grátis** (desbloqueio prévio torna o próximo envio gratuito, mas o unlock continua sendo
> clique explícito por linha). Cada linha é plausível isolada; a combinação é anômala.

Explorar isso exige que a performer gaste cota, espere dois cooldowns de 30 dias e saiba
interpretar o padrão. **Decisão:** aceitar e documentar. Se o opt-out virar comum, reavaliar
junto do piso de anonimato acima — as duas questões têm a mesma raiz (o conjunto de
candidatos é pequeno demais para esconder um sinal).

**Consequência para o chat (Fase futura).** A linha mascarada como `unlocked` anuncia um
canal aberto que não existe: o membro optou por sair e não deve receber a primeira mensagem
grátis da performer. Quando o chat entrar, enviar para uma linha mascarada precisa parecer
bem-sucedido e não entregar nada — senão o opt-out vaza no envio, não na listagem.
