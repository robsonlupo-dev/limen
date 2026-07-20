# Legal Gap Analysis — Limen
Data: 20/07/2026 | Auditor: Claude Code

Auditoria de código feita após a reunião com o escritório Opice Blum. Percorridos
`app/Http/Controllers/`, `app/Services/`, `app/Models/`, `app/Support/`,
`database/migrations/`, `routes/`, `resources/js/Pages/` e `docs/`.

**Nada foi implementado nesta passagem** — este documento só registra o que existe,
o que existe pela metade e o que não existe.

Legenda: ✅ EXISTE · 🟡 PARCIAL · 🔴 FALTA

> **Nota transversal que muda a leitura de tudo abaixo:** a plataforma **não tem
> módulo de publicação de conteúdo**. Não existe model de post, feed, vídeo ou
> mídia paga — só `avatar_path` e `cover_path` no perfil da performer. A matriz de
> capacidade já registrava isso; a varredura confirma. Consequência: vários itens
> de "conteúdo" abaixo estão marcados 🔴 FALTA porque **a superfície que eles
> protegeriam ainda não existe**. Isso é uma janela, não um conforto — a hora de
> construir moderação e pipeline de verificação é *antes* do primeiro upload, não
> depois.

---

## 1. Age Verification (ECA Digital)

| Item | Status |
|---|---|
| Age gate de navegação (cookie) | ✅ EXISTE |
| CPF + data de nascimento no cadastro de membro | ✅ EXISTE |
| Verificação contra base oficial (Serpro/DataValid) | 🔴 FALTA |
| KYC com documento para membro (não só performer) | 🔴 FALTA |
| Registro auditável do método de verificação | ✅ EXISTE |

**Age gate (cookie)** — `resources/js/Components/AgeGateModal.vue` grava
`limen_age_confirmed` por 365 dias (cookie não-httpOnly, isento da criptografia de
cookie do Laravel para o servidor conseguir lê-lo). O `GuestLayout` decide se
renderiza; usuário logado nunca vê. O próprio arquivo declara em comentário que é
**controle de UI/UX, não verificação** — a redação está correta e deve ser mantida
assim em qualquer material para auditor.

**CPF + data de nascimento** — `app/Http/Requests/Web/RegisterWebRequest.php:41,56`.
CPF é `required_if:role,consumer` (performer não informa no cadastro: entrega no
KYC com documento). Validação de dígitos verificadores em `app/Rules/CpfValido.php`.
Data de nascimento com `before_or_equal` de 18 anos — o corte é hoje, não o ano, então
o dia anterior ao aniversário é rejeitado corretamente.

**Registro auditável** — a tabela `age_verifications` existe
(`2026_07_20_100001`), com `method`, `cpf_hmac`, `verified_at`, `user_id` unique.
Escrita em `app/Services/AuthService.php:43`. O CPF **nunca é persistido em texto
puro**: só HMAC-SHA256 com a `APP_KEY` (`app/Support/CpfHash.php`). O índice em
`cpf_hmac` **não é unique** — detecta conta duplicada, não bloqueia; bloquear é
decisão de produto ainda aberta.

**Limitação que o documento precisa dizer em voz alta:** `method = 'cpf_dob'`
significa *CPF estruturalmente válido + data autodeclarada*. O algoritmo do CPF é
público e gerador de CPF válido é resultado de primeira página de busca. O registro
prova que **um CPF estruturalmente válido foi digitado**, não que a pessoa tem 18
anos, nem que o CPF é dela. `docs/SECURITY_ISSUES.md` §"Age Verification" já traz a
redação defensável para auditoria — usar aquela, não "verificação de CPF" seca.

Decisão de design que sustenta o ECA Digital em auditoria futura:
`users.age_verified_at` **não** é marcado no cadastro de membro (só o `KycService`
escreve ali, quando um documento passou por provedor). Sem isso, qualquer
`whereNotNull` trataria declaração e documento conferido como o mesmo bool.

---

## 2. Creator Verification Pipeline

| Item | Status |
|---|---|
| KYC com documento (RG/CNH) para performer | ✅ EXISTE |
| Liveness check / selfie no KYC | 🟡 PARCIAL |
| Face match documento vs selfie | 🟡 PARCIAL |
| Registro de sessão KYC no banco (`identity_verifications`) | ✅ EXISTE |
| Vínculo entre conteúdo postado e pessoa verificada | 🔴 FALTA |
| Revalidação periódica de performers | 🔴 FALTA |
| Flag de IP compartilhado entre performers | 🔴 FALTA |

