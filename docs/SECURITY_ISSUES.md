# Security Issues — registro para abertura manual

Achados de revisão de segurança que ainda não viraram issue no GitHub (não há
`gh` CLI neste ambiente). Cada seção abaixo é o corpo de uma issue a abrir em
https://github.com/robsonlupo-dev/limen/issues/new — apague a seção quando a
issue existir, deixando o link no lugar.

---

## RESOLVIDO — Correlação de pseudônimos Membro # ↔ Fã #

**Severidade:** 🟡 Médio-Alto · **Fechado no Sprint 6** · Não abrir issue.

`'Fã #' . (consumer_id % 10000)` no dashboard de gorjetas e `'Membro #' . user_id`
na lista de seguidores viviam no mesmo espaço de ids, então Membro #12345 era
Fã #2345. Um membro abaixo do piso, ou em Modo Discreto, que mandasse uma gorjeta
entregava quatro dígitos do próprio id — e a lista de gorjetas não passa por piso
nenhum.

**Como ficou:** `app/Support/FanAlias.php`. Pseudônimo derivado por PAR
(performer_profile_id, member_id) via HMAC-SHA256 com a `APP_KEY` — o mesmo
membro é um número diferente para cada performer, e o alias não volta a ser id.
Fonte única das três telas (dashboard de gorjetas, seguidores, interesses
enviados), então elas continuam concordando entre si sem concordar com o id.

Respondendo aos pré-requisitos que esta seção deixou em aberto:

- **Estável, não rotativo.** A performer precisa reconhecer "o Fã #0042 de
  sempre" entre gorjetas — ela consegue contar quantas gorjetas ele mandou, e
  isso é o produto. Decisão do PO no Sprint 6.
- **Formato mantido em 4 dígitos** (não os 4 alfanuméricos propostos aqui): a
  tela não muda de cara. Consequência aceita: com poucas centenas de seguidores
  dois membros podem cair no mesmo rótulo. A UI **não** trata o alias como chave.
- **Chave é a `APP_KEY`**, que já mora no `.env` e nunca é versionada (CLAUDE.md
  § 5) — não foi criado salt novo. Rotacionar a `APP_KEY` rotaciona todos os
  pseudônimos: a performer perde o histórico, nada quebra.
- **O `member_id` cru NÃO trafega mais no POST.** Esta seção previa que ele
  continuaria (`SendInterestRequest`), e é por isso que a troca não podia ser só
  de exibição: com o id nas props do Inertia, o alias seria maquiagem — bastava
  ler o payload. A lista de seguidores agora manda `member_handle` (HMAC truncado
  em 16 hex) e o `SendInterestRequest` resolve handle→membro varrendo os
  seguidores listáveis do perfil. Efeito colateral bom: adivinhar handle é
  inviável, enquanto varrer ids era trivial — mas o Piso de Anonimato continua
  sendo a barreira de autorização, não a obscuridade do handle.

**Não mudou:** ledger (`reference_id` segue sendo o user_id), audit log e
qualquer coisa interna. Isto é camada de apresentação.

**Cobertura:** `tests/Unit/FanAliasTest.php` (determinismo, faixa, não-correlação
entre performers, alias ≠ id, resolução do handle restrita aos candidatos,
rotação de APP_KEY).

---

## Age Verification — nível atual e limitações conhecidas

**Implementado em:** 20/07/2026 · **Branch:** `age-verification`
(migration `2026_07_20_100001_create_age_verifications_table`)
**Status:** 🟠 PARCIAL — suficiente para documentar esforço, insuficiente para
auditoria robusta. Não é issue a abrir: é registro de escopo, para que ninguém
(nós inclusive) descreva este controle como mais forte do que ele é.

Contexto: o `limen_age_confirmed` é gate de navegação pública, não verificação —
o 18+ de cadastro já era server-side via `birthdate` antes desta entrega. O que
mudou é a coleta de CPF e o registro auditável.

### O que está implementado

- CPF estruturalmente validado (dígitos verificadores, `app/Rules/CpfValido.php`).
- Data de nascimento autodeclarada, `>= 18` anos, rejeitada no dia anterior ao
  aniversário (o corte é hoje, não o ano).
- **CPF nunca persistido** — só o HMAC-SHA256 com a `APP_KEY`
  (`app/Support/CpfHash.php`), gravado em `age_verifications.cpf_hmac`.
- `method = 'cpf_dob'` distingue este nível de verificações futuras.
- `cpf_hmac` indexado, **não** unique: detecta conta duplicada, não bloqueia —
  bloquear é decisão de produto ainda em aberto.

