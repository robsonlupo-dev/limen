# LIMEN — UAT_MANUAL_COMPLETE.md

User Acceptance Testing — Roteiro completo passo a passo.
198 cenários organizados por jornada (102 originais + 68 das features do Sprint 16 + 28 da moderação Fase 1→4c).
**Atualizado: 2026-09-22** — adicionada a FASE 18 (Moderação / Trust & Safety: ações do moderador, SLA/prioridade, hub de filas, denúncia de apelido e fila de conteúdo auto-sinalizado). Versão anterior: V3 (2026-09-17).

---

## Como usar este documento

**Criticidade:**
- 🔴 **PARE** — se falhar, pare tudo e reporte. Bug de dinheiro ou segurança.
- 🟡 **ANOTE** — se falhar, anote e continue. Bug funcional ou visual.

**Coluna de resultado:** preencha com ✅ (passou), ❌ (falhou), ou ⚠️ (parcial).
Se falhou, anote o que aconteceu (print, URL, mensagem de erro).

**Browsers:**
- Browser A (Chrome): membro
- Browser B (Firefox/Incógnito): performer (sua esposa)

**Regra de ouro:** se a tela mostrar erro 500 (tela branca, "Server Error"),
tire print e anote a URL. Continue por outro caminho.

**Splits atualizados (economia reformada):**
- Sem infra (chat, tip, conteúdo, presente): **80/20** (performer/plataforma)
- Com infra (live, chamada): **70/30** (performer/plataforma)
- ⚠️ O manual anterior dizia 75/25 para presentes — ESTÁ ERRADO. É 80/20.

---

## FASE 1 — JORNADA DA PERFORMER (Browser B)

### 1.1 — Onboarding da Performer (usar conta ana@uat.limen.test)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 1 | Login | Acesse limen.top e faça login com ana@uat.limen.test / UatLimen2026! | Dashboard da performer aparece | 🟡 | ✅ | |
| 2 | Dashboard vazio | Verifique o dashboard | Sem conteúdo, sem ganhos, saldo 0 | 🟡 | ✅ | |
| 3 | Perfil | Acesse o perfil da performer e edite: bio, foto, mundo | Salva sem erro | 🟡 | ⚠️ | **ACHADO:** ao trocar nome artístico, slug do perfil muda mas link no catálogo fica antigo → 404. Acessando direto pelo novo slug funciona. Ver §JÁ CORRIGIDO |
| 4 | Publicar conteúdo Aberto | Publique 1 foto como "Aberto", preço 10 tokens | Foto aparece no perfil | 🟡 | ⚠️ | Fluxo existe em "Gerenciar → publicar". Retestar. |
| 5 | Publicar conteúdo Premium | Publique 1 foto como "Premium", preço 20 tokens | Foto aparece com indicação Premium | 🟡 | ⚠️ | Idem — retestar pelo fluxo correto |
| 6 | Publicar conteúdo Exclusivo | Publique 1 foto como "Exclusivo", preço 50 tokens | Foto aparece com indicação Exclusivo | 🟡 | ⚠️ | Tier pode não estar visível neste setup. Retestar. |
| 7 | Piso de preço | Tente publicar conteúdo com preço 3 tokens | Rejeitado — piso é 5 tokens | 🔴 | | Retestar |
| 8 | Live "Em breve" | Procure o botão de iniciar live | Mostra ícone + "Em breve", NÃO funcional (se flag OFF) | 🟡 | | |
| 9 | Chamada "Em breve" | Procure configuração de chamada | Mostra "Em breve" (se flag OFF) | 🟡 | | |
| 9.1 | Localização | Localização da performer | Autocomplete IBGE com todas as cidades; não aceita "3e" | 🟡 | 🔧 | **CORRIGIDO** — autocomplete IBGE implementado. Ver Fase 15. |
| 9.2 | Complete seu perfil | Aparece "adicionar foto de capa" mas a foto já está adicionada | Não deve pedir o que já foi preenchido | 🟡 | | Retestar |
| 9.3 | Performer acessa /catalogo | Performer não deveria ter acesso ao catálogo de performers | Logo leva ao catálogo de MEMBROS, não ao /catalogo | 🟡 | 🔧 | **CORRIGIDO** — home da performer é agora o catálogo de membros. Ver Fase 11. |

### 1.2 — Performer recebe interações (usar conta bella@uat.limen.test)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 10 | Login Bella | Login com bella@uat.limen.test | Dashboard aparece com 3 conteúdos | 🟡 | | |
| 11 | Ver extrato | Acesse o extrato de tokens | Lista vazia (ninguém comprou ainda) | 🟡 | ✅ | |
| 12 | Redação do payout | Procure informação sobre saque | Deve dizer "Cada token vale R$0,60 no saque" | 🔴 | | |
| 13 | Painel de fundadora | Se visível, verifique: sem número de posição, gênero neutro "Fundador(a)" | Texto correto | 🟡 | | |

---

## FASE 2 — JORNADA DO MEMBRO FREE (Browser A: free@uat.limen.test)

### 2.1 — Navegação e catálogo

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 14 | Login Free | Login com free@uat.limen.test | Dashboard do membro | 🟡 | ✅ | Testado com rico@uat.limen.test |
| 15 | Saldo | Verifique o saldo no header ou dashboard | 5.000 tokens | 🔴 | ✅ | |
| 16 | Catálogo | Acesse o catálogo de performers | Vê as 3 performers (Ana, Bella, Cris) | 🟡 | ✅ | |
| 17 | Perfil da performer | Clique no perfil da Bella | Vê bio, conteúdo Aberto (com preço), NÃO vê Premium/Exclusivo | 🔴 | ✅ | |
| 18 | Filtros | Use filtros do catálogo (mundo, etc.) | Funciona, filtra corretamente | 🟡 | | |