**KYC documental** — `app/Http/Requests/SubmitKycRequest.php` exige
`document_type in:cpf,rg,cnh`, `document_front` (obrigatório), `document_back`
(opcional) e `selfie` (obrigatória), jpeg/png até 10 MB. `identity_verifications`
guarda `document_number`, `full_legal_name` e `date_of_birth` com cast `encrypted`
(`app/Models/IdentityVerification.php:20-22`); os arquivos vão para o disco privado
`kyc` cifrados com `Crypt` e sufixo `.enc`
(`app/Services/Kyc/KycDocumentStore.php`). Aprovação/rejeição gravam `reviewed_by`,
`reviewed_at` e linha de `audit_log` (`app/Services/KycService.php`).

**Liveness e face match — 🟡 e o motivo importa.** A selfie é coletada e a decisão
vem da Didit (`app/Services/Kyc/DiditKycClient.php`), mas **quais checagens rodam é
propriedade do `workflow_id` configurado no painel da Didit, não do nosso código**.
Nada no repositório prova que liveness ou face match estão ligados; o cliente só lê
`Approved`/`Declined` e mapeia para `approved`/`rejected`. Para a auditoria isso é
uma afirmação **não verificável a partir do código** — precisa de evidência
documental do painel da Didit anexada ao dossiê. Enquanto não houver, não descrever
como "com liveness".

**Vínculo conteúdo ↔ pessoa verificada — 🔴, e é o gap mais estrutural da seção.**
Não existe pipeline porque não existe publicação de conteúdo. Quando existir, hoje
não há nenhum dos mecanismos: nem aprovação manual, nem hash de vídeo de
verificação, nem re-checagem no upload. O que existe é `is_verified` no perfil,
setado uma vez na aprovação do KYC — um booleano permanente que não distingue "esta
pessoa foi verificada um dia" de "esta pessoa é quem está publicando agora".

**Revalidação periódica** — não há campo de expiração de KYC, comando agendado ou
job. Uma aprovação de 2026 vale para sempre.

**IP compartilhado entre performers** — não há coluna de IP de cadastro nem de
último acesso em `users`. `audit_logs.ip` existe e guardaria o rastro, mas nada
consulta esse campo procurando colisão entre performers. Detecção de rede de
exploração hoje é impossível sem query manual.

---

## 3. Content Safety

| Item | Status |
|---|---|
| Página de política de conteúdo proibido | ✅ EXISTE (texto placeholder) |
| Aceite gravado no banco | ✅ EXISTE |
| Fluxo de denúncia por membros | 🔴 FALTA |
| Fila de moderação para conteúdo reportado | 🔴 FALTA |
| Remoção de conteúdo por admin | 🔴 FALTA |
| Hash database de CSAM (PhotoDNA/NCMEC) | 🔴 FALTA |
| Watermark automático em mídias | 🔴 FALTA |

**Política e aceite — a parte boa, e está bem construída.**
`app/Http/Controllers/Web/LegalDocumentsController.php` serve os dois textos
(Política de Conteúdo Proibido e Contrato de Performance) em **rota pública**, de
propósito: a performer precisa ler antes de ter conta e o link precisa continuar
abrindo depois. `document_acceptances` (`2026_07_20_100002`) é append-only por
convenção, com unique `(user_id, document_type, document_version)` — re-submeter é
no-op idempotente, versão nova gera linha nova. IP e user-agent entram como HMAC
(`app/Support/ClientFingerprint.php`), nunca em texto puro: corroboram um aceite
contestado sem virar rastreamento de navegação. `app/Http/Middleware/DocumentsAccepted.php`
barra performer sem aceite vigente; membro e admin passam (os documentos são da
relação de trabalho, não do uso do site).

Bumpar `config/documents.php` força re-aceite de toda a base — é assim que texto
novo do escritório vira evidência nova em vez de cobrir silenciosamente um aceite
feito sobre outro texto.

⚠️ **O conteúdo dos dois documentos é placeholder** até o escritório entregar o
texto. A máquina de aceite está pronta; o que ela faz aceitar, não. Aceite gravado
sobre placeholder tem valor jurídico próximo de zero — **substituir o texto e
bumpar a versão é pré-requisito de go-live**, não polimento.