### O que NÃO está implementado

- Consulta a base oficial (Serpro/DataValid) — prevista para o Sprint 7.
- Prova de que o CPF pertence a quem se cadastrou.
- Prova de que a data de nascimento confere com o documento.

Consequência prática: o algoritmo do CPF é público e gerador de CPF válido é
resultado de primeira página de busca. O registro prova que **um CPF
estruturalmente válido foi digitado**, não que a pessoa tem 18 anos.

### Redação defensável para auditoria

> "CPF estruturalmente validado + data de nascimento autodeclarada; consulta a
> base oficial prevista para o Sprint 7 (`method = 'cpf_dob'`)."

**NÃO** descrever para auditores como "verificação de CPF" sem essa ressalva —
descreveria algo mais forte do que o sistema faz hoje, e uma ressalva ausente
custa mais numa auditoria do que o controle fraco em si.

### Decisões de design

- **`users.age_verified_at` NÃO é marcado no cadastro de membro.** Aquela coluna
  é escrita só pelo `KycService`, quando um documento passou por provedor
  (Didit). Marcá-la também aqui faria qualquer `whereNotNull` tratar declaração
  como documento conferido — os dois níveis viram um bool indistinguível no
  dossiê. O sinal do membro mora em `age_verifications.method`.
- **Quando o Serpro entrar**, gravar `method = 'serpro'` na mesma tabela permite
  distinguir os dois níveis retroativamente, em vez de reescrever histórico.
- **HMAC, não hash puro:** o espaço de CPF (10¹¹) é enumerável em GPU. A chave é
  a `APP_KEY`, fora do Git, então um dump de banco isolado não permite a
  varredura. Vazando `APP_KEY` **e** banco, os CPFs são recuperáveis por força
  bruta — o modelo de ameaça aqui é dump de banco sozinho.
- **Performer não informa CPF no cadastro:** já entrega no KYC com documento e
  selfie; pedir duas vezes duplicaria coleta de PII sem ganho.

**Cobertura:** `tests/Feature/MemberAgeVerificationTest.php` — CPF inválido e
ausente rejeitados, menor de idade rejeitado (inclusive na véspera do
aniversário), caminho feliz, `age_verified_at` nulo, dedupe por HMAC, e uma
varredura de todas as colunas de texto de todas as tabelas confirmando que os
dígitos do CPF não sobraram em lugar nenhum.

---

## Aceite de documentos — IP em claro no `audit_logs`

**Severidade:** 🟢 Baixo · Registro de escopo, não bug. Abrir issue só se o PO
quiser fechar a lacuna.

`document_acceptances` guarda IP e user-agent como HMAC da `APP_KEY` (ver
`app/Support/ClientFingerprint.php`): nenhuma coluna crua, o valor não é
recuperável a partir de um dump do banco.

Mas o mesmo evento chama `Audit::log('performer_documents_accepted', ...)`, e
`app/Support/Audit.php` grava `'ip' => $request->ip()` **em texto puro**. Pelo
`user_id` os dois lados se correlacionam, então na prática o IP do aceite existe
em claro na tabela ao lado. A propriedade defendida na migration vale para
`document_acceptances`, não para o dossiê inteiro.

Não é regressão desta entrega — é o comportamento do `Audit` desde a fundação, e
o audit log tem justamente a função de guardar rastro. O que não pode é a
documentação prometer mais do que o sistema entrega.

**Saídas possíveis:** (a) hashear o IP também no `Audit` — mas aí todo o audit
log perde a leitura direta que o torna útil numa investigação; (b) política de
retenção que expurgue `audit_logs.ip` depois de N meses; (c) aceitar e declarar.
Decisão do PO.

### O que ESTÁ implementado no aceite

- Tabela `document_acceptances` append-only (o model recusa `update`), uma linha
  por (usuário, documento, versão), com unique que torna re-submeter idempotente.
- Versão vigente em `config/documents.php`; bumpar força re-aceite de todas as
  performers. A versão **nunca** vem do request.
- Middleware `documents.accepted` nas duas portas de auth: web (redirect) e API
  Sanctum (403 JSON). Ignora quem não é performer.
- Textos em `/politica-de-conteudo` e `/contrato-de-performance`, públicos.

### O que NÃO está implementado

- **O texto jurídico.** As duas páginas servem
  `[CONTEÚDO JURÍDICO — aguardando Opice Blum]`. O aceite registrado hoje aponta
  para a versão `2026-07-20`, que é placeholder: **não descrever para auditoria
  como "contrato aceito"** enquanto o texto não for o definitivo. Quando chegar,
  bumpar a versão no config é o que transforma o aceite em evidência real.
