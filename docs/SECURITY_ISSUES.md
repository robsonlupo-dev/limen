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

#### ✅ 1.4 EXIF/GPS — DECIDIDO (24/07/2026)

Foto de celular carrega coordenadas GPS e identificador de dispositivo. Um membro
que manda uma "foto privada efêmera" entrega as coordenadas de casa,
permanentemente, num arquivo que a performer pode baixar antes do TTL. É maior que
o TTL, e não estava na lista original de vetores.

> **Decisão do PO:** instalar `intervention/image` para re-encodar a foto na
> ingestão. Remove EXIF/GPS antes de cifrar. O processamento ocorre só no upload
> (uma vez por foto), impacto de performance negligenciável (<1s para 5 MB).

Acolhe a recomendação da revisão: o re-encode mata EXIF **e polyglot** no mesmo
passo, porque o arquivo servido deixa de ser o arquivo enviado. Somar
`X-Content-Type-Options: nosniff` e `Content-Type` derivado de re-sniff no
servidor, nunca do upload.

**Verificado neste ambiente (24/07/2026):**
- `intervention/image` **não está no projeto** — é dependência nova, primeira do
  tipo. Entra no `composer.json`, não é só código.
- **GD disponível, Imagick ausente** (PHP 8.4.23). A v3 do Intervention roda em
  GD, então funciona — mas **fixar o driver explicitamente** na config em vez de
  confiar no autodetect: se produção tiver Imagick e dev não, o mesmo upload
  produz bytes diferentes, e a divergência aparece como bug de imagem, não de
  ambiente.
- Extensão `exif` presente — útil para **testar** que o strip funcionou (asserção
  de que o arquivo re-encodado não tem tags GPS), não para fazer o strip.

⚠️ **Consequência que a decisão traz junto:** `intervention/image` passa a parsear
**arquivo controlado pelo atacante** via GD — é exatamente a superfície onde vivem
os CVEs de biblioteca de imagem (leitura fora de limites, exaustão de memória em
imagem-bomba). Dois complementos, nenhum deles opcional:
- **Limite de dimensões**, não só de bytes. Um PNG de 200 KB pode declarar
  30000×30000 e estourar a memória do PHP no `imagecreatefrom*` antes de qualquer
  validação de tamanho de arquivo adiantar.
- **`composer audit` hoje é `|| true` no CI** (registrado em A.3 do handoff).
  Adicionar uma dependência que processa entrada hostil enquanto o audit é soft
  significa que um advisory nela não quebra o build. Vale promover o audit a hard
  fail junto desta feature, não depois.

#### 🔴 1.5 `DeletionService` não cobre — e faltam os dois lados

`collectFilePaths()` enumera KYC e mídia de perfil por lista fixa de colunas.
São três passos novos, não um: purgar `member_photos` do titular; purgar
`member_photo_access` **recebidos** quando quem encerra é a performer (o análogo
exato de `purgeVisitsToOwnProfile()`); e coletar os caminhos para o `deleteFiles()`
pós-commit. Vale o aviso do item 11 do CLAUDE.md verbatim: **as FKs
`cascadeOnDelete` não disparam**, porque os dois lados são soft-delete.

#### ✅ 1.6 Quota de disco — DECIDIDO (24/07/2026)

Nada na spec limitava quantidade. Custo medido nesta revisão:
**`Crypt::encryptString` tem overhead de 1.78x** (1 MiB → 1.86 MiB; base64
aplicado duas vezes na serialização). Com `max:5120` — o limite de
`UploadMediaRequest` — cada foto ocupa ~9 MB em disco.

> **Decisão do PO:** máximo **5 fotos ativas simultâneas** por membro. Cap
> verificado **no submit**, não no job de limpeza.

Teto por membro: 5 × ~9 MB ≈ **45 MB**, e ele não cresce com o tempo — é o ponto
de contar ativas em vez de uploads totais.

**"No submit, não no job" é o que torna o cap um cap.** Verificar na limpeza
deixaria a janela aberta justamente onde o abuso acontece: o job roda de hora em
hora (item 1.3), então um laço de upload gravaria centenas de arquivos e só seria
podado no próximo tick — o disco já teria enchido. A checagem no submit é
autorização; a do job seria contabilidade tardia.

