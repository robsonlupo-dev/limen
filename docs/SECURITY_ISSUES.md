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