**Denúncia, moderação, remoção — 🔴 os três.** Varredura por `report|denunc|moderat|flag`
em controllers/services/models/routes não retorna nenhuma superfície de moderação.
`app/Http/Controllers/Web/Admin/` contém **um único controller**, de waitlist. Não
há: botão de denunciar, tabela de reports, fila, nem ação administrativa de remover
conteúdo ou suspender performer. Se um membro vir algo ilegal hoje, **o produto não
oferece caminho para reportar** — o que, num serviço de conteúdo adulto, é o item
que um regulador procura primeiro.

**CSAM (PhotoDNA/NCMEC) e watermark — 🔴.** Zero referência no código; as únicas
menções a "watermark" estão em docs de handoff, como plano. Sem módulo de conteúdo
não há onde plugar hoje, mas a decisão de integrar precisa ser tomada **junto** com
o desenho do upload, porque hash-matching depois do fato é retrabalho e uma janela
de exposição.

---

## 4. Pagamentos — Modelo e Riscos

*Descritivo, não classificado como gap.*

**Fluxo do dinheiro — dois saltos, custódia no meio:**

1. **Membro → Limen.** `app/Services/PaymentService.php:32` cria cobrança PIX no
   Asaas com `'customer' => $user->asaas_customer_id` e `billingType => 'PIX'`. O
   dinheiro cai na **conta Asaas do Limen**. O webhook idempotente (`PAYMENT_RECEIVED`,
   dedupe por id de evento em `payment_events`) credita tokens no `token_ledger`.
2. **Gasto do membro.** Gorjeta/desbloqueio debita tokens do membro e credita a
   performer **em tokens**, com o split retido pela plataforma
   (`app/Services/TipService.php:65`: `floor($amount * $split_pct / 100)`). Nada de
   dinheiro se move aqui — é escrituração interna no ledger append-only.
3. **Limen → performer (payout).** `app/Services/PayoutService.php:89` chama
   `createTransfer` no Asaas, PIX da conta do Limen para a chave da performer.
   Conversão em `calculatePayoutCentavos`: `round(($tokens * 99 * $splitPct) / 1000)`.

**Recebedor legal do pagamento: o Limen.** Não há split direto no Asaas, não há
subconta por performer, não há `walletId` de destino na cobrança. O membro paga ao
Limen; a performer é paga pelo Limen, em transação separada e posterior.

**Como a taxa é separada:** ela não é "separada" no sentido financeiro — ela
simplesmente **nunca sai**. O Limen recebe 100% do valor do pacote de tokens e
transfere ao performer apenas a fração derivada do `split_pct` no momento do payout.
O `token_ledger` é o único registro da divisão.

**Risco de enquadramento como intermediador financeiro — descrito, não resolvido.**
O desenho atual tem as três características que a discussão regulatória costuma
olhar: (a) o Limen **recebe recursos de terceiro destinados a terceiro**;
(b) mantém **saldo em custódia** por tempo indeterminado entre a compra de tokens e
o payout — os tokens são passivo do Limen perante membro e performer; (c) executa a
**ordem de pagamento** ao beneficiário final. A camada de tokens não descaracteriza
isso por si só: economicamente, é conta de pagamento pré-paga.

Contrapontos que existem no desenho e devem constar na análise jurídica: o Asaas é
instituição de pagamento regulada e é ele quem opera o PIX nas duas pontas; o Limen
não custodia dinheiro fora da conta Asaas; não há saque em dinheiro pelo membro
(token não é resgatável, só gastável) — o que afasta a leitura de "conta de
livre movimentação".

**Não cabe a esta auditoria concluir o enquadramento.** Cabe registrar que a
escolha entre *manter custódia* e *migrar para split direto no Asaas* é uma decisão
**jurídica com consequência de arquitetura**, e que quanto mais tarde ela for
tomada, mais cara fica — o `token_ledger` e o `PayoutService` assumem custódia em
todo o seu desenho. Levar a pergunta ao Opice Blum de forma explícita.

---

## 5. Segurança e LGPD

| Item | Status |
|---|---|
| Criptografia de mensagens no banco (`messages.body`) | 🔴 FALTA |
| Hard Delete de usuário (irreversível) | 🔴 FALTA |
| TTL de logs de auditoria | 🔴 FALTA |
| Backups (frequência, retenção, localização) | 🟡 PARCIAL |
| MFA para performers | 🔴 FALTA |
| Secrets management (`.env`, rotação de `APP_KEY`) | 🟡 PARCIAL |
| Upload de mídia: storage, ACL, scan de malware | 🟡 PARCIAL |