Dois detalhes que a implementação não pode reinterpretar:
- **"Ativas" = não expiradas e não revogadas**, contadas pelo mesmo critério que
  o serving usa (item 1.3: expiração é verificada na leitura). Se o cap contar
  linhas ainda presentes no disco mas já vencidas, o membro fica travado em 5
  fotos mortas esperando o GC — e o cap vira bug de produto, não defesa.
- **Contagem sob lock ou constraint**, não `count()` seguido de `insert()`. Dois
  submits concorrentes leem 4 e gravam os dois, e o cap de 5 vira 6. Mesmo padrão
  do `lockForUpdate` que o recovery code de 2FA já usa.

Mantidos da recomendação original: `max:5120` no Form Request, `throttle:` no
endpoint (o projeto já usa 10/min em gorjeta) e o 1.78x no dimensionamento de
disco.

#### ✅ 1.7 Backup vs TTL — DECIDIDO (24/07/2026)

`docs/backup.sh` tarballa `storage/app/private` **e** `storage/app/kyc` com
`RETENTION_DAYS=14`. Uma foto com TTL de 24h que caia em qualquer disco coberto
sobrevive duas semanas no backup — cifrada por GPG, mas presente. "Expira em 24h"
vira falso na primeira noite.

> **Decisão do PO:** o disco de fotos efêmeras fica **fora** do `backup.sh`,
> explicitamente. Perda aceitável e consistente com o produto — foto efêmera por
> design não deve persistir além do TTL. Adicionar a exclusão explícita no
> `backup.sh` **antes** da implementação.

Mesma lógica de `profile_visits`: dado sem valor fiscal nem trilha legal não entra
em retenção longa. E a perda em caso de restore é o comportamento correto, não um
efeito colateral — restaurar um backup de ontem devolveria fotos que o titular já
viu expirarem, o que é pior que perdê-las.

**"Antes da implementação" é a parte operacional, e é a que costuma escapar:** o
`backup.sh` não vive no deploy automático — o cabeçalho do próprio arquivo diz
"instalar em `/home/deploy/backup.sh` no servidor e agendar via cron". Editar o
arquivo no repo **não** altera o script que roda em produção. A ordem correta é:
1. escolher o caminho do disco **fora** de `storage/app/private` e de
   `storage/app/kyc` (dentro de qualquer um dos dois, o tar já o captura por
   diretório — nenhuma exclusão no repo salva);
2. atualizar `docs/backup.sh` com a exclusão e o comentário do porquê;
3. **substituir a cópia instalada no servidor** e conferir que o próximo tarball
   não contém o diretório novo.

Sem o passo 3 a decisão fica só no papel e a foto continua indo para o backup por
14 dias.

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
para a própria performer"). **Decidido pelo PO em 24/07/2026** — ver decisão nº 1.

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

**Decisão do PO (24/07/2026) — ✅ FECHADO, Opção B:** Nível 3 sem contador;
Níveis 1 e 2 com faixa de membros únicos. Detalhe e rationale na decisão nº 3 do
bloco de decisões, abaixo.

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
**Decidido pelo PO em 24/07/2026: v1 só imagem** — ver decisão nº 2.

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

### Decisões de produto — RESOLVIDAS pelo PO (24/07/2026)

Travavam a Feature 2. As três estão decididas; a implementação está liberada nos
termos abaixo.

