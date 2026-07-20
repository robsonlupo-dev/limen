# LIMEN — MASTER HANDOFF (Sprint 6)

> **Gerado em:** 20/07/2026 · **Base:** `main` em `229d852` (merge do PR #69)
> **Suíte:** 556 testes verdes · 2614 asserts (`php artisan test`)
> **Método:** escrito a partir da inspeção do código real — `git log --merges`,
> `composer.json`, `package.json`, `php -v`, migrations e a suíte rodada de ponta a ponta.
> Onde um doc contradiz o código, **o código venceu** e a divergência está registrada.
>
> Continua `MASTER_HANDOFF_SPRINT5.md` (Sprint 5, base `edbb1c1`, 440 testes). Este arquivo
> cobre o que mudou de lá para cá e o que o Sprint 6 precisa atacar. Para contexto de produto
> (Círculos, Maison, Founding Members, Growth OS, DNS), o handoff do Sprint 5 continua válido
> e é a fonte — não foi reescrito aqui.

---

## 1. O QUE O SPRINT 5 ENTREGOU

Nove PRs mergeados em `main`, todos confirmados no `git log --merges`:

| PR | Branch | Entrega |
|---|---|---|
| **#61** | `feat/pci-saqd-hardening` | Endurecimento PCI SAQ-D (ver `docs/PCI_SAQ_D.md`) |
| **#62** | `feat/kyc-didit-real` | Integração KYC Didit real (sai do driver `fake`) |
| **#63** | `fix/didit-auth-url` | Correção da URL de autenticação Didit |
| **#64** | `fix/didit-api-key-auth` | Autenticação por `x-api-key` (não Bearer) |
| **#65** | `feat/kyc-webhook-v3` | Webhook Didit v3 com `X-Signature-V2` |
| **#66** | `feat/payout-needs-review-alert` | Payout `needs_review`: alerta + requeue |
| **#67** | `feat/founder-trial-7d` | Trial de 7 dias para Founding Members |
| **#68** | `feat/founder-trial-7d` | `ExpireSubscriptions` + expiração por `next_due_date` |
| **#69** | `feat/anonymity-floor` | Piso de Anonimato + Modo Discreto + mitigação de sybil |

**Evolução da suíte:** 440 → 556 testes (2066 → 2614 asserts).

### 1.1 PR #69 em detalhe (o mais recente, e o que tem mais regra nova)

Três camadas, todas no `app/Services/FollowerVisibilityService.php`, que é a **fonte única**
da regra — a tela de seguidores e o envio de Interesse consultam o mesmo serviço de propósito:

1. **Piso de Anonimato** — a performer só vê a lista a partir de `interest.anonymity_floor`
   (5) seguidores. Com 1 ou 2, "Membro #123" deixa de ser anônimo.
2. **Modo Discreto** — o membro conta para o piso mas nunca é listado (tiers Black e
   Founders Circle).
3. **Mitigação de sybil** — só contas com 7+ dias (`ANONYMITY_FLOOR_ACCOUNT_AGE_DAYS`) **e**
   e-mail verificado contam para *atingir* o piso.

O ataque mitigado: a performer registrava 4 contas de consumidor, seguia a si mesma e
destravava a lista — o próximo seguidor real ficava sendo o único nome que ela não plantou.

Complementos que entraram junto:
- `throttle:5,1` no `POST /cadastro` (`routes/web.php`) — era a **única** rota de auth sem
  throttle; login, reset de senha e o cadastro da API já tinham. O corte de idade encarece a
  *pressa*; o throttle e a verificação de e-mail encarecem o *volume*.
- Contagem de seguidores em faixas ("Menos de 5", "5+", "10+", "50+", "100+", exato a partir
  de 500), inclusive no dashboard da própria performer — faixar só as telas públicas deixaria
  o ataque de correlação em pé.

**Migrations novas:** `2026_07_20_000347_add_discrete_mode_to_follows_table`,
`2026_07_20_000348_add_discrete_mode_to_users_table`.

**Revisão de segurança:** aprovada com ressalvas, sem achados críticos. Confirmado que a tela
e o envio de Interesse continuam alinhados (sem oráculo 404-vs-201), que não há caminho
paralelo de contagem de seguidores fora do serviço, e que o `created_at` do membro não é
exposto ao front. Dois dos três 🟡 foram corrigidos no próprio PR; o terceiro está no §5.

---

## 2. STACK (verificado, não copiado de doc)

| Item | Versão real | Onde conferi |
|---|---|---|
| PHP | **8.4.22** | `php -v` |
| Laravel | **^13.8** | `composer.json` |
| Vue | **^3.5.39** | `package.json` |
| Inertia | **^3.5.0** (`@inertiajs/vue3`) | `package.json` |
| MySQL | 8.4 (Docker) | `CLAUDE.md` + `docker` |
| Redis | via Docker | cache/filas |

> ⚠️ **`CLAUDE.md` está desatualizado:** diz "PHP 8.5" (real: 8.4.22) e "Próxima: Fase 8"
> (entregue há tempo). O handoff do Sprint 5 já tinha registrado essa divergência e ela
> continua aberta. Vale corrigir o `CLAUDE.md` no Sprint 6 — ele é lido em toda sessão.

### 2.1 Servidor e domínios

**Host único:** Hetzner `62.238.46.212` (`limen-dev-01`), tudo em `/var/www/limen`.

| Domínio | Papel |
|---|---|
| **`limen.dev.br`** | **Staging — o app completo**, chat em tempo real inclusive |
| **`thelimen.com.br`** | **Produção — portão de MARKETING, não o app.** vhost só passa `/`, `/links`, `/interesse`, `/images/`, `/og-image.png` |

> **Ponto que já causou confusão duas vezes:** os dois domínios são o **mesmo servidor, o mesmo
> `/var/www/limen` e o mesmo banco**. Quando algo parecer "desatualizado" em produção,
> suspeite de **opcache/CDN**, não de código não deployado.

---

## 3. BLOQUEANTES PRÉ-GO-LIVE

| # | Bloqueante | Natureza | Observação |
|---|---|---|---|
| 1 | **Abrir empresa (CNPJ)** | Jurídico/administrativo | Asaas produção **exige PJ**. Não é tarefa de código e não tem workaround técnico — é o caminho crítico mais longo. |
| 2 | **Soft descriptor** | Configuração externa | Nome comercial neutro na conta Asaas (o que aparece na fatura do cartão). **Não é campo de API** — configura-se no painel Asaas, com o suporte. Depende do #1. |
| 3 | **`KYC_PROVIDER=didit`** | Confirmação externa | Confirmar o **encoding do `X-Signature-V2`** antes de produção. Detalhe no §3.1. |
| 4 | **`FOUNDER_CUTOFF_AT`** | Config de produção | Precisa estar no `.env` de produção. Já existe no `.env.example`. Sem ele, a janela de Founding Members não fecha. |
| 5 | **Fix correlação `Membro #` ↔ `Fã #`** | Código | Ver `docs/SECURITY_ISSUES.md` e §5. |

### 3.1 Sobre o bloqueante #3 (o único com pegadinha técnica)

`KycWebhookController.php:118` faz:

```php
$expected = hash_hmac('sha256', $this->canonicalJson($payload), $secret);
```

Sem o 4º argumento, `hash_hmac()` devolve **hex** — ou seja, **o código já assume hex** e
compara com `hash_equals()`. Se a Didit enviar base64, **toda** verificação falha e os webhooks
são rejeitados silenciosamente em produção.

Existe um fallback `X-Signature-Simple` (HMAC sobre `timestamp:session_id:status:webhook_type`),
mas ele não salva o caso: também é hex.

**Ação:** confirmar o encoding com a Didit **ou** capturar um webhook real em staging antes do
go-live. É barato confirmar e caro descobrir em produção.

---

## 4. DECISÕES LOCKED DE PRIVACIDADE

Estas quatro **não se rediscutem sem decisão explícita do PO**. Todas têm teste cobrindo.

1. **`discrete_mode` NÃO está em `$fillable` do `User`.** Confirmado em `User.php:22-26`. É
   proteção contra mass assignment: um `PATCH /preferencias` com `discrete_mode` no corpo não
   pode ligar/desligar o modo por acidente. A troca passa obrigatoriamente pelo endpoint
   dedicado, que checa o tier.
2. **Perder Black/FC NÃO desativa o Modo Discreto.** Quem já está discreto continua discreto se
   a assinatura lapsar — não reexpomos ninguém por falha de pagamento. Mas o membro **sempre
   consegue DESLIGAR** (não pode ficar preso); só não consegue **religar** sem o tier.
3. **Rota web:** `consumer.settings.discrete-mode`. O frontend Vue fala com rotas **web**
   (sessão + CSRF), não com Sanctum. Existe também a rota de API
   (`consumer.preferences.discrete-mode`), mas a tela usa a web.
4. **O piso usa contas com 7+ dias e e-mail verificado; a faixa exibida usa TODOS os ativos.**

### 4.1 A consequência do item 4 que confunde quem lê a tela

A faixa é **exibição**; o piso é **segurança**. Os cortes valem para **destravar**, não para
filtrar a lista.

Isso significa que **"5+" com a lista escondida é um estado legítimo** — há seguidores demais
recentes (ou não verificados) para diluir alguém. E, uma vez que contas elegíveis abriram a
lista, **contas novas e não verificadas aparecem nela normalmente**.

Não é bug. Se o PO achar confuso na tela da performer, o ajuste é na copy do `floor_message`,
não na regra.

---

## 5. DÍVIDA DE SEGURANÇA ABERTA — correlação `Membro #` ↔ `Fã #`

**Severidade:** 🟡 Médio-Alto · **Pré-existente na `main`**, não introduzido pelo #69.

O dashboard de gorjetas exibe `'Fã #' . ($tip->consumer_id % 10000)`
(`Performer/DashboardController.php:65`) enquanto a lista de seguidores exibe
`'Membro #' . $user_id`. Mesmo espaço de id ⇒ correlação determinística:
`Membro #12345` ↔ `Fã #2345`.

**Por que importa:** a lista de gorjetas **não passa por piso nenhum**. Um membro discreto, ou
abaixo do piso, entrega 4 dígitos do próprio id ao dar uma gorjeta — e a performer liga os dois
pseudônimos.

**Registro completo, com mitigação proposta e o pré-requisito de produto (pseudônimo estável vs.
rotativo):** `docs/SECURITY_ISSUES.md`. Falta abrir a issue no GitHub (não há `gh` CLI no
ambiente de dev — PRs e issues são abertos manualmente pelo PO).

---

## 6. AGENDA DO SPRINT 6

| Item | Natureza | Nota |
|---|---|---|
| **Panic Button** | Segurança da performer | Ação de emergência durante interação |
| **Ghost Mode** | Privacidade | Distinto do Modo Discreto — escopo a definir antes de codar |
| **Read Receipts** | Chat | Confirmação de leitura; decidir se é opt-out |
| **Photo Blur** | Privacidade/mídia | Blur em prévia de mídia |
| **2FA performers** | Segurança de conta | Conta de performer é a que tem dinheiro atrelado |
| **Hard Delete LGPD** | Compliance | Hoje o chat tem soft-delete; falta o expurgo real |
| **Fix `Fã #` correlação** | Segurança | §5 — o único com análise já pronta |

**Sugestão de ordem:** começar pelo fix do `Fã #` (análise pronta, escopo fechado, fecha uma
dívida conhecida) e pelo **Hard Delete LGPD** (é compliance, tem prazo legal, e toca schema —
quanto mais tarde, mais dados para migrar). Panic Button e Ghost Mode precisam de definição de
produto antes de virar código; não são tarefas de implementação ainda.

**Dependência que atravessa o sprint:** os bloqueantes #1 e #2 (CNPJ, soft descriptor) são
administrativos e **não avançam com trabalho de código**. Começar cedo — eles governam a data
de go-live, não o backlog técnico.

---

## 7. COMO RODAR A SUÍTE NESTA MÁQUINA

Não há `pdo_sqlite` neste dev box, e o `phpunit.xml` aponta para sqlite `:memory:`.
**Não edite o `phpunit.xml`** — variáveis de ambiente do shell têm precedência, que é
exatamente o que o CI faz:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD=limen_dev_pw \
php artisan test
```

**Armadilha:** migration que falha faz o Pest re-rodar `migrate:fresh` a cada teste — **parece
hang** (300s+), não erro. Rode `php artisan migrate:fresh` sozinho para ver a exceção real.

---

## 8. CHECKLIST DE ABERTURA DO SPRINT 6

- [ ] Corrigir `CLAUDE.md` (PHP 8.5 → 8.4.22; "Próxima: Fase 8" → estado real)
- [ ] Abrir a issue do `Fã #` no GitHub a partir de `docs/SECURITY_ISSUES.md`
- [ ] Confirmar encoding do `X-Signature-V2` com a Didit (bloqueante #3)
- [ ] Setar `FOUNDER_CUTOFF_AT` no `.env` de produção (bloqueante #4)
- [ ] Fechar escopo de produto de Panic Button e Ghost Mode antes de codar
- [ ] Andar com CNPJ e soft descriptor em paralelo ao sprint técnico