### 2.2 — Chat (free paga 2 tokens)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 19 | Abrir chat com Bella | Clique para abrir chat | Cobra 2 tokens, saldo vai a 4.998 | 🔴 | ✅ | |
| 20 | Enviar mensagem | Escreva "Oi, tudo bem?" e envie | Mensagem aparece no chat | 🟡 | ✅ | |
| 21 | Performer recebe (Browser B) | Na conta da Bella, verifique | Mensagem chegou + notificação toast | 🟡 | ✅ | |
| 22 | Performer responde | Bella responde "Olá!" | Resposta aparece no Browser A | 🟡 | ✅ | |
| 23 | Toast no membro | Verifique o toast de mensagem | Pop-up no canto inferior direito com foto + "Ler mensagem" | 🟡 | ✅ | |
| 24 | Performer ganhou 1 token | No extrato da Bella | 1 token de chat_access_credit (80% de 2 = 1.6000, performer recebe 1.6000) | 🔴 | ✅ | Split 80/20 com DECIMAL(20,4) |
| 25 | Filtro de conteúdo | Membro escreve "motel, 500 reais" | Mensagem BLOQUEADA (filtro Tipo 1 — programa/dinheiro) | 🟡 | ✅ | |
| 25.1 | Filtro evasão com espaços | Membro escreve "t e m a t o" ou "p r o g r a m a" | Mensagem BLOQUEADA | 🟡 | ✅ | **CORRIGIDO** — PR #218, fuzzy() com separator |
| 25.2 | Filtro ameaça/insulto | Membro escreve ameaça | Mensagem BLOQUEADA (Tipo 2 — conduta) | 🟡 | ✅ | |
| 25.3 | Contato no chat | Membro escreve "meu zap 11 98765-4321" | Mensagem PERMITIDA — decisão de negócio, não é o site que bloqueia | 🟡 | ✅ | docs/PENDENCIAS_JURIDICAS.md §4 |
| 25.4 | Nickname no chat | Membro com nickname definido envia mensagem | Aparece o nickname (não "Fã #NNNN") | 🟡 | ✅ | |
| 25.5 | Avatar no chat | Membro com avatar envia mensagem | Foto aparece ao lado da mensagem | 🟡 | ✅ | |

### 2.3 — Conteúdo (free paga por tudo)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 26 | Ver conteúdo Aberto da Bella | Acesse a foto Aberta de 10 tokens | Mostra preview borrado com preço | 🟡 | ❌ | **ACHADO:** blur muito agressivo (≤40px), não dá pra ver nada, não incentiva compra. Precisa ~80-120px. |
| 27 | Desbloquear Aberto | Pague 10 tokens | Foto desbloqueada, saldo diminui 10, permanente | 🔴 | ✅ | |
| 28 | Split correto | No extrato da Bella | Recebeu 8.0000 tokens (80% de 10), applied_rate=80 | 🔴 | ✅ | |
| 29 | Tentar ver Premium | Acesse conteúdo Premium da Bella | BLOQUEADO — free não tem acesso a Premium | 🔴 | | |
| 30 | Tentar ver Exclusivo | Acesse conteúdo Exclusivo da Bella | BLOQUEADO | 🔴 | | |
| 31 | Tentar ver FC Only (Cris) | Acesse conteúdo FC Only da Cris | BLOQUEADO | 🔴 | | |
| 31.1 | Imagens portrait | Publique foto vertical (retrato) e veja no catálogo | Foto inteira visível, sem corte | 🟡 | ❌ | **ACHADO:** corte no centro, perde cabeça/pés. Precisa object-fit: contain |

### 2.4 — Gorjeta

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 32 | Enviar gorjeta para Bella | Gorjeta de 50 tokens | Saldo do membro diminui 50, Bella recebe 40.0000 (80%) | 🔴 | ✅ | |
| 33 | Split da gorjeta | Verifique no extrato de Bella | 40.0000 tokens, applied_rate=80 | 🔴 | ✅ | |
| 33.1 | Histórico do membro | Verifique o extrato do membro que enviou | Mostra tipo da transação E nome da performer que recebeu | 🔴 | ❌ | **ACHADO:** não mostra quem recebeu, só o tipo de transação |
| 33.2 | Nickname no extrato | No extrato da performer | Aparece nickname do membro ao lado do FanAlias | 🟡 | ✅ | |

### 2.5 — Presente (SPLIT CORRIGIDO: 80/20)

> ⚠️ **ATENÇÃO:** O manual anterior dizia 75/25. O split correto é **80/20** (sem infraestrutura).

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 34 | Catálogo de presentes na live | Acesse a lista de presentes durante uma live | 6 presentes visíveis (Rosa→Diamante) | 🟡 | ✅ | |
| 35 | Enviar Rosa (4 tk) | Envie Rosa para Bella na live | Saldo diminui 4, Bella recebe 3.2000 (**80%**) | 🔴 | ✅ | ~~Anterior: 75/25~~ → **Corrigido para 80/20** |
| 36 | Enviar Champagne (40 tk) | Envie Champagne para Cris na live | Saldo diminui 40, Cris recebe 32.0000 (**80%**) | 🔴 | ✅ | |
| 37 | Split do presente | No extrato da performer | applied_rate=**80** (não 75) | 🔴 | ✅ | |
| 37.1 | Presente fora da live | Acesse catálogo de presentes do PERFIL da performer (sem estar em live) | Catálogo acessível e funcional | 🟡 | ❌ | **ACHADO:** catálogo só acessível durante live. Precisa de acesso pelo perfil. |
| 37.2 | Animação do presente | Envie presente na live | Animação aparece no feed de chat/tips | 🟡 | ✅ | |
| 37.3 | Gift "false success" | Envie presente com erro (ex: saldo insuf.) | Mostra mensagem de ERRO clara, não falso sucesso | 🔴 | ✅ | **CORRIGIDO** — FailsValidationAsJson + guard http.js |

---

## FASE 3 — CENÁRIOS NEGATIVOS (o mais importante)

### 3.1 — Membro pobre (pobre@uat.limen.test, 3 tokens)

> ⚠️ **BLOQUEADOR:** pobre@ não consegue logar. Conta existe (ID=167, active, consumer, verified, senha OK). Causa: **Turnstile captcha** rejeitando login. Fix pendente.

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 38 | Login pobre | Login com pobre@uat.limen.test | Saldo: 3 tokens | 🟡 | ❌ | **BLOQUEADO** — Turnstile rejeitando. Ver §BLOQUEADORES |
| 39 | Abrir chat (2 tk) | Abra chat com Ana | Funciona — cobra 2, sobra 1 | 🔴 | 🚫 | Depende de #38 |
| 40 | Segundo chat (precisa 2, tem 1) | Abra chat com Bella | RECUSADO — saldo insuficiente, mensagem clara | 🔴 | 🚫 | Depende de #38 |
| 41 | Gorjeta (precisa 5, tem 1) | Tente gorjear Bella com 5 tokens | RECUSADO — saldo insuficiente | 🔴 | 🚫 | Depende de #38 |
| 42 | Presente Rosa (precisa 4, tem 1) | Tente enviar Rosa | RECUSADO — saldo insuficiente | 🔴 | 🚫 | Depende de #38 |
| 43 | Desbloquear conteúdo (precisa 10, tem 1) | Tente desbloquear Aberto | RECUSADO | 🔴 | 🚫 | Depende de #38 |
| 44 | Mensagem de recusa | Em TODAS as recusas acima | Mensagem clara (nunca erro 500, nunca tela branca) | 🔴 | 🚫 | Depende de #38 |