1. **Lista/contador de viewers de Story — ✅ DECIDIDO**

   > Contador em faixa de membros únicos — mesmo padrão do contador de seguidores
   > (5+ / 10+ / 50+ / etc). Cada membro conta 1 vez independente de quantas vezes
   > abriu o Story. Performer não vê lista de quem viu, só a faixa de quantos
   > viram. Implementar via `story_views` com `DISTINCT member_id` antes de
   > aplicar as faixas.

   Acolhe a recomendação de 2.1 (sem lista de viewers). A faixa reusa
   `PerformerProfile::followersLabelFor()`, que já é a tabela vigente
   (`Menos de 5` / `5+` / `10+` / `50+` / `100+`, exato a partir de 500) — **não
   criar uma segunda tabela de faixas**, pela mesma razão que o critério de
   elegibilidade do piso tem uma dona só: duas tabelas divergem, e divergiriam no
   sentido permissivo.

   Detalhes que a decisão fixa e que a implementação não pode reinterpretar:
   - **`DISTINCT member_id` ANTES da faixa**, nunca depois. Faixar o total de
     aberturas devolveria "quantas vezes abriram", que é comportamento do membro,
     não audiência — e um membro que reabre 5 vezes empurraria sozinho a faixa de
     `Menos de 5` para `5+`.
   - **A faixa não substitui os guards de 2.7:** view de membro com Ghost Mode ou
     Modo Discreto não é gravada em `story_views`, então não conta para o
     `DISTINCT`. Guard no service, não no controller.
   - **`story_views` continua sendo tabela de correlação** mesmo sem lista na UI:
     valem 2.6 (hard delete nos dois sentidos do `DeletionService`) e a retenção
     curta. A decisão fecha a superfície de exibição, não a de banco.

   A ressalva que esta decisão deixou aberta — o contador por nível de
   visibilidade — foi fechada na decisão nº 3, abaixo.

2. **Vídeo na v1 — ✅ DECIDIDO**

   > V1 aceita apenas imagem (jpeg/png). Vídeo entra no Sprint 10 após preparar
   > a estratégia de serving sem cifra em memória.

   Acolhe a recomendação de 2.5 e neutraliza o DoS de memória do
   `Crypt::encryptString` na v1. Consequências para a implementação:
   - Form Request com `mimes:jpeg,png` (mesmo conjunto do `SubmitKycRequest`),
     validado no servidor — não por extensão do filename.
   - O re-encode do item 1.4 vale aqui também: imagem publicada por performer
     também carrega EXIF/GPS, e um Story revela a localização de quem tem KYC na
     plataforma. **Strip obrigatório**, não opcional.
   - A estratégia de serving do Sprint 10 é o pré-requisito, não um follow-up:
     ver 2.5 (envelope encryption é o caminho travado das FC Sessions) e 2.3 (a
     rota autenticada com authz por request vale igual para vídeo).

3. **Contador por nível de visibilidade — ✅ DECIDIDO (Opção B)**

   > Nível 3 sem contador. Stories Exclusivos (Black/FC) não exibem contador de
   > visualizações. Níveis 1 e 2 exibem faixa de membros únicos normalmente.
   >
   > **Rationale:** consistente com Ghost Mode e Status Invisível do Black —
   > membro que pagou por invisibilidade não aparece em nenhum contador, nem
   > agregado. A performer sabe que o conteúdo foi entregue aos membros premium
   > mas não sabe quantos viram.
   >
   > **Implementação:** `StoryViewService` verifica o nível antes de retornar o
   > contador — Nível 3 retorna `null`, **não zero**.

   Fecha o vazamento de tier de 2.2: sem contador no Nível 3, não há par de
   números para subtrair, e o ataque de diferença de conjuntos (postar Nível 1 e
   Nível 3 juntos e comparar) fica sem o segundo operando.

   **`null` e não zero, e a distinção é de segurança, não de estilo.** Zero é um
   valor no mesmo domínio da faixa: uma tela que trate `0` como "ninguém viu"
   afirmaria algo falso sobre a audiência, e é o mesmo erro que a copy do painel
   de visitantes evita de propósito ("Nada a mostrar", deliberadamente ambígua,
   porque distinguir "zero" de "abaixo de k" já diria à performer que alguém
   passou). `null` significa *"esta pergunta não é respondida neste nível"* —
   estado distinto de toda contagem possível. A UI renderiza ausência, não `0`.

   **Não vaza pela ausência:** a performer escolheu o nível ao postar, então ela
   já sabe por que não há contador ali. A ausência é função do nível, que é dado
   dela — não do conjunto de quem viu.

   **Nível 2 mantém contador e isso é coerente**, não uma inconsistência: Nível 2
   é "qualquer Círculo ativo", e `PrivacyPerkService::MIN_TIER = 'black'` — ser
   assinante de algum tier não é a categoria que comprou invisibilidade. O que a
   faixa do Nível 2 revela é quantos seguidores assinam alguma coisa, que é
   métrica de negócio legítima. A linha do corte cai exatamente onde o perk
   começa.

   **O guard vive no `StoryViewService`**, não no controller nem no componente
   Vue — mesma razão do item 2.7 e do item 9 do CLAUDE.md: a segunda superfície
   que pedisse o contador (API, painel, export de métricas) nasceria vazando.
   Filtrar no front seria pior ainda: o número trafegaria nas props do Inertia e
   bastaria abrir o DevTools — o mesmo erro que o `FanAlias` evitou ao tirar o
   `member_id` cru do payload.

