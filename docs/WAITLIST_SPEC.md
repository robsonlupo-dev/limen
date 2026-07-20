<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# WAITLIST — Cadastro em 2 etapas (Membro / Performer)

> **STATUS: APROVADO — decisões fechadas pelo PO em 2026-07-13 (ver seção 8). Em implementação.**
>
> Este documento foi derivado do código atual (`WaitlistController`, `WaitlistEntry`,
> `WaitlistService`, `WaitlistWebRequest`, migrations e `Landing.vue`) e dos docs
> `CIRCLES_SYSTEM_V4.md` e `WORLDS_ARCHITECTURE.md`. As decisões de design foram
> confirmadas pelo Product Owner e estão registradas na seção 8.

---

## 1. Contexto e estado atual (o que JÁ existe)

Hoje o cadastro na waitlist é de **uma etapa só**: um único formulário no
`Landing.vue` captura tudo de uma vez, e membro e performer preenchem
**exatamente os mesmos campos**.

**Formulário atual (`Landing.vue`, seção `#form`)** — um `useForm` único:

| Campo | Tipo | Regra atual |
|---|---|---|
| `role` | toggle membro/performer | `required, in:performer,member` (default `member`, ou `suggestedRole` do referral) |
| `name` | texto | `required, max:120` |
| `email` | texto | `required, email, max:190` + `NotDisposableEmailDomain` |
| `world` | seleção única (opcional) | `nullable, in:mulheres,homens,casais,trans,gls,swing` |
| `age_confirmed` | checkbox 18+ | `required, accepted` |
| `website` | honeypot oculto | deve ficar vazio |

**Backend atual:**
- `POST /waitlist` → `waitlist.store` → `WaitlistWebRequest` → `WaitlistService::join()`.
- `join()` é **idempotente por (email, role)** (`firstOrNew`); em entry nova congela
  `position_in_role`, semeia tier base e atribui referral com guardas anti-fraude.
- Tier base: **performer → `PerformerTier::Candidate`**, **member → `MemberTier::Curious`**.
- Envia `WaitlistConfirmationMail` **apenas** quando a entry é nova.
- Double opt-in: `GET /waitlist/confirmar?t=<invite_token>` → `confirm()` → painel do fundador.
- Unsubscribe em 2 tempos: `GET /waitlist/cancelar` mostra página; `POST` executa (CSRF).

**Colunas da tabela `waitlist_entries`:**
`id, name, email, role, world (nullable), age_confirmed, source, timestamps`,
+ (founding members) `referred_by, position_in_role, referral_count,
tier_member, tier_performer`. Únicas: `(email, role)`.
Campos **guardados** (nunca vêm do input): `invite_code, invite_token,
position_in_role, referral_count, tier_member, tier_performer`.

**Constatação importante:** as branches `feat/landing-waitlist-s2b2` e
`feat/waitlist-founding-members-s2b2b` **já estão mergeadas na main** — elas
*são* essa implementação de 1 etapa. **Não existe design de 2 etapas pré-existente**;
o que segue é proposta nova.

---

## 2. Objetivo do fluxo em 2 etapas

Separar o cadastro para que membro e performer sigam trilhas distintas, capturando
por papel só o que faz sentido, sem inflar o formulário de quem só quer entrar como
membro, e preparando o terreno para o que cada papel precisará depois (Círculos para
membro; verificação/KYC e mundo para performer).

**Princípio:** a Etapa 1 é a mesma para os dois (mínimo para reservar o lugar); a
Etapa 2 é ramificada por papel.

---

## 3. O fluxo proposto

```
                 ┌─────────────────────────────────────┐
                 │  ETAPA 1 — comum aos dois papéis     │
                 │  • Escolher papel: Membro | Performer│
                 │  • E-mail                            │
                 │  • Confirmar 18+                     │
                 │  • (honeypot oculto)                 │
                 └───────────────┬─────────────────────┘
                                 │ escolha do papel bifurca
              ┌──────────────────┴───────────────────┐
              ▼                                       ▼
  ┌───────────────────────────┐        ┌───────────────────────────────┐
  │ ETAPA 2 — MEMBRO          │        │ ETAPA 2 — PERFORMER            │
  │ • Nome/apelido            │        │ • Nome artístico               │
  │ • Preferências de mundo   │        │ • Mundo que representa (único) │
  │   (multi, privadas)       │        │ • Solo ou dupla (casal)        │
  │ • (Círculo de interesse?) │        │ • (Área/formato de trabalho?)  │
  └────────────┬──────────────┘        └───────────────┬───────────────┘
               └──────────────────┬───────────────────┘
                                  ▼
                    POST /waitlist  (um único submit)
                                  ▼
              WaitlistService::join()  (idempotente por email+role)
                                  ▼
              WaitlistConfirmationMail (variante por papel)
                                  ▼
              GET /waitlist/confirmar?t=…  → painel do fundador
```