### 3.2 — Gate de tier

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 45 | Explorador vê Aberto grátis | Login explorador@, veja conteúdo Aberto da Bella | Grátis, sem pagar | 🔴 | | |
| 46 | Explorador NÃO vê Premium | Tente acessar Premium da Bella | BLOQUEADO | 🔴 | | |
| 47 | Prestige vê Premium | Login prestige@, acesse Premium da Bella | Paga 20 tokens, desbloqueia | 🔴 | | |
| 48 | Prestige NÃO vê Exclusivo | Tente acessar Exclusivo | BLOQUEADO | 🔴 | | |
| 49 | Black vê Exclusivo | Login black@, acesse Exclusivo da Cris | Paga 50 tokens, desbloqueia | 🔴 | | |
| 50 | Black NÃO vê FC Only | Tente acessar FC Only | BLOQUEADO | 🔴 | | |
| 51 | FC vê FC Only | Login fc@, acesse FC Only da Cris | Paga 100 tokens, desbloqueia | 🔴 | | |

### 3.3 — Chat por tier

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 52 | Free paga 2 tk de chat | free@ abre chat com Cris | Cobra 2, performer recebe 1.6000 (80%) | 🔴 | | |
| 53 | Explorador paga 2 tk | explorador@ abre chat com Cris | Cobra 2, performer recebe 1.6000 (80%) | 🔴 | | |
| 54 | Black paga 1 tk | black@ abre chat com Cris | Cobra 1, performer recebe 0.8000 (80%) | 🔴 | | |
| 55 | FC paga 1 tk | fc@ abre chat com Cris | Cobra 1, performer recebe 0.8000 (80%) | 🔴 | | |
| 56 | Performer recebe sempre a mesma % | Verifique no extrato da Cris | Todos os chats creditam 80% do valor cobrado | 🔴 | | |

### 3.4 — Isolamento de role

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 57 | Membro em rota de performer | Logado como free@, acesse /performer/dashboard | 403 ou redirect (NUNCA conteúdo) | 🔴 | | |
| 58 | Performer em rota de membro | Logado como ana@, acesse rota de membro | 403 ou redirect | 🔴 | | |
| 59 | Não autenticado | Sem login, acesse /performer/dashboard | 401 ou redirect ao login | 🔴 | | |

---

## FASE 4 — PRIVACIDADE E SEGURANÇA

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 60 | FanAlias no chat | No chat, a performer vê o membro como | FanAlias (ex: "Membro-A3F2"), NUNCA nome real ou email | 🔴 | | |
| 61 | Tier NÃO visível | A performer vê o tier do membro? | NÃO — nenhum badge, nenhuma indicação | 🔴 | | |
| 62 | Ghost Mode (Black) | Login black@, visite perfil de Cris | A visita NÃO aparece no painel de Cris | 🔴 | | |
| 63 | Panic Button | Procure e clique no Panic Button | Redireciona para Google instantaneamente | 🟡 | | |
| 64 | Denúncia | Denuncie um conteúdo de Bella (como membro) | Denúncia enviada, confirmação na tela | 🟡 | | |
| 65 | Denúncia no admin | Login admin@, acesse painel de moderação | Denúncia aparece na fila | 🟡 | | |

---

## FASE 5 — ECONOMIA DE TOKENS (A CONTA FECHA)

> Esta é a fase mais importante. Se os números não batem, tem bug de dinheiro.
> **IMPORTANTE:** Todos os valores são DECIMAL(20,4). Arredondamento floor único no payout.

### 5.1 — Verificação do ledger (após as fases 2 e 3)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 66 | Saldo do membro free | No dashboard ou via tinker | saldo_atual + total_gasto == 5.000 (saldo inicial) | 🔴 | | |
| 67 | Saldo do membro pobre | Idem | saldo_atual + total_gasto == 3 | 🔴 | 🚫 | Depende do fix pobre@ |
| 68 | Saldo da Bella | No dashboard da performer | saldo == soma de todos os *_credit no ledger | 🔴 | | |
| 69 | Saldo da Cris | Idem | saldo == soma de *_credit | 🔴 | | |
| 70 | Retenção da Limen | Calcular manualmente | total_gasto_membros == total_ganho_performers + total_retido_limen | 🔴 | | |

### 5.2 — Verificação via terminal (rodar depois dos testes manuais)

```bash
cd /var/www/limen && php artisan tinker --execute="
\$users = App\Models\User::where('email', 'like', '%uat.limen.test')->get();
foreach (\$users as \$u) {
    \$wallet = \$u->tokenWallet;
    \$ledgerSum = \$u->tokenLedgerEntries()->sum('amount');
    \$match = \$wallet && \$wallet->balance == \$ledgerSum ? 'OK' : 'MISMATCH';
    echo \$u->email . ' | balance=' . (\$wallet->balance ?? 'null') .
         ' | ledger=' . \$ledgerSum . ' | ' . \$match . PHP_EOL;
}
"
```

**Se algum mostrar MISMATCH, é bug de dinheiro. PARE e reporte.**

---

## FASE 6 — PAYOUT DA PERFORMER

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 71 | Bella tem ganhos | Verifique o dashboard de Bella | Mostra tokens ganhos (tip_credit + chat_access_credit + content_credit + gift_credit) | 🔴 | | |
| 72 | Valor em R$ | Verifique o valor de saque | ganhos × R$0,60 | 🔴 | | |
| 73 | Solicitar saque (on-demand) | Se Bella tem >= 100 de ganhos, solicite saque | Saque criado, tokens debitados | 🔴 | | |
| 74 | Saque com < 100 ganhos | Se ganhos < 100, tente sacar | RECUSADO — mínimo 100 tokens | 🔴 | | |
| 75 | subscription_grant NÃO entra | Verifique que tokens de franquia não contam no saque | Só *_credit conta | 🔴 | | |

---

## FASE 7 — ADMIN E MODERAÇÃO

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 76 | Login admin | Login com admin@uat.limen.test | Painel admin carrega | 🟡 | ✅ | Fix aplicado: role='admin' (não 'moderator') |
| 77 | Fila de KYC | Acesse o painel de KYC | Lista aparece (pode estar vazia) | 🟡 | | |
| 78 | Fila de denúncias | Acesse moderação/reports | Denúncia do cenário 64 aparece | 🟡 | | |
| 79 | Waitlist | Acesse admin/waitlist | Lista de waitlist com entradas | 🟡 | | |
| 80 | Ban performer | Tente banir a Ana (ou outra conta de teste) | Performer marcada como banida | 🟡 | | |
| 81 | Performer banida tenta logar | Logout Ana, tente logar | Bloqueada com mensagem | 🔴 | | |

---

## FASE 8 — HARD DELETE E LGPD

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 82 | Solicitar exclusão | Login como membro, solicite exclusão de conta | Confirmação recebida, grace de 30 dias | 🔴 | | |
| 83 | Dados marcados | Verifique no admin ou tinker | Conta marcada para exclusão | 🟡 | | |
| 84 | Tentar logar após | Logout, tente logar | Conta em grace — comportamento definido | 🟡 | | |

