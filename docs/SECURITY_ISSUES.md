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

---

## Sprint 9 — Pré-análise de Segurança: Foto Efêmera de Membro e Stories da Performer

**Revisado em:** 24/07/2026 · **Status:** 🔵 PRÉ-IMPLEMENTAÇÃO — nada codificado.
**Escopo:** as duas features de mídia registradas no backlog do Sprint 9
(`docs/MASTER_HANDOFF_FINAL.md`, Apêndice A).

Não é uma issue a abrir contra o código: é o registro das restrições que o desenho
tem que respeitar **antes** da primeira linha. Duas decisões de produto (§ final)
travam a Feature 2.

**Veredito:** as duas são construíveis, nenhuma das duas é "mais uma tela". A
Feature 2 é o módulo de conteúdo chegando por porta lateral — e o backlog, quatro
linhas acima dela no próprio `MASTER_HANDOFF_FINAL.md`, já decidiu: *"Módulo de
conteúdo — quando existir, construir moderação e pipeline de verificação **antes**
do primeiro upload."* Stories é o primeiro upload.

---

### FEATURE 1 — Foto Efêmera do Membro

#### 🔴 1.1 O rosto não atravessa o `FanAlias` — ele o revoga, e entre perfis

Não há resposta técnica para "como impedir a performer de correlacionar
alias ↔ rosto". A foto chega numa conversa já amarrada a um `member_id`: a
performer vê *"Fã #0042 me mandou um rosto"*. A ligação é feita por construção.

O achado não-óbvio é outro. `FanAlias.php` deriva o pseudônimo por PAR justamente
para que *"o mesmo membro seja um número diferente para cada performer, então nada
correlaciona entre perfis"*. **O rosto é uma chave de join global:** duas
performers que receberam foto do mesmo membro comparam as imagens fora da
plataforma e desfazem exatamente o isolamento cross-perfil que o `FanAlias`
existe para dar. A feature não enfraquece o pseudônimo local — anula a
propriedade central do desenho.

**Decisão:** parar de tratar como feature de privacidade. É **des-anonimização
consentida**, e a UI diz isso no momento do envio, não nos Termos: *"a performer
verá seu rosto ligado ao seu pseudônimo — isso não expira."* O TTL protege o
arquivo, não a memória nem o print. Mesma disciplina de linguagem já imposta ao
painel de visitantes e ao geobloqueio: **não descrever como "a performer não
guarda sua foto"**. Concretamente: cap de performers com acesso ativo simultâneo,
e mostrar ao membro *"você compartilhou sua foto com N performers"* — não
previne, põe o agregado na frente de quem carrega o risco.

#### 🔴 1.2 "Indicador de tempo restante" reintroduz o oráculo de horário

Um countdown *"expira em 71h48"* com TTL de 72h devolve `granted_at` ao minuto —
e pior que o caso original do painel de visitantes, porque o TTL vem de um menu de
três opções e a performer conhece a base da subtração exatamente. É o oráculo do
`visited_at`, que o projeto já pagou para fechar (CLAUDE.md, privacidade do
membro, item 12), voltando por uma barra de progresso.