---

### Ordem recomendada

1. **Feature 1 antes da Feature 2.** Escopo menor, risco contido, e força
   construir `MemberPhotoStore` + expiração-na-leitura + os passos do
   `DeletionService` que a Feature 2 reusa.
2. **Feature 2 destravada.** As três decisões de produto estão fechadas
   (24/07/2026) e nenhuma questão de escopo segue aberta. O que resta é
   engenharia: os 🔴 desta seção continuam valendo como requisitos.
3. **O refactor de `role`** destrava a moderação de Stories (2.4) e o Curador das
   FC Sessions de uma vez. Duas features travadas no mesmo pré-requisito.

---

Revisão conduzida em pré-implementação (julho/2026) antes de qualquer código.
Nenhum dos problemas listados existe no código atual — são riscos do desenho
proposto.

---

## Sprint 9B — Revisão pós-implementação da Foto Efêmera (29/07/2026)

Revisão de segurança sobre a branch `feat/sprint9b-ephemeral-photos`, que entrega
a camada de service/model da Feature 1 (sem controller, rota, Form Request nem
Policy — esses são o PR seguinte).

### Corrigido nesta branch

1. **`destroy()` soft-deletava a linha com o arquivo ainda no disco.** O disco
   roda com `throw => false` e o retorno de `Storage::delete()` não era
   conferido: uma permissão errada num deploy fazia o GC "limpar" em silêncio,
   e a linha soft-deletada saía do escopo padrão — nenhuma rodada futura voltava
   a olhar aquele arquivo. O rosto do membro ficava no volume indefinidamente com
   o alarme `stale` marcando zero. Agora `MemberPhotoStore::delete()` lança
   quando o disco recusa (apagar caminho inexistente segue sendo sucesso), e
   `purgeExpired()` varre com `withTrashed()`.
2. **`Storage::put()` sem checar retorno** — disco cheio devolvia `false` e o
   Store entregava um caminho válido para bytes que nunca foram gravados.
3. **Sem guard de propriedade em `grantTo`/`readForMember`/`readForPerformer`.**
   Os três recebem o ATOR e conferem o vínculo no Service. Com route-model
   binding, `readForPerformer($access)` entregava a foto que o membro mandou para
   OUTRA performer e carimbava `viewed_at` na conta errada.
4. **Teto de imagem-bomba de 50 MP → 4 MP.** 49 MP pedem ~200 MB no GD, e
   estourar `memory_limit` é fatal error (não `Throwable`): o worker morre, o
   temporário em claro fica órfão em `/tmp`. Como a saída é `scaleDown(1200)`,
   o teto antigo não comprava resolução nenhuma.
5. **`DeletionService` não cobria as fotos** (§ 1.5, era 🔴): o Hard Delete LGPD
   deixava o rosto no disco por até 7 dias. Agora `purgeMemberPhotos()` (linhas,
   acessos e bytes, hard delete) e `purgePhotoAccessToOwnProfile()` (o análogo de
   `purgeVisitsToOwnProfile()` para quando quem encerra é a performer).
6. **`MemberPhoto`: `user_id` e `expires_at` em `$hidden`** — o primeiro é o
   `member_id` cru que o `FanAlias` tira de circulação; o segundo é relógio, e
   com o TTL vindo de um menu de três opções devolve `granted_at` ao minuto.
7. **`MemberPhotoAccess`: FKs e `expires_at` fora do `$fillable`** — o prazo é
   derivado do clamp, e aceitá-lo por payload fura a regra "acesso não sobrevive
   ao conteúdo".