---

## FASE 9 — LIVE E CHAMADA (quando feature flag ON)

> Estes testes SÓ rodam quando FEATURE_LIVE_ENABLED=true no .env.
> Para testar, mude o .env temporariamente e rode config:clear.
> Depois volte para false.

### 9.1 — Live pública

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 85 | Performer inicia live | Login Cris (Browser B), clique "Iniciar Live" | Câmera liga, status "ao vivo" | 🟡 | ✅ | |
| 86 | Badge no catálogo | Verifique o catálogo (Browser A) | Badge "AO VIVO" na Cris | 🟡 | ✅ | |
| 87 | Membro entra grátis | Login free@ (Browser A), clique na Cris ao vivo | Vê o vídeo SEM cobrar tokens | 🔴 | ✅ | |
| 88 | Gorjeta durante live | Envie gorjeta de 20 tk | Débito **80/20**, animação aparece | 🔴 | ✅ | |
| 89 | Presente durante live | Envie Rosa (4 tk) | Débito **80/20**, animação aparece | 🔴 | ✅ | ~~75/25~~ → **Corrigido para 80/20** |
| 90 | Performer encerra | Clique "Encerrar Live" | Todos desconectados, badge some | 🟡 | ✅ | |
| 90.1 | Token SVG | Verifique o ícone de token no console/chat | SVG, não emoji quadrado | 🟡 | ✅ | **CORRIGIDO** — emoji substituído por SVG |
| 90.2 | Vídeo sem tela preta | Membro entra na live | Vídeo aparece sem tela preta | 🟡 | ✅ | **CORRIGIDO** — retry 3×/350ms em enableLocalMedia |
| 90.3 | Chat height desktop | Chat da live no desktop | Altura adequada, sem overflow | 🟡 | 🔧 | Fix aplicado, retestar |

### 9.2 — Chamada privada 1:1

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 91 | Membro solicita chamada | free@ solicita chamada com Cris (10 tk/min) | Cris recebe notificação | 🟡 | | |
| 92 | Performer aceita | Cris aceita a chamada | Vídeo bidirecional, 1º minuto debitado (10 tk) | 🔴 | | |
| 93 | Cobrança por minuto | Espere 1 minuto | 2º minuto debitado automaticamente (10 tk) | 🔴 | | |
| 94 | Split 70/30 | Verifique no extrato da Cris | 7.0000 tokens por minuto (70%), applied_rate=70 | 🔴 | | |
| 95 | Encerrar | Qualquer lado encerra | Room fechada, tempo contabilizado | 🟡 | | |
| 95.1 | Chat na chamada privada | Durante chamada, envie mensagem no chat | Chat funciona dentro da chamada | 🟡 | 🔧 | Fix aplicado, retestar |
| 95.2 | Mute na chamada privada | Durante chamada, clique mute | Áudio silencia, indicador visual | 🟡 | 🔧 | Fix aplicado, retestar |
| 95.3 | Pausar live durante chamada | Performer aceita chamada privada durante live | Live pausa para os viewers (não encerra) | 🟡 | | |
| 95.4 | Retomar live após chamada | Chamada encerra | Live retoma automaticamente para os viewers | 🟡 | | |

### 9.3 — Chamada com saldo baixo

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 96 | Membro pobre solicita | Login pobre@ (saldo ~1 tk), solicite chamada | RECUSADO — saldo < preço de 1 minuto | 🔴 | 🚫 | Depende do fix pobre@ |

---

## FASE 10 — CENÁRIOS EDGE CASE

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 97 | Desbloqueio duplo | Tente desbloquear o MESMO conteúdo duas vezes | Segunda vez não cobra (idempotente) | 🔴 | | |
| 98 | Chat já aberto | Abra chat com performer que já tem chat | Não cobra novamente (chat já pago) | 🔴 | | |
| 99 | Presente para si mesmo | Performer tenta enviar presente para si | RECUSADO | 🟡 | | |
| 100 | Gorjeta para si mesmo | Performer tenta gorjear a si mesma | RECUSADO | 🟡 | | |
| 101 | URL direta de conteúdo | Copie URL de foto Premium e acesse sem login | BLOQUEADO (401 ou redirect) | 🔴 | | |
| 102 | URL direta com login errado | Login como Explorador, acesse URL de Exclusivo | BLOQUEADO (403 ou sem imagem) | 🔴 | | |

---

## CHECKLIST FINAL — A CONTA FECHA

Preencha DEPOIS de todos os testes:

| Verificação | Resultado |
|---|---|
| Saldo de CADA membro = saldo_inicial − total_gasto | |
| Saldo de CADA performer = soma de *_credit | |
| wallet.balance == SUM(ledger.amount) para todos | |
| Nenhum saldo negativo em nenhuma conta | |
| total_gasto_membros == total_ganho_performers + total_retido_limen | |
| Nenhum erro 500 encontrado durante TODOS os testes | |
| Nenhum conteúdo exibido para tier sem acesso | |
| Performer NUNCA viu tier, saldo ou nome real do membro | |
| Formatação de token sempre pt-BR com vírgula (ex: 1.234,5678) | |

---

# ═══════════════════════════════════════════════════════════════
# PARTE 2 — FEATURES NOVAS (construídas na sessão de Sprint 16+)
# ═══════════════════════════════════════════════════════════════

> Estas fases (11–17) cobrem tudo que foi construído DEPOIS da versão original
> deste manual. Muitos achados que você anotou na Fase 1 já foram corrigidos —
> veja a seção "JÁ CORRIGIDO" no fim antes de retestar.

---

## FASE 11 — CATÁLOGO DE MEMBROS + CORAÇÃO/MENSAGEM (home da performer)

> A performer agora entra direto no catálogo de MEMBROS (não no dashboard).
> Ela pode dar coração (grátis, ilimitado) e mandar mensagem personalizada
> (15 grátis/dia; o membro paga para LER).

### 11.1 — Home e catálogo de membros

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 103 | Home da performer | Login ana@, veja para onde vai | Vai direto ao catálogo de MEMBROS (não ao dashboard) | 🟡 | | |
| 104 | Logo leva ao catálogo | Clique no logo LIMEN (topo esquerdo) | Vai ao catálogo de membros (não ao /catalogo de performers) | 🟡 | | |
| 105 | Dashboard acessível | Clique em "Meu Painel" na nav | Dashboard ainda existe e abre | 🟡 | | |
| 106 | Cards de membro | Veja os cards no catálogo de membros | FanAlias (ex "Membro #0294"), foto ou placeholder, coração + mensagem | 🟡 | | |
| 107 | Contador de mensagens | Veja o topo do catálogo | "Mensagens grátis hoje: 15/15" | 🟡 | | |
| 108 | Tier invisível | Veja se aparece o tier do membro | NÃO — nenhum tier/badge visível | 🔴 | | |
| 109 | Black/FC ocultos | Membros Black/FC que nunca optaram | NÃO aparecem no catálogo (privacidade default) | 🔴 | | |