**Mecânica das etapas (proposta):** wizard **client-side** no `Landing.vue`
(estado `step` no Vue, um só `useForm`), com **um único POST no final**. Assim
não há entry parcial no banco, a idempotência por (email, role) permanece
intacta e não abrimos superfície nova de escrita. (Alternativa descartada:
dois POSTs com "draft entry" — mais estado, mais superfície, sem ganho aqui.)

---

## 4. Campos por etapa e por papel

### Etapa 1 — comum

| Campo | Regra proposta | Observação |
|---|---|---|
| `role` | `required, in:member,performer` | bifurca a Etapa 2 |
| `email` | `required, email, max:190` + `NotDisposableEmailDomain` | normalizado (lower/trim) como hoje |
| `age_confirmed` | `required, accepted` | gate 18+ dos dois lados (princípio do CLAUDE.md) |
| `website` | honeypot | mantém comportamento atual |

### Etapa 2 — MEMBRO

| Campo | Regra proposta | Fonte / racional |
|---|---|---|
| `name` | `required, max:120` | nome/apelido de exibição |
| `world_preferences` | `nullable, array; each in:<mundos>` | **CIRCLES/WORLDS**: membro escolhe *quais mundos* aceita receber Interesse Controlado — são **preferências privadas e múltiplas**, não um mundo único |
| `circle_interest` *(opcional)* | `nullable, in:<círculos>` | **CIRCLES_SYSTEM_V4**: sinalizar interesse em Explorador→Founders Circle. **Decisão pendente #3** |

### Etapa 2 — PERFORMER

| Campo | Regra proposta | Fonte / racional |
|---|---|---|
| `name` (nome artístico) | `required, max:120` | performer se candidata com nome artístico (texto do próprio Landing atual) |
| `world` | `required, in:<mundos>` | **WORLDS**: uma performer **pertence a um mundo**. Aqui é **seleção única e obrigatória** (ao contrário do membro) |
| `performer_kind` *(solo/dupla)* | `nullable, in:solo,casal` | **WORLDS → Mundo Casais**: "performer = dois". Capturar cedo ajuda o funil de KYC. **Decisão pendente #4** |
| `work_focus` *(opcional)* | `nullable` | formato pretendido (lives, conteúdo, etc.) — **Decisão pendente #5** |

> **Divergência de modelagem a resolver:** hoje `world` é **uma coluna única
> nullable**. Membro (preferências, N mundos) e performer (1 mundo obrigatório)
> têm formas diferentes. Ver **Decisão pendente #2**.

---

## 5. E-mails disparados

**Hoje existe apenas um:** `WaitlistConfirmationMail` (double opt-in), enviado só
quando a entry é nova. Template em `resources/views/emails/waitlist/confirmation.blade.php`.

**Proposta para o fluxo em 2 etapas:**

| Gatilho | E-mail | Mudança proposta |
|---|---|---|
| Entry nova criada (`created === true`) | `WaitlistConfirmationMail` | **Variar a cópia por papel** (membro vs performer) — próximos passos e o que esperar diferem. Mesma classe com branch por `role`, ou duas variantes. **Decisão pendente #6** |
| Clique no link de confirmação | *(nenhum e-mail hoje)* | Manter sem e-mail; redireciona ao painel do fundador. Sem mudança |
| Unsubscribe | *(nenhum e-mail)* | Sem mudança |

**Não** introduzir e-mails novos nesta entrega sem decisão explícita (ex.: e-mail
de boas-vindas separado). Fora de escopo por ora.

---

## 6. Regras de anti-fraude (JÁ EXISTENTES — preservar integralmente)

Nenhuma dessas deve regredir na migração para 2 etapas:

1. **Honeypot** (`website`): campo oculto; se preenchido, responde sucesso neutro e **não grava**.
2. **E-mail descartável bloqueado**: `NotDisposableEmailDomain` contra a lista em `config/waitlist.php`.
3. **Idempotência por (email, role)**: `firstOrNew` + índice único; reenviar o mesmo interesse não duplica nem reenvia e-mail.
4. **Tiers / posição / invite jamais vindos do input**: `invite_code, invite_token, position_in_role, referral_count, tier_member, tier_performer` são **guardados** e definidos no servidor.
5. **Posição congelada por papel** no cadastro (`position_in_role`, contada separadamente para member e performer).
6. **Referral anti-fraude** (`WaitlistService`):
   - só atribui em entry **nova**;
   - **anti self-referral** (referrer ≠ próprio e-mail);
   - **cap de 3 referrals por IP / 24h** (`MAX_REFERRALS_PER_IP_24H`);
   - **fail-closed sem IP** (sem IP não concede crédito);
   - **IP nunca em claro**: `hash_hmac('sha256', ip, app.key)`.
7. **invite_code com letras aleatorizadas** (commit `0fdb62d`) — anti-enumeração.
8. **Double opt-in**: e-mail só confirma via token opaco (`invite_token`), sem PII na URL; GET de confirmação idempotente (pré-fetch de mailbox confirma no máximo uma vez).

**Novo a considerar (Etapa 2):** a validação por papel deve ser **server-side no
Form Request** (não confiar no wizard client-side) — campos de performer só aceitos
quando `role=performer`, e vice-versa, para impedir forjar `world` obrigatório ou
campos de outro papel via POST direto.

---

## 7. Impacto em dados / schema (a decidir antes de implementar)

Dependente das decisões pendentes, mas provável:

- **Membro — preferências de mundo (N):** nova representação (coluna JSON
  `world_preferences` **ou** tabela pivot). `world` único atual não comporta.
- **Performer — mundo (1, obrigatório):** pode reutilizar a coluna `world` atual
  (tornando-a obrigatória **apenas** quando `role=performer`, via regra do Request).
- **`performer_kind` (solo/casal):** nova coluna nullable, se aprovado (#4).
- **`circle_interest`:** nova coluna nullable, se aprovado (#3).

Toda mudança de schema via **migration versionada** (convenção do CLAUDE.md). Novos
campos precisam entrar no `$fillable` **com cuidado** — nada que seja derivado/sensível.

---

## 8. Decisões fechadas (PO — 2026-07-13)

1. **Mundos:** **4** — `mulheres, homens, trans, casais`. Remover `gls`/`swing`.
   **Escopo da remoção:** apenas as superfícies de **cadastro/waitlist**
   (`WaitlistWebRequest`) nesta entrega. O catálogo/enum de `performer_profiles`,
   seeders e `PublicCatalogTest` (que tratam gls/swing como legado oculto do
   público) **ficam intocados** — purga legada é follow-up separado.
2. **Modelagem de mundo (assimetria confirmada):** performer = coluna `world`
   atual, **obrigatória quando `role=performer`**. Membro = nova coluna JSON
   **`world_preferences`** (nullable, N valores).
3. **`circle_interest`:** **não** capturar agora.
4. **`performer_kind` (solo/casal):** **sim**, capturar. **Obrigatório quando
   `world=casais`**, nullable nos demais mundos.
5. **`work_focus`:** **não** capturar.
6. **E-mail de confirmação:** varia por papel na **mesma classe**
   `WaitlistConfirmationMail`, com branch por `role` (mecanismo `isPerformer()`
   **já existente** — sem trabalho adicional).
7. **Mecânica das etapas:** **wizard client-side com POST único** confirmado.
8. **Nome artístico:** é o **mesmo campo `name`** (sem campo separado nesta entrega).

---

## 9. Fora de escopo desta entrega

- Verificação de identidade / KYC de performer (Fase 5 — só capturamos candidatura aqui).
- Cobrança / escolha de Círculo com pagamento (Fase 5 / CIRCLES).
- Mudanças no painel do fundador e no sistema de referral/tiers além do necessário
  para acomodar os campos novos.
- E-mails novos além da confirmação existente.

---

## 10. Próximo passo

Decisões fechadas (seção 8) e plano técnico aprovado. Implementação em andamento na
branch `feat/waitlist-2-steps` (a partir da `origin/main`), nesta ordem: migration →
model → `WaitlistWebRequest` (validação por papel) → `WaitlistService` → `Landing.vue`
(wizard) → testes. E-mail não muda (já ramifica por papel).