### Segunda rodada de revisão (29/07/2026) — o que a verificação pegou

A revisão do commit de correções confirmou 4 dos 6 itens sem ressalva e apontou
três coisas, todas corrigidas em seguida:

- **`destroy()` continuava sem ator** — é o quarto verbo da mesma classe e é o
  que o endpoint de revoke vai chamar. Com route-model binding em
  `DELETE /fotos/{photo}` e um controller que só delega, qualquer membro apagaria
  a foto de outro. Agora existe `destroyForMember()`; `destroy()` segue como
  primitivo de sistema (GC e `DeletionService`).
- **Teto de 4 MP ficava ABAIXO da entrada primária do produto.** 4 MP são
  2000x2000 e a foto de um iPhone (4032x3024 = 12,2 MP) seria recusada, sem o
  membro ter como redimensionar. Subiu para 13 MP (~55 MB de pico no GD), com a
  fórmula escrita no config. **Pendência: ninguém verificou o `memory_limit` real
  do php-fpm em produção**, que é o número do qual essa conta depende.
- **A troca do `updateOrCreate` por escrita explícita introduziu uma race.** O
  `updateOrCreate` cai em `createOrFirst()`, que trata a violação do índice único
  re-consultando; o select-then-insert cru devolvia 500 em duplo clique. Sair do
  `updateOrCreate` foi necessário (com `expires_at` fora do `$fillable` ele
  descartaria o prazo em silêncio), então ficou a escrita explícita com `catch`
  da `UniqueConstraintViolationException`.

Também nesta rodada: a linha da foto passou a sair em **hard delete**, e só
depois de confirmar que os bytes saíram do disco. A linha morta guardava
`user_id`, `size_bytes` e `created_at` indefinidamente — "o membro X mandou 43
fotos, nestes horários" —, e a varredura `withTrashed()` do GC decifraria
`path_encrypted` de todas elas a cada hora só para pular. `deleted_at` fica como
estado intermediário (linha ida, bytes de pé), que é o que a varredura recolhe.

### Follow-ups aceitos pelo PO — NÃO corrigidos nesta branch

- **Arquivo órfão no disco — repriorizar.** Os bytes são gravados fora da
  transação e a compensação é só o `catch`: timeout, OOM ou SIGKILL entre a
  gravação e o commit deixam arquivo cifrado SEM linha. Como todo o GC parte da
  tabela, esse arquivo nunca é recolhido — retenção infinita, com o id do titular
  legível no nome do diretório.
  **O Hard Delete de conta agravou isto:** as linhas saem por `forceDelete()`
  dentro da transação e os bytes depois, em best-effort. Se o disco recusar, o
  encerramento termina com sucesso e o rosto fica no volume sem nenhuma linha em
  lugar algum — antes, o GC ainda o pegaria em até 7 dias pelo TTL. Hoje o único
  sinal é o `Log::warning` do `deleteFiles()` (com `deletion_log_id`, sem
  caminho). Enquanto a varredura não existir, a mitigação barata é o
  `deleteFiles()` conferir `exists()` depois do delete e adiar o `forceDelete()`
  da linha para a rodada seguinte — o `deletions:process` é diário e idempotente.
- ~~**`grantTo()` não exige relação entre o membro e a performer de destino**~~ —
  **RESOLVIDO em 29/07/2026**: o PO fechou a regra (chat ativo) e ela está em
  `MemberPhotoService::shareWith()`. Ver a seção do gate mais abaixo.
  `grantTo()` continua sem o gate de propósito — é o primitivo que `shareWith()`
  usa depois de checar. Chamador novo entra por `shareWith()`.
- **Cap de performers por foto (§ 1.1).** `grantTo()` não limita com quantas
  performers a mesma foto é compartilhada, e o agregado "você compartilhou sua
  foto com N performers" não existe. É a única mitigação registrada do risco
  central da feature: o rosto como chave de join entre perfis.