**Mensagens em texto puro.** `messages.body` é `text` sem cast `encrypted`
(`app/Models/Message.php:23-28` só casteia `read_at`). Conteúdo íntimo entre membro
e performer está legível para qualquer acesso ao banco ou a um dump — incluindo os
backups. É a divergência mais gritante com o princípio 4 do CLAUDE.md ("PII isolada
e criptografada"), que hoje é honrado no KYC e no CPF mas não na superfície de maior
volume.

**Hard Delete — não existe.** `users` tem `softDeletes()`
(`2026_06_24_000001_extend_users_table.php:21`) e busca por
`forceDelete|hardDelete|anonymize` em `app/` não retorna **nada**. Não há caminho de
exclusão irreversível nem de anonimização. Pedido de eliminação sob o art. 18 da
LGPD hoje é atendível apenas por operação manual no banco. Já está listado como item
do Sprint 6 no CLAUDE.md — a auditoria confirma que segue aberto.

Tensão real a resolver com o escritório, não a decidir sozinho: `messages` é
**deliberadamente** retido no servidor após soft-delete, para trilha de abuso/legal
(comentado na migration e no model). Direito à eliminação × dever de retenção
probatória é conflito genuíno — precisa de base legal escrita e prazo definido, não
de uma decisão de engenharia.

**TTL de auditoria — não existe.** `audit_logs` não tem política de expurgo, comando
agendado ou particionamento. Cresce para sempre. Além disso, `audit_logs.ip` guarda
**IP em texto puro** — inconsistente com a decisão tomada em `document_acceptances`
e `ClientFingerprint`, onde IP entra como HMAC. Mesma PII, dois tratamentos.

**Backups — 🟡.** `docs/backup.sh` é sólido no que faz: `mysqldump --single-transaction`,
os dois discos privados (`storage/app/private` e `storage/app/kyc`), GPG obrigatório
via `GPG_RECIPIENT:?` (falha fechada se não definido), senha via `MYSQL_PWD` e não na
linha de comando, `RETENTION_DAYS=14`. O 🟡 é porque **é um script para instalar, não
um backup rodando**: o cabeçalho instrui agendar em cron no servidor e não há
evidência no repositório de que esteja ativo, nem de destino **off-site** — o
`BACKUP_DIR` é local (`/home/deploy/backups`). Backup criptografado na mesma máquina
não sobrevive à perda da máquina. Confirmar no servidor e registrar
frequência/retenção/localização reais.

**MFA — 🔴.** Busca por `two_factor|2fa|totp|mfa` em `app/`, `config/` e migrations
não retorna nada. Conta de performer (que dá acesso a payout e a PII de membros) é
protegida por senha apenas. Já consta como item do Sprint 6.

**Secrets — 🟡.** `.env` fora do Git, sem segredo versionado, e a chave Asaas tem
armadilha conhecida (prefixo `$` exige aspas simples). O 🟡 é a **rotação da
`APP_KEY`**: ela é chave de três coisas irreversíveis — HMAC de CPF
(`CpfHash`), pseudônimos (`FanAlias`), fingerprint de aceite (`ClientFingerprint`) —
e chave de decifração dos documentos de KYC e das colunas `encrypted`. Rotacionar
hoje **quebra a dedupe de CPF, invalida a correlação de aceites e torna os documentos
de KYC ilegíveis**. Ou seja: não há procedimento de rotação, e o desenho atual
torna a rotação destrutiva. Isso precisa de plano (re-cifragem em lote, chave
versionada) antes de virar incidente.

**Upload de mídia — 🟡.** Avatar/cover vão para o disco `local` (privado, fora do
webroot) e são servidos por `app/Http/Controllers/Api/V1/PerformerMediaController.php`
sob rota **assinada** (`routes/api.php:53`), o que é o desenho correto. Validação:
`mimes:jpeg,png,webp`, `max:5120` (`UploadMediaRequest.php:17`). Dois furos:
**nenhum scan de malware/antivírus** em qualquer upload (nem mídia, nem documento de
KYC), e `mimes` do Laravel confia na detecção por conteúdo do Symfony mas **não
re-encoda a imagem** — payload embutido em JPEG válido passa. Re-encodar no ingest é
a mitigação barata.

---

## 6. Propriedade Intelectual

| Item | Status |
|---|---|
| Arquivo LICENSE no repositório | 🔴 FALTA — e há problema pior |
| Contrato de sociedade referenciado em doc interno | 🔴 FALTA |
| Cláusula de propriedade de código em contratos de prestadores | 🔴 FALTA |

🚩 **`composer.json:10` declara `"license": "MIT"`.** É o valor default do esqueleto
do Laravel, quase certamente nunca revisado — mas é uma **declaração pública de
licenciamento permissivo sobre o código proprietário do Limen**. Não há arquivo
`LICENSE` que a contradiga. Se o repositório for tornado público, ou se um prestador
alegar que o código foi liberado sob MIT, essa linha é a evidência que ele vai citar.
Correção é de um caractere (`"proprietary"` ou `"UNLICENSED"`) e deveria ser feita
imediatamente — **não** foi feita aqui porque esta passagem é só auditoria.

Nenhum documento em `docs/` referencia contrato de sociedade, acordo de sócios ou
cláusula de cessão de direitos patrimoniais de código. Isso é esperado (são
documentos societários, não artefatos de repositório), mas o **vínculo** deveria
existir: um doc interno que aponte onde vivem, e cláusula de cessão em todo contrato
de prestador que tocou o código. Sem cessão expressa, no Brasil os direitos
patrimoniais de obra por encomenda podem não migrar automaticamente para a
contratante.

---

## 7. Ranking Final

| Item | Status | Classificação | Sprint |
|---|---|---|---|
| Texto real da Política de Conteúdo Proibido (hoje placeholder) | 🟡 | **CRÍTICO** | Pré-go-live |
| Fluxo de denúncia por membros | 🔴 | **CRÍTICO** | Pré-go-live |
| Fila de moderação + remoção de conteúdo por admin | 🔴 | **CRÍTICO** | Pré-go-live |
| Prevenção de CSAM (PhotoDNA/NCMEC) | 🔴 | **CRÍTICO** | Antes do 1º upload de conteúdo |
| Vínculo conteúdo postado ↔ pessoa verificada no KYC | 🔴 | **CRÍTICO** | Antes do módulo de conteúdo |
| Enquadramento como intermediador financeiro (parecer jurídico) | 🟡 | **CRÍTICO** | Pré-go-live (decisão do escritório) |
| `composer.json` declara licença MIT sobre código proprietário | 🔴 | **CRÍTICO** | Imediato (1 linha) |
| Verificação de idade contra base oficial (Serpro/DataValid) | 🔴 | ALTO | 7 |
| Criptografia de `messages.body` | 🔴 | ALTO | 6/7 |
| Hard Delete / anonimização LGPD | 🔴 | ALTO | 6 |
| Evidência documental de liveness + face match na Didit | 🟡 | ALTO | Pré-go-live (anexar ao dossiê) |
| MFA para performers | 🔴 | ALTO | 6 |
| Backup off-site confirmado e rodando (hoje é script local) | 🟡 | ALTO | Pré-go-live |
| Scan de malware + re-encode de imagem no ingest | 🟡 | ALTO | 7 |
| Plano de rotação da `APP_KEY` (hoje rotação é destrutiva) | 🟡 | ALTO | 7 |
| Cláusula de cessão de direitos em contratos de prestadores | 🔴 | ALTO | Jurídico, imediato |
| Revalidação periódica de KYC de performers | 🔴 | MÉDIO | 7 |
| Watermark automático em mídias | 🔴 | MÉDIO | Junto com módulo de conteúdo |
| TTL / expurgo de `audit_logs` | 🔴 | MÉDIO | 7 |
| `audit_logs.ip` em texto puro (inconsistente com HMAC do resto) | 🔴 | MÉDIO | 7 |
| Base legal escrita p/ retenção de mensagens vs. art. 18 LGPD | 🔴 | MÉDIO | Jurídico |
| Arquivo LICENSE explícito no repositório | 🔴 | MÉDIO | 7 |
| Doc interno apontando contrato de sociedade | 🔴 | MÉDIO | 7 |
| KYC documental para membro (além de CPF+DOB) | 🔴 | BAIXO | Futuro |
| Flag de IP compartilhado entre performers | 🔴 | BAIXO | Futuro |
| Bloqueio de CPF duplicado (hoje só detecta) | 🟡 | BAIXO | Decisão de produto |

### Leitura de uma linha

O que está construído está bem construído — KYC cifrado, CPF nunca persistido em
claro, aceite append-only com fingerprint HMAC, ledger e webhook idempotentes. Os
CRÍTICOS não são dívidas de qualidade: são **superfícies inteiras que não existem**
(denúncia, moderação, CSAM, vínculo conteúdo↔pessoa) mais **um texto jurídico
placeholder** e **uma linha de `composer.json`**. A ausência de módulo de conteúdo é
o que torna isso administrável hoje e insustentável no dia seguinte ao primeiro
upload.
