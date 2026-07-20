# Security Issues — registro para abertura manual

Achados de revisão de segurança que ainda não viraram issue no GitHub (não há
`gh` CLI neste ambiente). Cada seção abaixo é o corpo de uma issue a abrir em
https://github.com/robsonlupo-dev/limen/issues/new — apague a seção quando a
issue existir, deixando o link no lugar.

---

## Issue: Correlação de pseudônimos Membro # ↔ Fã #

**Severidade:** 🟡 Médio-Alto
**Componente:** DashboardController.php:65 + FollowersController
**Branch:** main (pré-existente, não introduzido pela feat/anonymity-floor)

**Descrição:**
O dashboard de gorjetas da performer exibe o remetente como
`'Fã #' . ($tip->consumer_id % 10000)`, enquanto a lista de seguidores
exibe `'Membro #' . $user_id`.

Como ambos usam o mesmo espaço de ID (`user_id`), a correlação é
determinística:
- Membro #12345 na lista de seguidores
- Fã #2345 no dashboard de gorjetas (12345 % 10000 = 2345)

Um membro abaixo do piso de anonimato que envia uma gorjeta
entrega 4 dígitos do próprio ID, permitindo correlação cruzada.
A lista de gorjetas não passa por nenhum piso de anonimato.

**Impacto:**
Membro discreto (Black/FC com Modo Discreto ativo) ou membro
abaixo do piso pode ser identificado pela performer ao combinar
os dois pseudônimos.

**Mitigação proposta:**
Substituir `consumer_id % 10000` por um identificador opaco e
estável por performer — ex: `hash(consumer_id + performer_id + salt)`
truncado para 4 caracteres alfanuméricos. Isso garante que o mesmo
membro apareça com pseudônimos diferentes para performers diferentes,
impossibilitando correlação cruzada.

**Pré-requisito:** Decidir se o pseudônimo deve ser estável
(mesmo membro sempre é "Fã #AB3F" para a mesma performer)
ou rotativo (muda a cada gorjeta).

**Sprint sugerido:** Sprint 6

### Notas de implementação (levantadas na revisão)

- **4 caracteres alfanuméricos são ~1,68M combinações, mas o espaço que importa
  é o de seguidores da performer.** Com poucas centenas de membros a colisão é
  rara; o ponto do truncamento é impedir a correlação, não garantir unicidade.
  A UI não deve tratar o pseudônimo como chave.
- **O salt precisa sair do `.env`** (nunca versionado, ver CLAUDE.md §5). Trocar
  o salt rotaciona todos os pseudônimos de uma vez — aceitável, mas quebra o
  histórico que a performer via.
- **Se o pseudônimo for estável, ele é um identificador persistente por
  performer:** ela consegue contar "quantas gorjetas o Fã #AB3F já mandou". Isso
  provavelmente é desejável (é o produto), mas é uma decisão de privacidade, não
  só de implementação — é o que o pré-requisito acima está perguntando.
- **`Membro #` na lista de seguidores tem o mesmo problema de fundo:** expõe o
  `user_id` cru. Trocar só o lado das gorjetas fecha a correlação entre as duas
  telas, mas o `user_id` continua vazando pela lista de seguidores para qualquer
  outra superfície futura que use o mesmo espaço. Vale considerar o pseudônimo
  opaco como padrão de toda exposição de membro à performer.
- **O `member_id` cru ainda precisa trafegar no POST do Interesse Controlado**
  (`SendInterestRequest`), então a troca é de exibição — o mapeamento
  pseudônimo→id fica no servidor.
