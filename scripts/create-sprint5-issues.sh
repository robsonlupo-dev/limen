#!/usr/bin/env bash
# Abre as 4 issues de follow-up do chat (feat/chat-reverb).
# Rodar da raiz do repo limen, com o gh CLI autenticado.
# Corpos em aspas SIMPLES de propósito: preservam backticks e $ do markdown
# sem o shell tentar expandi-los.
set -euo pipefail

# --- Milestone ----------------------------------------------------------------
# Cria a milestone antes das issues. `|| true` para não abortar o script (set -e)
# caso a milestone já exista (o gh api devolve 422 nesse caso).
gh api repos/:owner/:repo/milestones \
  -f title="Sprint 5" \
  -f description="Chat, Phase B (Black/FC), tokenização client-side PCI" || true

# --- Issue 1 ------------------------------------------------------------------
gh issue create \
  --title "Idempotência não-permanente no chat_access permite recobrança em replay tardio" \
  --label "bug" \
  --assignee @me \
  --milestone "Sprint 5" \
  --body '## Contexto
A compra/renovação de acesso ao chat (`ChatAccessService::openOrRenew`) é idempotente por `idempotency_key`, mas a chave é guardada apenas como **última chave** na linha `chat_access` (`last_idempotency_key`), e é **sobrescrita** a cada open/renew.

- Guard: `app/Services/ChatAccessService.php:77` — `if ($access && $access->last_idempotency_key === $idempotencyKey) return $access;`
- Sobrescrita: `app/Services/ChatAccessService.php:120` e `:130`

Protege o double-submit imediato (mesma chave), mas **não** protege o replay de uma chave antiga depois de uma renovação.

## Impacto
Sequência `open(A)` → `renew(B)` → **replay tardio de A** (ex.: retry de rede do request original): como `last_idempotency_key` agora é `B`, a chave `A` não bate mais, o serviço **debita 50 tokens de novo** e estende a janela.

Contraria o princípio nº 3 do `CLAUDE.md` ("Reprocessar nunca duplica saldo"). Baixa probabilidade, mas é cobrança dupla real — e o projeto tem histórico sensível a double-pay.

## Solução sugerida
Tornar o dedup **permanente**, não "última chave":
- Persistir a `idempotency_key` de cada movimento no `token_ledger` (migration) e, antes de debitar, checar se já existe lançamento `spend_chat_access` com aquela chave → se sim, tratar como replay (retorna estado atual, não cobra); **ou**
- Tabela dedicada de chaves de idempotência já processadas (índice único), consultada antes do débito.

Requer migration — ficou fora do escopo da finalização do modelo.'

# --- Issue 2 ------------------------------------------------------------------
gh issue create \
  --title "Opt-out de Interesse não congela conversa de chat já aberta" \
  --label "enhancement" \
  --assignee @me \
  --milestone "Sprint 5" \
  --body '## Contexto
A máscara de opt-out (INTEREST_ANONYMITY_FLOOR — enviar para membro que optou por sair precisa parecer sucesso sem entregar nada) é aplicada **somente no gatilho do Interesse**, em `ChatService::performerMessageFromInterest`.

Uma vez que a conversa está aberta, as mensagens seguintes da performer entram por `ChatController::storeMessage` → `ChatService::sendMessage`, que **não** consulta o status de supressão do Interesse.

## Impacto
Se o produto pretende que o opt-out **silencie o chat contínuo**, esse caminho **entrega mesmo assim**.

Importante: isto **NÃO vaza o opt-out** para a performer — a resposta continua uniforme (201), então o piso de anonimato do Interesse é preservado. É uma **decisão de intenção de produto**, não um furo de anonimato.

## Decisão necessária
- **Se o opt-out governa apenas o reveal do Interesse** (conversa aberta segue viva): comportamento atual está correto, fechar como "by design".
- **Se o opt-out deve congelar a conversa inteira:** arquivar a conversa (status deixa de ser active) no `setOptOut(true)`, de modo que `sendMessage` caia em `ChatException::conversationArchived()` — sem vazar o opt-out de volta (manter a resposta indistinguível do lado da performer).

## Solução sugerida (caso "congelar")
No fluxo de `InterestService::setOptOut(true)`, arquivar as conversas do par afetado; reabrir em `setOptOut(false)`. Adicionar teste garantindo resposta indistinguível do lado da performer.'

# --- Issue 3 ------------------------------------------------------------------
gh issue create \
  --title "Broadcast do chat entrega metadados a membro em grace/expired" \
  --label "enhancement" \
  --assignee @me \
  --milestone "Sprint 5" \
  --body '## Contexto
O evento `MessageSent` transmite no canal privado `conversation.{id}`. O corpo da mensagem **nunca** vai no broadcast (só metadados: `message_id`, `conversation_id`, `sender_id`, `created_at`) — isso é intencional e correto (`app/Events/MessageSent.php`).

Porém a autorização do canal (`routes/channels.php`) só verifica `hasParticipant`, ignorando o **estado de acesso** do membro. Um membro em carência (grace) ou expirado continua inscrito e recebendo os "pings".

## Impacto
Membro sem acesso pago em dia recebe, em tempo real: sinal de que **houve** mensagem nova (atividade/timing) e o `sender_id`. Não vaza o **conteúdo** (corpo continua gateado no `show()`, server-side), mas revela atividade atrás do paywall. Severidade baixa.

## Solução sugerida
Na autorização do canal em `routes/channels.php`, além de `hasParticipant`, negar quando o membro não tem leitura — reusar `ChatAccessService::accessState(...)["can_read"]` (performer e assinante sempre passam; membro em grace/expired/none não). Assim o canal fica coerente com o gate de leitura do `show()`.

Adicionar teste de autorização de canal: membro em grace (negado) vs. ativo/assinante/performer (permitido).'

# --- Issue 4 ------------------------------------------------------------------
gh issue create \
  --title "Lançamento do ledger não referencia o ChatAccess no primeiro open" \
  --label "bug" \
  --assignee @me \
  --milestone "Sprint 5" \
  --body '## Contexto
Em `ChatAccessService::openOrRenew`, o débito e o crédito são gravados no `token_ledger` **antes** de a linha `chat_access` existir, no caso de **primeiro** open:

- `app/Services/ChatAccessService.php:84-104` — `debit(...)`/`credit(...)` recebem `$access?->id`, que é `null` quando ainda não há linha.
- A linha `chat_access` guarda `spend_ledger_id`/`credit_ledger_id` (trilha access → ledger, OK), mas os lançamentos do ledger ficam com `reference_id = null` (trilha ledger → access **quebrada**).

Na **renovação** o `$access->id` já existe, então o `reference_id` é preenchido corretamente — o furo é só na compra inicial.

## Impacto
Trilha de auditoria/reconciliação **unidirecional** na compra inicial: partindo de um lançamento `spend_chat_access`/`chat_access_credit`, não dá para chegar ao `ChatAccess` que o originou (só o caminho inverso funciona). Sem perda de dinheiro nem de saldo — é integridade de trilha.

## Solução sugerida
Criar a linha `chat_access` (esqueleto, com a janela) **antes** do débito, capturando o estado anterior (existência / `expires_at`) para preservar a lógica de empilhamento da janela e de `renewed_at`, e então debitar/creditar já com `$access->id`. Alternativa: após o `create`, reconciliar `reference_id` dos dois lançamentos na mesma transação.

Toca a transação crítica de cobrança — não regredir os testes de saldo insuficiente (`ChatAccess::count() === 0` no rollback) e de idempotência.'

echo "4 issues criadas."