### 11.2 — Coração (grátis, membro vê quem curtiu)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 110 | Dar coração | ana@ dá coração num membro (ex free@) | Registra, sem custo, ilimitado | 🟡 | | |
| 111 | Membro vê quem curtiu | Login free@, veja "Interessadas" na nav | Vê que Ana curtiu — SEM pagar nada, com nome/identidade da performer | 🔴 | | |
| 112 | Contador de coração | Na nav do membro | Badge de não-vistos aparece; zera ao abrir | 🟡 | | |

### 11.3 — Mensagem personalizada (15/dia grátis; membro paga para ler)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 113 | Enviar mensagem | ana@ escreve mensagem personalizada a free@ | Enviada; contador cai para 14/15 | 🟡 | | |
| 114 | Membro vê teaser | Login free@, veja a mensagem | Vê DE QUEM + as 3 primeiras palavras + resto BORRADO/bloqueado | 🔴 | | |
| 115 | Corpo não vaza | Inspecione (DevTools) o payload da mensagem bloqueada | O corpo COMPLETO não está no HTML/JSON — só o teaser (corte server-side) | 🔴 | | |
| 116 | Desbloquear mensagem | free@ paga tokens para ler | Corpo completo aparece, vira conversa normal | 🔴 | | |
| 117 | Limite de 15/dia | ana@ manda 15 mensagens | 16ª bloqueada: "você usou suas 15 mensagens grátis de hoje" | 🟡 | | |
| 118 | Performer NÃO paga | ana@ manda mensagem | Nunca é cobrada — só o membro paga para ler | 🔴 | | |

---

## FASE 12 — AGENDAMENTO DE CHAMADA (feature flag: FEATURE_CALL_ENABLED)

> Membro agenda chamada com performer. Depósito de 1 minuto é TRAVADO no saldo.
> Só rode com FEATURE_CALL_ENABLED=true no .env + config:clear.

### 12.1 — Agendar e travar depósito

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 119 | Botão agendar | free@ abre perfil da Cris, procure "Agendar chamada" | Botão visível (ao lado de "Chamada privada") | 🟡 | | |
| 120 | Agendar horário | Escolha data/hora futura | Depósito = preço de 1 min é TRAVADO (sai do saldo disponível) | 🔴 | | |
| 121 | Saldo reservado | Veja o saldo do membro | Mostra tokens reservados; não gastáveis em outra coisa | 🔴 | | |
| 122 | Horário ocupado bloqueado | Outro membro tenta agendar mesmo horário + buffer 5min da Cris | Horário indisponível | 🟡 | | |
| 123 | Máximo 5 agendamentos | free@ tenta agendar 6 chamadas futuras | 6ª recusada — máx 5 por membro | 🟡 | | |

### 12.2 — Confirmação e no-show

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 124 | Performer confirma | Cris vê o agendamento no painel, confirma | Status vira confirmado | 🟡 | | |
| 125 | Performer não confirma | Deixe passar a janela (2h antes) sem confirmar | Cancela automaticamente + refund total ao membro | 🔴 | | |
| 126 | No-show do membro | No horário, Cris entra, membro NÃO entra em 2 min | Depósito (1 min) vai 100% para a Cris (call_noshow_credit) | 🔴 | | |
| 127 | No-show da performer | No horário, membro espera, Cris NÃO entra em 2 min | Refund total ao membro + 1 strike na Cris. Nenhuma sala aberta | 🔴 | | |
| 128 | Contador de 2 min | Membro na tela de espera após Cris entrar | Vê contador de 2 min correndo | 🟡 | | |
| 129 | Cancelamento grátis | Membro cancela com >2h de antecedência | Refund total | 🔴 | | |
| 130 | Sala privada | Durante a chamada agendada | NÃO aparece como live pública no catálogo | 🔴 | | |
| 131 | Chamada acontece | Ambos entram, 1º min pago pelo depósito | Minutos seguintes cobrados 70/30 (fluxo normal) | 🔴 | | |
| 132 | 3 strikes | Performer com 3 no-shows | Flag para review no admin | 🟡 | | |

---

## FASE 13 — VISITAS BIDIRECIONAIS

> Antes só existia membro→performer. Agora performer→membro também: quando a
> performer visita o perfil do membro, ELE fica sabendo.

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 133 | Performer visita membro | ana@ abre o perfil de um membro (free@) | Visita registrada | 🟡 | | |
| 134 | Membro vê quem visitou | Login free@, acesse "Quem me visitou" | Vê a performer (nome/avatar públicos), link ao perfil dela | 🔴 | | |
| 135 | Sentido antigo intacto | free@ visita perfil da Ana | Ana vê no painel dela (como antes) | 🟡 | | |
| 136 | Ghost Mode do Black preservado | Login black@, visite Ana | Ana NÃO vê a visita (Ghost Mode do membro premium) | 🔴 | | |
| 137 | Performer NUNCA vê PII | Na tela de visita, o que a performer vê do membro | FanAlias sempre, nunca tier/nome real | 🔴 | | |

---

## FASE 14 — INTRO DE VOZ DA PERFORMER

> Performer grava intro de voz (≤20s). Vai para moderação humana. Só aparece
> no perfil depois de aprovada pelo admin.

### 14.1 — Gravação e moderação

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 138 | Tela de gravação | ana@ acessa a gestão de intro de voz | Vê o texto de orientação (voz é isca, não passe contato/redes, moderação, voz identificável) | 🟡 | | |
| 139 | Gravar/enviar áudio | Grave ou envie um áudio ≤20s | Aceito, status vira "processing" → "pending" | 🟡 | | |
| 140 | Áudio > 20s recusado | Tente enviar áudio de 30s | Recusado (422, cap de duração) | 🔴 | | |
| 141 | Não aparece antes de aprovar | Veja o perfil da Ana (como membro) | O áudio NÃO aparece (ainda pending) | 🔴 | ✅ | Confirmado: voz só aparece após aprovação |
| 142 | Fila de moderação | Login admin@, acesse /moderacao/apresentacoes-de-voz | Áudio da Ana aparece com player | 🟡 | ✅ | |
| 143 | Admin aprova | admin@ ouve e aprova | Status vira approved | 🟡 | ✅ | |
| 144 | Player no perfil | Veja o perfil da Ana (como membro) | Botão de play dourado ao lado do nome; toca grátis | 🔴 | ✅ | |
| 145 | Indicador no card | Veja o card da Ana no catálogo | Ícone "tem áudio" | 🟡 | | |