- **Nenhum `audit_log` no fluxo.** Upload, grant, revoke e leitura pela performer
  não deixam trilha. É a única que sobraria depois que os acessos são apagados no
  TTL (§ 1.8). Quando entrar: id da foto/acesso e nada mais — sem caminho, sem
  nome de arquivo, sem bytes.
- **`composer audit` continua `|| true` no CI** (`.github/workflows/deploy.yml`).
  Este é o PR que adiciona `intervention/image`, a primeira dependência que
  parseia arquivo controlado pelo atacante; o § 1.4 recomendava promover o audit
  a hard fail junto da feature.

### Bloqueadores para o PR dos endpoints — FECHADOS (29/07/2026)

Entregues em `feat/sprint9b-photo-endpoints`, junto dos quatro endpoints e da UI:
Form Request com `max:5120` e o TTL restrito ao menu; `throttle:10,1` no upload;
rotas novas dentro dos grupos que já carregam `2fa` e `documents.accepted`;
`response()->json()` explícito em toda resposta que o front consome;
`Content-Type` do serving por re-sniff no servidor (`finfo` sobre os bytes
decifrados, allowlist de `image/jpeg`), `Content-Disposition: inline` com nome
GENÉRICO e `Cache-Control: no-store`; nenhuma URL assinada — a autorização é por
sessão, a cada request.

Três coisas que só apareceram ao escrever os endpoints, e que ficam registradas:

1. **Validação em rota web não vira JSON.** `shouldRenderJsonWhen` só liga o
   JSON em `api/*`, então uma `ValidationException` num endpoint web vira
   redirect-com-erros-de-sessão mesmo com `Accept: application/json` — e o
   `fetch` do front receberia HTML. Resolvido com o trait
   `Web\Concerns\FailsValidationAsJson`, no formato que o Laravel usa em `api/*`.
   **Vale para todo endpoint web novo que o JavaScript consumir.**
2. **`SubstituteBindings` roda ANTES do middleware de rota.** Um teste de gate
   com id inexistente recebe 404 do binding e passa sem nunca exercitar o gate.
   Os testes de `role` e de `2fa` usam id existente de propósito.
3. **O serving distingue 403 (acesso de outra performer) de 404 (vencido)**, a
   pedido do PO. O custo registrado: 403 vs 404 diz que aquele id de acesso
   existe. É informação fraca — não diz de quem para quem, e a mensagem segue
   uniforme —, mas se incomodar, o conserto é 404 nos dois.

### Gate de compartilhamento — RESOLVIDO (decisão do PO, 29/07/2026)

O item aberto acima ("`grantTo()` não exige relação entre o membro e a performer
de destino") está fechado: **o membro só compartilha com performer com quem tem
chat ativo**, e o gate vive em `MemberPhotoService::shareWith()`.

Uma consequência de implementação que a decisão não previa e que ficou decidida
no código: **"chat ativo" pergunta ao `ChatAccessService` se o membro pode ENVIAR
mensagem agora (`can_send`)**, em vez de consultar `chat_access` na mão. O
assinante de Círculo tem chat livre e **não gera linha** naquela tabela — a
leitura literal ("existe `ChatAccess` não expirada") recusaria justamente quem
paga mais (coberto por teste, porque é a justificativa do desenho). Carência
(`grace`) não passa: quem não pode nem responder não recebe rosto novo.

O gate replica as **duas** portas de `ChatService::sendMessage()` — o
`can_send` e o `conversation->status === 'active'` — e recusa também performer
suspensa, pendente ou encerrada, sempre com a mesma resposta (`no_active_chat`),
para não devolver ao membro o estado da conta dela.

> **Follow-up: as duas portas são uma CÓPIA, e cópia diverge.** O certo é um
> `canMemberSend(Conversation, User)` com uma dona só, na mesma disciplina de
> `FollowerVisibilityService::applyFloorEligibility()`. Não foi feito porque
> `sendMessage()` distingue as duas falhas em exceções diferentes
> (`conversationArchived` vs `accessRequired`) e unificar mudaria a resposta do
> chat. Enquanto não for unificado, **regra nova no envio de mensagem tem de ser
> replicada em `MemberPhotoService::shareWith()`** — foi assim que o
> `status === 'active'` passou batido na primeira versão.