- Sem re-aceite periódico por tempo (só por mudança de versão).
- Sem trilha de recusa: quem não aceita simplesmente não passa, e não fica
  registrado que recusou.

**Cobertura:** `tests/Feature/PerformerDocumentAcceptanceTest.php` — 27 testes.

---

## Flag de IP de cadastro compartilhado — limites e decisões pendentes

**Severidade:** 🟡 Médio · Sinal implementado e sinalizando; três decisões são do
PO e uma é armadilha de infra. Abrir issue para os itens 1 e 2.

Performers cadastradas do mesmo IP recebem flag na fila de KYC do admin
(`GET /api/v1/admin/kyc` → `shared_registration_ip`). O IP entra como HMAC da
`APP_KEY` em `users.registration_ip_hash`; membro fica NULL (finalidade LGPD
declarada: proteger quem é recrutado para produzir conteúdo).

**Sinaliza, nunca bloqueia** — e essa parte é deliberada: bloquear puniria o
caso legítimo sem ninguém olhar.

### 1. Limiar de 1 conta + CGNAT = risco de afogar o sinal real

Hoje **uma** outra conta no mesmo IP já acende a luz (`others > 0`). No Brasil,
Vivo/Claro/TIM colocam milhares de assinantes móveis atrás de um mesmo IPv4
(CGNAT), e IPv4 residencial é rotativo — duas performers sem relação nenhuma
pegam o mesmo IP em semanas diferentes.

Consequência na direção contrária à finalidade: performers sem vínculo chegam à
fila rotuladas como possível rede de exploração, a revisora aprende a ignorar o
rótulo, e quando a rede real aparecer o sinal estará afogado em ruído. **Quem
paga o falso positivo é a pessoa que o recurso deveria proteger.**

O limiar de 1 foi especificado pelo jurídico (`count > 1`) e por isso está como
pedido. Mitigações possíveis, todas decisão do PO:
- janela temporal nos totais (mesmo IP com 6 meses de distância é DHCP, não rede);
- limiar configurável, 2+ outras contas como padrão;
- graduar o rótulo (2 = "possível", 4+ = "provável") em vez de booleano.

### 2. Entrar CDN na frente quebra a feature em silêncio

Não há `TrustProxies` no projeto e o nginx de produção fala direto com o php-fpm
por socket unix, então `$request->ip()` é o cliente real e `X-Forwarded-For`
enviado pelo cliente é ignorado (há teste travando isso).

Se um dia entrar Cloudflare/CDN, `ip()` passa a devolver o IP da borda **para
todo mundo**: 100% das performers colidem num hash só e a fila inteira nasce
sinalizada. E o "conserto" intuitivo (`trustProxies(at: '*')`) é pior — aí o
`X-Forwarded-For` vira campo escolhido pelo cliente, que passa a poder escapar do
flag ou apontar para o IP de outra performer e incriminá-la.

**Se a borda mudar:** `trustProxies` com lista explícita de faixas, nunca `'*'`.

### 3. `audit_logs` guarda o IP do cadastro em texto puro

`Audit::log('auth.register_performer')` roda no mesmo request e grava
`audit_logs.ip` cru. Quem tiver leitura do banco correlaciona performers por IP
**sem precisar da APP_KEY** — exatamente o que o HMAC existe para impedir. Mesma
lacuna já registrada na seção do aceite de documentos; aqui pesa mais, porque o
dado correlacionado é a hipótese de coerção.

### 4. Retenção não definida

O hash fica indefinidamente e não há expurgo. LGPD pede retenção limitada à
finalidade. Não tem conserto óbvio: apagar após a aprovação mata a detecção de
cadastros futuros contra contas já aprovadas. Precisa virar decisão registrada.

### 5. Conta já aprovada não é reavaliada

O flag só aparece na fila `pending`/`review`. Uma performer nova do mesmo IP de
uma já aprovada é sinalizada (o total varre a base toda), mas a **já aprovada**
não volta para revisão. Se a rede se forma depois, metade dela fica invisível.
Falta a contrapartida: alerta ou relatório periódico para o time de confiança.

### 6. Consultar o sinal não gera audit log

É um dado de suspeita sobre uma pessoa. Registrar quem olhou — e que a aprovação
foi decidida com o flag aceso — protege a performer e a plataforma numa disputa.

**Cobertura:** `tests/Feature/SharedRegistrationIpTest.php` — 13 testes, incluindo
soft delete não apagando o flag, `X-Forwarded-For` ignorado, membro no mesmo IP
não sinalizando, e o hash fora da serialização do usuário.