### 14.2 — Rejeição e notificação

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 146 | Admin rejeita | admin@ rejeita um áudio com motivo | Status rejected + motivo salvo | 🟡 | | |
| 147 | Performer é notificada | Login ana@ após rejeição | Recebe aviso de que a voz não foi aprovada + motivo + convite a gravar outra | 🔴 | | |
| 148 | Substituir áudio | ana@ grava um novo no lugar | Novo áudio volta para pending (re-moderação) | 🔴 | | |
| 149 | Metadados removidos | (técnico) Verifique o áudio servido | Sem GPS/ID3/metadados (strip via ffmpeg) | 🔴 | | |

---

## FASE 15 — FILTRO DE CIDADE CONSENTIDO

> A cidade da performer é PRIVADA por padrão (só UF é pública). A performer pode
> optar por ser "encontrável por cidade" (default OFF).

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 150 | Autocomplete de cidade | ana@ edita localização, digita "sao" | Aparecem sugestões (São Paulo, etc.) da base IBGE; ignora acento | 🟡 | | |
| 151 | Cidade inválida recusada | Tente salvar cidade "3e" ou nome inexistente | NÃO aceita (só municípios reais da base IBGE) | 🟡 | | |
| 152 | Toggle "encontrável por cidade" | ana@ liga o toggle (default OFF) | Salva; agora ela pode ser filtrada por cidade | 🟡 | | |
| 153 | Filtro no catálogo | Membro filtra catálogo por cidade da Ana | Ana aparece (ela optou); performers que NÃO optaram não aparecem no filtro de cidade | 🔴 | | |
| 154 | Cidade nunca no card | Veja o card da performer | Cidade NÃO é exibida (só filtra, nunca mostra) | 🔴 | | |
| 155 | Catálogo de membros sem filtro de cidade | Veja o catálogo de membros (como performer) | NÃO tem filtro de cidade (privacidade do membro) | 🔴 | | |

---

## FASE 16 — CAPTCHA (Cloudflare Turnstile) + BADGES + MICROINTERAÇÕES

### 16.1 — Turnstile

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 156 | Widget no cadastro | Acesse /cadastro (aba anônima) | Widget "Verify you are human" da Cloudflare aparece | 🟡 | | |
| 157 | Bloqueio sem captcha | Tente cadastrar sem resolver o captcha | Bloqueado | 🔴 | | |
| 158 | Cadastro com captcha | Resolva e cadastre | Passa; validação server-side OK | 🔴 | | |
| 158.1 | Login com captcha | Faça login normalmente | Captcha não impede login de contas válidas | 🔴 | ❌ | **ACHADO:** Turnstile pode estar bloqueando login de pobre@. Ver §BLOQUEADORES |

### 16.2 — Badges e microinterações

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 159 | Badge "Nova/Novo" | Veja cards de quem entrou nos últimos 7 dias | Pílula dourada "Nova"/"Novo" | 🟡 | | |
| 160 | Contadores não-vistos | Veja a nav (Mensagens, Interessadas) | Bolinha com número; zera ao abrir a seção | 🟡 | | |
| 161 | Microinterações | Hover nos cards, clique em botões, marque coração | Cards elevam, botões pulsam, coração faz "pop" | 🟡 | | |
| 162 | Reduced-motion | Ative "reduzir movimento" no SO, recarregue | Animações desligam, conteúdo estático legível | 🟡 | | |

---

## FASE 17 — LANDING DE PRÉ-LANÇAMENTO (thelimen.com.br)

> A raiz do thelimen.com.br é a landing cinematográfica de pré-lançamento.
> Teste de casa/4G (a empresa bloqueia via Zscaler).

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 163 | Landing carrega | Acesse thelimen.com.br | 5 cenas com fotos: porta (vídeo), arco, digital, silhueta/máscara, LIMEN | 🟡 | | |
| 164 | Imagens carregam | Role a página toda | Todas as fotos aparecem (não fundo preto) | 🔴 | | |
| 165 | Header só logo | Veja o topo | Só o logo LIMEN — sem "Entrar"/"Criar conta" (pré-lançamento) | 🟡 | | |
| 166 | Sem cadastro | Procure CTA de cadastro | NÃO existe — só "Entre na lista de espera" | 🟡 | | |
| 167 | Waitlist funciona | Preencha e-mail + papel + 18+, envie | Registra na lista de espera | 🔴 | | |
| 168 | Aviso de spam | Após enviar o e-mail | Mensagem clara: confira a caixa de spam, marque "não é spam" | 🔴 | | |
| 169 | Convite por link | Acesse thelimen.com.br/convite/{code} | Badge "convidado por X" + papel pré-selecionado na waitlist | 🟡 | | |
| 170 | Mobile | Teste no celular | Layout responsivo, texto legível, sem sobreposição | 🟡 | | |

---

## FASE 18 — MODERAÇÃO / TRUST & SAFETY (área `/moderacao/*`)