**Decisão:** faixa, não relógio — reutilizar o vocabulário de
`ProfileVisitService::slot()` ou equivalente (*"expira hoje"*, *"expira em alguns
dias"*). E **não exibir o TTL escolhido**: 24h vs 7 dias é postura do membro e é
sinal por si só.

#### 🔴 1.3 TTL aplicado no lugar errado — job parado vira acesso indefinido

Se o único mecanismo que corta o acesso é o job apagando o arquivo, falha do job
não custa disco: custa privacidade.

**Decisão:** **expiração verificada na LEITURA; o job é só garbage collection.**
O precedente já existe no projeto — `ChatAccess` confere a janela no acesso e
`PurgeExpiredChatAccess` apenas recolhe. Complemento: o comando reporta quantos
arquivos estão vencidos-e-ainda-presentes; número persistente não-zero é o alarme
(hoje não há alerta de job em lugar nenhum).

*Consistência:* o repo tem 2 Jobs enfileirados (ambos e-mail) e 9 comandos em
`routes/console.php`. `DeleteExpiredMemberPhotos` deve ser
`Schedule::command(...)->hourly()->withoutOverlapping()`, no padrão de
`visits:purge` — não um Job.

#### 🔴 1.4 EXIF/GPS — nada no repo remove metadado de imagem hoje

Foto de celular carrega coordenadas GPS e identificador de dispositivo. Um membro
que manda uma "foto privada efêmera" entrega as coordenadas de casa,
permanentemente, num arquivo que a performer pode baixar antes do TTL. É maior que
o TTL, e não estava na lista original de vetores.

**Decisão:** **re-encodar a imagem no servidor** para um JPEG canônico na
ingestão — mata EXIF e polyglot no mesmo passo, porque o arquivo servido deixa de
ser o arquivo enviado. Somar `X-Content-Type-Options: nosniff` e `Content-Type`
derivado de re-sniff no servidor, nunca do upload.

#### 🔴 1.5 `DeletionService` não cobre — e faltam os dois lados

`collectFilePaths()` enumera KYC e mídia de perfil por lista fixa de colunas.
São três passos novos, não um: purgar `member_photos` do titular; purgar
`member_photo_access` **recebidos** quando quem encerra é a performer (o análogo
exato de `purgeVisitsToOwnProfile()`); e coletar os caminhos para o `deleteFiles()`
pós-commit. Vale o aviso do item 11 do CLAUDE.md verbatim: **as FKs
`cascadeOnDelete` não disparam**, porque os dois lados são soft-delete.

#### 🔴 1.6 Sem quota, a feature é DoS de disco

Nada na spec limita quantidade. Custo medido nesta revisão:
**`Crypt::encryptString` tem overhead de 1.78x** (1 MiB → 1.86 MiB; base64
aplicado duas vezes na serialização). Com `max:5120` — o limite de
`UploadMediaRequest` — cada foto ocupa ~9 MB em disco.

**Decisão:** cap de fotos **ativas** simultâneas por membro (não de uploads
totais), `max:5120`, `throttle:` no endpoint (o projeto já usa 10/min em gorjeta),
e dimensionar disco com o 1.78x na conta.

#### 🔴 1.7 Backup de 14 dias sobrevive ao TTL de 24h

`docs/backup.sh` tarballa `storage/app/private` **e** `storage/app/kyc` com
`RETENTION_DAYS=14`. Uma foto com TTL de 24h que caia em qualquer disco coberto
sobrevive duas semanas no backup — cifrada por GPG, mas presente. "Expira em 24h"
vira falso na primeira noite.

**Decisão:** o disco de mídia efêmera fica **fora** do backup, explicitamente e
com comentário dizendo por quê. Mesma lógica de `profile_visits`: dado sem valor
fiscal nem trilha legal não entra em retenção longa.

#### ⚠️ 1.8 `member_photo_access` é metadado que sobrevive ao conteúdo

Dentro de um chat 1:1 já estabelecido o horário acrescenta pouco — a conversa já
identifica o par. O risco é de retenção: a tabela é **um mapa persistente de quem
mostrou o rosto para quem**, em claro, que sobrevive à destruição dos bytes.
`profile_visits` tem retenção de 7 dias exatamente por isso; a tabela de acesso,
como especificada, não tem retenção nenhuma.

**Decisão:** a linha de acesso morre junto com a foto. Se for preciso contador
para abuso, guardar o contador, não as linhas. `viewed_at` não vai para a
performer em hipótese alguma (é a ação dela) nem para o membro em relógio.

#### ⚠️ 1.9 APP_KEY: rotação é sobrevivível para as fotos, não para os aliases

`config/app.php` já configura `previous_keys` via `APP_PREVIOUS_KEYS`, então
`Crypt` decifra material antigo durante a rotação — fotos e KYC seguem legíveis.
**`FanAlias::digest()` não tem esse fallback**: usa `config('app.key')` direto.
Rotacionar rotaciona todos os pseudônimos, o que já é aceito, mas com efeito novo:
as fotos continuam decifráveis enquanto o alias de quem as enviou muda.

**Decisão:** disco próprio (`member_photos`), `serve => false`, **nunca** o disco
`kyc` — isolar blast radius e não confundir o `DeletionService`, que trata o disco
`kyc` como "destruir no encerramento".

#### ⚠️ 1.10 Não reusar `KycDocumentStore` — copiar a disciplina dele

O padrão de path do `KycDocumentStore` resolve traversal e vale copiar: nome vem
do chamador (não do usuário), extensão vem de `$file->extension()` (MIME
adivinhado, não o filename do cliente), sufixo `.enc`. **Mas em classe e disco
separados** (`MemberPhotoStore`): as regras de retenção divergem e o
`DeletionService` trata os dois discos de forma diferente.

#### ⚠️ 1.11 Interação com Modo Discreto, hoje indefinida

`ProfileVisitService::record()` barra o membro discreto porque discreto é "nunca
listado". Enviar foto é auto-listagem voluntária. Não deve ser bloqueado, mas
precisa ser **escrito como regra explícita** — senão alguém "conserta" isso
depois, em qualquer um dos dois sentidos possíveis.

---

### FEATURE 2 — Stories da Performer (Modelo C)

#### 🔴 2.1 Lista de "quem viu meu story" derruba o Piso de Anonimato inteiro

É a armadilha arquitetural nº 1 da feature, e é a superfície esperada do padrão
Instagram — vai ser pedida. É uma lista membro→performer **sem piso nenhum**,
exatamente o buraco que o painel de visitantes existiu para tapar. Agrava o item
da spec *"Black/FC podem ver Stories públicos de performers que não seguem
ainda"*: um membro aparecendo para a performer **sem nunca ter seguido**, com o
piso baseado em follows fora do circuito.

**Decisão:** ou não existe lista de viewers, ou ela passa por
`FollowerVisibilityService::canRevealList()` + `FanAlias` + faixa + k-anonimato,
igual ao painel de visitantes. **Recomendado: contagem agregada em faixa e nada
mais** — coerente com a regra 5 do CLAUDE.md ("contagem sempre em faixa, inclusive
para a própria performer"). Ver decisão pendente nº 1.

#### 🔴 2.2 Níveis 2 e 3 vazam o tier — e vazam de quem paga por invisibilidade

Story Nível 3 (Black/FC): qualquer sinal de audiência — lista **ou contador** —
prova que aqueles viewers são Black/FC. Mesmo sem lista, postar um Nível 1 e um
Nível 3 com minutos de diferença faz a **diferença entre os conjuntos** entregar o
tier. Como o `FanAlias` é estável, *"Fã #0042 viu meu exclusivo"* = *"Fã #0042 é
Black/FC"* = alvo de alto valor, carimbado permanentemente.

A contradição de produto merece ser dita em voz alta: **o tier que compra
invisibilidade** (`PrivacyPerkService::MIN_TIER = 'black'` — Ghost Mode, Status
Invisível, Read Receipts) **viraria o mais identificável da plataforma.** O Nível
3 vende ao Black o oposto do que o Black já comprou.

**Decisão:** sem contador e sem lista nos Níveis 2 e 3. Se a performer precisar de
métrica, agregar **através de todos os stories do dia**, sem quebrar por nível.

#### 🔴 2.3 Copiar o padrão `performer.media` destrói o paywall

`routes/api.php` serve mídia com `middleware('signed')` e **sem auth de sessão** —
a assinatura não amarra viewer nenhum. Correto para avatar público; fatal para
Story Nível 3: a URL assinada do membro Black é um **bearer token** que ele cola
no WhatsApp, e qualquer pessoa deslogada baixa o arquivo até a expiração. A
monetização do Modelo C — *"cria incentivo para assinar Círculo"* — evapora.

Agrava: `ExpireSubscriptions` roda de hora em hora, então o tier já é levemente
stale; URL assinada de longa duração empilha as duas janelas.

**Decisão:** rota de story media com `auth` + autorização **reavaliada a cada
request** (follow + tier resolvidos na hora, por um service). Se usar URL
assinada, ela é adicional e curtíssima — nunca substituta da checagem.

#### 🔴 2.4 O auto-delete de 24h é destruição de prova embutida no produto

Achado mais sério da Feature 2, e a moldura é interna: o `DeletionService`
**preserva `reports` intactos de propósito**, e diz por quê — *"apagá-la porque o
denunciado pediu exclusão daria ao infrator um botão para destruir a prova contra
si."* Stories dá esse botão a toda performer, automático, a cada 24 horas.

Estado atual: `Report::REPORTABLE_TYPES` = `['performer', 'message']` — Story não
é denunciável. E mesmo que fosse, denúncia na hora 23 seria revisada contra nada.
`ChatContentFilter` é texto puro e não alcança mídia.

**Decisão, em três partes:**
1. **Hash na ingestão** (SHA-256 + perceptual), guardado permanentemente. Prova
   "este arquivo exato foi publicado em T por X" se reaparecer, e habilita
   matching contra listas de hash conhecidas. Preserva evidência **sem** preservar
   conteúdo — que é a pergunta original.
2. **Story entra em `REPORTABLE_TYPES`**, e denúncia **congela o GC** daquele
   story.
3. ⚠️ Revisar conteúdo congelado esbarra no bloqueio que as FC Sessions já
   encontraram: `role` é enum `consumer|performer|admin`, então *"moderador que vê
   o story denunciado mas não vê KYC"* **não é expressável no modelo**. O refactor
   de roles é pré-requisito, não follow-up.

**CSAM não se resolve com "guarda para revisar"** — exige fluxo definido de
takedown e notificação à autoridade. A abordagem de hash é o que de fato permite
bloquear o re-upload.

#### 🔴 2.5 `media_path_encrypted` para vídeo bate no bloqueio das FC Sessions

`Crypt::encryptString` carrega o arquivo inteiro em memória e não permite seek nem
Range — sem scrubbing, sem resume, e cada view decifra o arquivo todo na RAM do
PHP. KYC tolera (um admin, raramente). Story é **1-para-muitos por definição**: N
viewers concorrentes × tamanho do arquivo em memória é DoS auto-infligido numa
feature desenhada para ter audiência simultânea.

**Decisão — e é o ponto que amarra as duas features:** elas **não devem
compartilhar estratégia de storage**, apesar de a spec dar a ambas uma coluna
`*_encrypted`. Foto de membro é 1-para-poucos, pequena, altamente sensível,
enviada sob expectativa de privacidade → cifrar vale. Story é 1-para-muitos,
maior, conteúdo que a performer **escolheu publicar** → cifrar compra pouco e
custa muito. Para Story: v1 só imagem, ou disco privado sem cifra com authz na
camada de serving (risco "quem tem SSH lê", que já é verdade para os avatares).
Envelope encryption + KMS é o caminho das FC Sessions — projeto, não feature.
Ver decisão pendente nº 2.

#### 🔴 2.6 `story_views` no `DeletionService`, nos dois sentidos

Estruturalmente idêntica a `profile_visits`: mapa de interesses membro→performer.
Hard delete quando o membro encerra **e** quando a performer encerra, mais
retenção curta (as views morrem com o story em 24h). Mesmas FKs que não disparam.

#### 🔴 2.7 Ghost Mode e Modo Discreto precisam do guard desde o dia 1, e no Service

Item 9 do CLAUDE.md: o guard vive em `ProfileVisitService::record()`, não nos
controllers, porque *"a terceira rota que aparecesse nasceria vazando"*.
`story_views` é a terceira rota. Vale também a regra da ausência de linha: **não
gravar a view marcada como oculta** — não criar coluna `hidden`.

#### ⚠️ 2.8 Job de expiração — mesma inversão do item 1.3, com agravante

Expiração verificada na leitura, job como GC. O agravante: um Story pode ter sido
denunciado. Se o job apaga na hora 24 e a denúncia entrou na hora 23, a evidência
some — ver 2.4, parte 2 (denúncia congela o GC).

#### ✅ 2.9 "Seguir é automático" NÃO quebra a mitigação de sybil

`applyFloorEligibility()` corta pela **conta** (7+ dias, e-mail verificado), não
pelo caminho do follow. Facilitar o seguir não ajuda a performer a plantar contas,
que é o modelo de ameaça do piso. E a spec descreve clique explícito — é o fluxo
de hoje, que já não tem aprovação. Praticamente um no-op.

**Decisão explícita: não adicionar `follows.source`.** Seria um atributo novo do
membro visível à performer ("este veio dos stories") e, pior, tentaria alguém a
filtrar o piso por origem — bifurcando um critério que o CLAUDE.md diz ter **uma
dona só** (`FollowerVisibilityService::applyFloorEligibility()`).

#### ⚠️ 2.10 Se "ver" virar "seguir", há problema de consentimento

Se abrir um Story auto-seguir, o membro que só queria espiar entra numa lista que
a performer pode ver e que o habilita a receber Interesse Controlado. A intenção
("ver um story") ≠ a consequência.

**Decisão:** ver Story **nunca** cria Follow. Seguir continua sendo clique
explícito.

---

### Decisões de produto — ⏸️ PENDENTE DE DECISÃO DO PO

As duas travam a Feature 2. Sem elas, não começar a implementação.

1. **Existe lista/contador de viewers de Story?**
   Recomendação da revisão: nenhuma lista; no máximo contagem agregada em faixa,
   nunca quebrada por nível de visibilidade. Ver 2.1 e 2.2 — qualquer sinal de
   audiência em Nível 2/3 vaza o tier do membro.

2. **Vídeo entra na v1 ou v1 é só imagem?**
   Recomendação da revisão: v1 só imagem. Vídeo cifrado com `Crypt` é DoS de
   memória (2.5); vídeo não cifrado é decisão de risco a registrar; envelope
   encryption é o projeto travado das FC Sessions.

---

### Ordem recomendada

1. **Feature 1 antes da Feature 2.** Escopo menor, risco contido, e força
   construir `MemberPhotoStore` + expiração-na-leitura + os passos do
   `DeletionService` que a Feature 2 reusa.
2. **Feature 2 não começa antes das duas decisões acima.**
3. **O refactor de `role`** destrava a moderação de Stories (2.4) e o Curador das
   FC Sessions de uma vez. Duas features travadas no mesmo pré-requisito.

---

Revisão conduzida em pré-implementação (julho/2026) antes de qualquer código.
Nenhum dos problemas listados existe no código atual — são riscos do desenho
proposto.