> Tudo construído na sessão de 22/09/2026 (PRs #242–#250). Área separada do
> `/admin/*`: **moderator OU admin** entram, SEM poderes de admin (ban permanente
> continua do admin). Use a conta **UAT Moderador** (papel `moderator`, ver
> `docs/qa/TEST_ACCOUNTS.md`).
>
> **Rotas:** hub `/moderacao` · denúncias `/moderacao/denuncias` · escalados
> `/moderacao/denuncias?escalated=1` · conteúdo sinalizado
> `/moderacao/conteudo-sinalizado` · minhas ações `/moderacao/minhas-acoes`.
>
> **Gatilhos do filtro (para os testes de conduta):**
> - CONDUTA (bloqueia **+ sinaliza**): `vou te matar`, `te mato`, `sua puta nojenta`.
> - RISCO LEGAL (bloqueia, **não** sinaliza): `faço programa completo`, `pix fora`.
> - Passa: `sua puta safada` (qualificador consensual), `vamos num motel`.
> O corpo da mensagem **nunca** é gravado — a moderação age por **reincidência**.

### 18.1 — Hub e acesso

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 171 | Hub da moderação | Login UAT Moderador → acesse `/moderacao` | Cartões: Denúncias, Escalados, Fotos de membro, Intros de voz, **Conteúdo sinalizado**, Minhas ações — cada um com contagem de pendentes | 🟡 | | |
| 172 | Gate de acesso | Logado como **membro** (free@), acesse `/moderacao` | 403 / redirect (NUNCA a fila) | 🔴 | | |
| 173 | Admin também entra | Login admin@, acesse `/moderacao` | Entra (admin ⊇ moderator) | 🟡 | | |
| 174 | Minhas ações (read-only) | Após agir (18.3), abra `/moderacao/minhas-acoes` | Lista só as ações DO moderador logado, sem PII do denunciante | 🟡 | | |

### 18.2 — Fila de denúncias: tela de trabalho + SLA/prioridade (Fases 2–3)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 175 | Detalhe da denúncia | `/moderacao/denuncias` → abrir uma denúncia | Denunciante **pseudonimizado** (nunca id/e-mail cru), tipo/alvo, motivo, nota; ações de fechamento + moderação presentes | 🔴 | | |
| 176 | Prova retida | Na denúncia (foto/story/mensagem), acionar a prova | Carrega pelo endpoint dedicado; se expirou, mostra "expirado" + hash sem quebrar | 🟡 | | |
| 177 | Próximos + stats | Ver a tela de detalhe | "Próximos na fila" (ordem de atendimento) + rodapé de contagens | 🟡 | | |
| 178 | Selo de prioridade | `/moderacao/denuncias` | Cada denúncia com selo Urgente/Alta/Normal (derivado do motivo) | 🟡 | | |
| 179 | Ordem + atrasada | Ver a fila de pendentes | Ordenada por prioridade e antiguidade; fora do SLA marcada como **atrasada** | 🟡 | | |

### 18.3 — Ações do moderador (Fase 1)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 180 | Advertir | Abrir denúncia → **Advertir** → motivo → confirmar | Sucesso "Advertência registrada"; aparece em Minhas ações | 🟡 | | |
| 181 | Suspender temporário | Abrir denúncia → **Suspender** → motivo + dias (1–90) | Sucesso; alvo fica `suspended` até o prazo | 🔴 | | |
| 182 | **Suspensão derruba sessão viva** | Com o alvo (membro) **logado noutra janela**, suspenda-o; na janela dele, navegue para uma página autenticada | O membro é **deslogado/bloqueado na hora** — a sessão web também cai (não só o token de API) | 🔴 | | Teste que faltava da Fase 1 |
| 183 | Reativação automática | Alvo suspenso com prazo já vencido tenta usar o site | Acesso **volta sozinho** (a suspensão expira sem ação manual) | 🔴 | | Para testar rápido, ajuste `suspended_until` para o passado no tinker |
| 184 | Escalar ao admin | Abrir denúncia → **Escalar** → motivo | Sucesso; passa a aparecer em `?escalated=1`; botão "Escalar" desabilita (já escalada) | 🟡 | | |
| 185 | Limites de papel | Tente moderar a própria conta / um admin / banir permanentemente | Recusado — moderador não bane (escala), não modera admin nem a si mesmo | 🔴 | | |

### 18.4 — Denúncia de apelido de membro (Fase 4b + 4b-ui)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 186 | Botão só com apelido | Como **performer**, abra o perfil e o chat de um membro **com** apelido e de um **sem** apelido | Botão discreto **"Denunciar apelido"** só aparece para o membro COM apelido (no perfil e no cabeçalho do chat) | 🟡 | | |
| 187 | Enviar denúncia | Clicar em Denunciar apelido → motivo (Fingindo ser outra pessoa / Spam / Outro) + detalhes → Enviar | Banner verde "Denúncia recebida. Nossa equipe vai analisar."; modal fecha | 🟡 | | |
| 188 | Resposta uniforme (anti-oráculo) | Denuncie um apelido inexistente / o seu próprio / repetido | **Sempre** a mesma resposta ("recebida") — a performer nunca descobre o estado | 🔴 | | |
| 189 | Cai na fila | Como moderador, `/moderacao/denuncias` (tipo **Apelido**) | Denúncia aparece; advertir/suspender agem sobre o MEMBRO dono do apelido | 🟡 | | |

### 18.5 — Fila de conteúdo auto-sinalizado (Fase 4c-a + 4c-b)

| # | Passo | Ação | Esperado | Crit. | Result. | Notas |
|---|---|---|---|---|---|---|
| 190 | Conduta no chat 1:1 | Como membro, envie no chat `vou te matar` | Envio **bloqueado**; em `/moderacao/conteudo-sinalizado` o usuário ganha **1 flag** (fonte **Chat**) | 🔴 | | |
| 191 | Risco legal NÃO sinaliza | Como membro, envie `faço programa completo` | **Bloqueado**, mas **não** vira flag (não aparece / não ganha flag na fila) | 🔴 | | |
| 192 | Conduta no chat ao vivo | (se live ON) espectador manda `vou te matar` no chat da sala | Bloqueado; flag com fonte **Chat ao vivo** | 🟡 | | Depende de FEATURE_LIVE_ENABLED |
| 193 | Conduta na bio | Como membro, salve a bio pública com `vou te matar` | Bio **rejeitada**; flag com fonte **Perfil** | 🟡 | | |
| 194 | Conduta no apelido | Como membro, tente definir o apelido `te mato` | Apelido **rejeitado**; flag com fonte **Apelido** (contato/reservado/legal NÃO geram flag) | 🟡 | | |
| 195 | Fila agrega por reincidência | `/moderacao/conteudo-sinalizado` | Um **cartão por usuário** (papel + id interno, sem nome/e-mail) com contagem, fontes e data do último; mais reincidente no topo; **corpo nunca exibido** | 🔴 | | |
| 196 | Ações na fila | Num cartão: **Advertir** (motivo), **Suspender** (motivo+dias), **Dispensar** | Advertir/suspender agem sobre o usuário e **não** limpam os flags; **Dispensar** marca os pendentes como dispensados e o usuário sai da fila (volta com novo flag) | 🔴 | | |
| 197 | Contagem no hub | Compare o cartão "Conteúdo sinalizado" em `/moderacao` com a fila | Mostra o nº de **usuários distintos** com flag pendente, batendo com a fila | 🟡 | | |
| 198 | Dedup na janela | Dispare a MESMA conduta 2× seguidas | Gera **só 1 flag** na janela (não infla a contagem) | 🟡 | | |

---

# ═══════════════════════════════════════════════════════════════
# RESUMO EXECUTIVO — O QUE JÁ FOI TESTADO
# ═══════════════════════════════════════════════════════════════

## Cenários já testados e aprovados (✅)

| Fase | Cenários aprovados |
|---|---|
| 1.1 Onboarding performer | #1, #2 |
| 2.1 Navegação | #14, #15, #16, #17 |
| 2.2 Chat | #19–#25, #25.1–#25.5 (chat, filtro, evasão, contato, nickname, avatar) |
| 2.3 Conteúdo | #27, #28 (desbloqueio e split) |
| 2.4 Gorjeta | #32, #33, #33.2 (envio, split, nickname extrato) |
| 2.5 Presente | #34–#37, #37.2, #37.3 (catálogo live, envio, split 80/20, animação, false success fix) |
| 7 Admin | #76 (login admin) |
| 9.1 Live | #85–#90, #90.1, #90.2 (iniciar, badge, entrar, gorjeta, presente, encerrar, SVG, vídeo) |
| 14.1 Voz | #141–#144 (moderação, aprovação, player) |

## Cenários com falha conhecida (❌)

| # | Problema | Prioridade |
|---|---|---|
| #26 | Blur preview muito agressivo | Alta |
| #31.1 | Imagens portrait cortadas | Alta |
| #33.1 | Histórico membro não mostra performer | Média |
| #37.1 | Presente só na live, sem acesso pelo perfil | Média |
| #38 | Login pobre@ bloqueado (Turnstile) | **CRÍTICO** |
| #158.1 | Turnstile possivelmente bloqueando login | **CRÍTICO** |

## Cenários bloqueados (🚫) — dependem do fix pobre@

#39, #40, #41, #42, #43, #44, #67, #96

## Cenários com fix aplicado, aguardando reteste (🔧)

| # | Fix |
|---|---|
| #9.1 | Autocomplete IBGE para cidades |
| #9.3 | Home performer = catálogo membros |
| #90.3 | Chat height desktop na live |
| #95.1 | Chat na chamada privada |
| #95.2 | Mute na chamada privada |

---

# ═══════════════════════════════════════════════════════════════
# BLOQUEADORES
# ═══════════════════════════════════════════════════════════════

## 1. Login pobre@uat.limen.test — CRÍTICO

**Status:** Conta existe e está perfeita (ID=167, active, consumer, verified, senha 60 chars).
**Causa provável:** Turnstile (CAPTCHA_PROVIDER=turnstile) está rejeitando o login.

**Para investigar, rode no servidor:**

```bash
# Verificar se o login controller valida Turnstile
cd /var/www/limen && grep -rn 'turnstile\|captcha\|recaptcha' app/Http/Controllers/Auth/ | head -20

# Verificar o middleware
cd /var/www/limen && grep -rn 'turnstile\|captcha' app/Http/Middleware/ | head -10

# Ver erros de login no log
cd /var/www/limen && grep -i 'captcha\|turnstile\|pobre' storage/logs/laravel.log | tail -20
```

**Fix provável (uma das opções):**
1. **Desligar Turnstile do login** (manter só no cadastro) — recomendado para UAT
2. **Usar chaves de teste do Turnstile** para o ambiente de dev:
   - Site key: `1x00000000000000000000AA` (sempre passa)
   - Secret key: `1x0000000000000000000000000000000AA` (sempre passa)
3. **Bypass por email de teste:** `if (str_ends_with($email, '@uat.limen.test')) skip captcha`

## 2. Blur preview muito agressivo

Resolução ≤40px torna a imagem irreconhecível. Precisa de ~80-120px para incentivar compra.

## 3. Imagens portrait cortadas

object-fit: cover corta fotos verticais. Precisa de object-fit: contain ou aspect-ratio responsivo.

---

# ═══════════════════════════════════════════════════════════════
# JÁ CORRIGIDO NESTA SESSÃO (achados da Fase 1 original)
# ═══════════════════════════════════════════════════════════════

> Você anotou estes achados na Fase 1. Vários JÁ foram resolvidos. Reteste para
> confirmar antes de reportar como bug.

- ✅ **#9.1 (cidade "3e" era aceita):** CORRIGIDO — autocomplete IBGE implementado,
  só aceita municípios reais (ver Fase 15, #150-151).
- ✅ **#9.3 (performer tem acesso ao /catalogo):** CORRIGIDO — home da performer
  é agora o catálogo de MEMBROS, logo leva para lá (ver Fase 11, #103-104).
- ✅ **Filtro de chat — evasão com espaços:** CORRIGIDO — PR #218, fuzzy() com
  separator entre letras. "t e m a t o", "p r o g r a m a" agora são bloqueados.
- ✅ **Nickname — evasão com espaços:** CORRIGIDO — "z a p" agora é bloqueado no
  nickname (canonical form spaceless em MemberNicknameService).
- ✅ **Token emoji quadrado:** CORRIGIDO — substituído por SVG icons em todo o app.
- ✅ **Live vídeo tela preta:** CORRIGIDO — retry 3×/350ms em enableLocalMedia.
- ✅ **Gift "false success":** CORRIGIDO — FailsValidationAsJson + guard http.js.
- ✅ **Admin 403:** CORRIGIDO — role='admin' (não 'moderator').
- ✅ **Gift split 75/25:** CORRIGIDO — agora é 80/20 (sem infra, como todas as outras).
- ⚠️ **#3 (nome do perfil dá 404 ao trocar):** slug do perfil muda quando o nome
  artístico muda, mas o link no catálogo pode cachear o antigo → 404. VERIFICAR
  se ainda ocorre.
- ⚠️ **#4/#5/#6 ("não achei" publicar conteúdo):** o fluxo existe em "Gerenciar →
  publicar". Retestar seguindo o card correto.
- ⚠️ **#7 (piso de preço):** confirmar no fluxo de publicação (piso de 5 tokens).
- ⚠️ **#9.2 (complete perfil pede foto já adicionada):** VERIFICAR se o cálculo de
  "perfil completo" ainda ignora a capa já enviada.

---

# ═══════════════════════════════════════════════════════════════
# NOTA SOBRE FEATURE FLAGS
# ═══════════════════════════════════════════════════════════════

Várias features estão atrás de flags (dark launch). Para testar, ligue no .env
de staging (limen.top) e rode `php artisan config:clear`:

- **FEATURE_LIVE_ENABLED=true** → Fase 9 (live) e chamada pública
- **FEATURE_CALL_ENABLED=true** → Fase 9.2/9.3 (chamada privada) e Fase 12 (agendamento)
- **CAPTCHA_PROVIDER=turnstile** → já ativo (Fase 16.1)
- **LANDING_PRELAUNCH=true** → já ativo (Fase 17)

Depois dos testes, volte as flags de live/call para false se não for manter.

---

# ═══════════════════════════════════════════════════════════════
# FIXES PENDENTES (prompts para Claude Code)
# ═══════════════════════════════════════════════════════════════

## Prompt 1: fix/uat-login-pobre (CRÍTICO)

Investigar e corrigir o login de pobre@uat.limen.test. A conta existe (ID=167,
active, consumer, verified). Provável causa: Turnstile captcha no formulário de login.

## Prompt 2: fix/uat-content-and-gifts (6 itens)

1. Blur preview menos agressivo (~80-120px em vez de ≤40px)
2. Corte de imagens portrait (object-fit)
3. Histórico do membro com nome da performer que recebeu
4. Layout dos filter pills no extrato da performer (sem scroll horizontal)
5. Gift acessível do perfil (fora da live)
6. Barra de ações do perfil — polimento visual

## Prompt 3: fix/uat-extrato-performer-pills

Filter pills do extrato da performer precisam de layout wrap (não scroll).
