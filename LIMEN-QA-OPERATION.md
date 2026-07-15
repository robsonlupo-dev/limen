# LIMEN — OPERAÇÃO DE QA PRÉ-PRODUÇÃO
### Pacote de orquestração autônoma para Claude Code
**Gerado em:** 01/07/2026 · **Executor:** Claude Code na VM (`~/teste`) · **Modo:** autônomo, sem confirmação

---

## COMO RODAR

```bash
cd ~/teste
git checkout -b qa/pre-prod-operation
claude
```

Depois cole este documento inteiro no Claude Code e diga:

> **Execute a OPERAÇÃO DE QA deste documento de ponta a ponta, em modo autônomo.
> Não me pergunte nada. Tome as decisões você mesmo. Rode tudo até o fim e me entregue
> os 7 relatórios + o resumo executivo no final.**

Recomendo `auto mode on` (shift+tab) para não aprovar edição por edição.

> **NOTA DE HONESTIDADE — leia primeiro.**
> Este é o único ponto onde os testes rodam de verdade: a máquina que executa o Claude Code.
> Todos os relatórios devem conter **dados reais** da execução — nunca inventar pass/fail.
> Se um teste não puder rodar (feature não existe, dependência ausente), o relatório registra
> `NÃO IMPLEMENTADO` ou `BLOQUEADO` com o motivo. Relatório honesto > relatório bonito.

---

## FASE 0 — PRÉ-VOO (o orchestrator faz isso ANTES de tudo)

Antes de criar qualquer agente ou dado, o orchestrator mapeia o terreno real:

```bash
# Estado do repo e ambiente
git status && git log --oneline -10
php artisan --version
php artisan route:list
php --version && node --version

# Schema real — a fonte da verdade para as factories
php artisan migrate:status
ls -1 database/migrations/
php artisan db:table users        2>/dev/null || true
php artisan db:table performer_profiles 2>/dev/null || true
php artisan db:table token_ledger 2>/dev/null || true
php artisan db:table token_wallets 2>/dev/null || true
php artisan db:table tips          2>/dev/null || true
php artisan db:table payments      2>/dev/null || true
php artisan db:table follows       2>/dev/null || true

# Modelos, services, controllers existentes
find app/Models -name "*.php" | sort
find app/Services -name "*.php" | sort
find app/Http/Controllers -name "*.php" | sort
ls -1 resources/js/Pages -R

# Factories e seeders que já existem
ls -1 database/factories/ database/seeders/ 2>/dev/null

# Suite de teste atual
ls -1 tests/Feature tests/Unit 2>/dev/null
cat phpunit.xml | grep -A3 DB_
```

**DETECÇÃO DE CAPACIDADE (obrigatório).** Com base no mapeamento acima, o orchestrator preenche
esta matriz. Cada `false` desliga o validador correspondente (que reporta `NÃO IMPLEMENTADO`):

| Módulo | Como detectar | Se ausente |
|---|---|---|
| Auth (login/registro/reset) | rotas `login`, `register.store`, `password.*` | crítico — não deve faltar |
| Wallet/Tokens | `TokenService`, tabela `token_ledger` | crítico |
| PIX/Asaas | `FakeAsaasClient`, `AsaasWebhookController` | crítico |
| Gorjetas | `TipService`, tabela `tips` | crítico |
| Payout | rota/serviço de payout | reportar `NÃO IMPLEMENTADO` se ausente |
| Follows | `FollowController`, tabela `follows` | esperado |
| Catálogo | `CatalogController`, `Pages/Catalog` | esperado |
| Feed/Posts | model `Post`/`ContentItem` + controller | provável ausente → `NÃO IMPLEMENTADO` |
| Conteúdo pago | model de unlock + coluna de preço em post | provável ausente → `NÃO IMPLEMENTADO` |
| Chat/Mensagens | model `Message`/`Conversation` | provável ausente → `NÃO IMPLEMENTADO` |
| Mídia/galeria | disco privado + `temporaryUrl` | parcial (upload existe) |
| Streaming ao vivo | LiveKit | ausente (fase futura) → `NÃO IMPLEMENTADO` |

**Banco de teste:** usar o MySQL Docker `limen_test` (o handoff diz que não há sqlite local).
Confirmar em `phpunit.xml` / `.env.testing`. Se `limen_test` não existir:
```bash
docker compose exec mysql mysql -uroot -p"$DB_ROOT" -e "CREATE DATABASE IF NOT EXISTS limen_test;"
```
Rodar migrations no banco de teste com `--env=testing`.

---

## FASE 1 — CRIAÇÃO DOS AGENTES

Criar os 16 agentes em `.claude/agents/`. Cada arquivo `.md` segue o formato do Claude Code
(frontmatter `name`/`description`/`tools` + corpo com a missão). Charters resumidos abaixo —
o orchestrator escreve cada arquivo completo.

### orchestrator (você, coordenando)
Sequencia as waves, dispara agentes em paralelo, agrega saídas, resolve conflitos, calcula a
nota final e escreve `GO_LIVE_READINESS.md`. É o único que fala com o usuário no fim.

### qa-lead
Define critérios de aprovação/reprovação, revisa a saída de todos os validadores, consolida
`TEST_RESULTS.md`. Autoridade para marcar um módulo como `blocker`.

### synthetic-data-generator
Gera o conteúdo textual/visual realista (nomes artísticos, bios, categorias, preços, URLs de
avatar placeholder). Não toca no banco — só produz os dados que a `test-user-factory` persiste.

### test-user-factory
Cria de fato os 50 performers + 100 membros via factories/seeders. Coleta as credenciais e
escreve `TEST_ACCOUNTS.md`. Responsável por deixar o ambiente povoado no fim.

### backend-qa
Testes de feature/Pest para services, endpoints API `/v1/*`, form requests, resources.

### frontend-qa
Renderização Inertia/Vue, submit de formulários, validação client-side, rotas web.

### ux-validator
Avaliação heurística por tela (rubrica na Fase 4), nota 0–10 por tela, escreve `UX_REPORT.md`.

### payments-validator
Fluxo Asaas/PIX com `FakeAsaasClient`: criar cobrança → webhook `PAYMENT_RECEIVED` →
crédito idempotente. Testa dedup por `event.id`, replay de webhook, assinatura inválida.

### token-economy-validator
Invariantes do ledger append-only: `balance_after` correto, soma bate, nenhum `UPDATE saldo`,
nenhum saldo negativo, débito atômico sob concorrência (lock de linha).

### chat-validator
Se existir: envio/histórico/mídia de mensagem. Senão: `NÃO IMPLEMENTADO — adiado`.

### media-validator
Upload em disco privado (nunca público), `temporaryUrl` com expiração, autorização de acesso.
Conteúdo pago só se o módulo existir.

### feed-validator
Se existir: CRUD de post, visibilidade grátis vs pago. Senão: `NÃO IMPLEMENTADO`.

### security-validator
IDOR, autorização por role, mass assignment (`role`/`status`/`preferred_world`), saldo negativo,
bypass de pagamento/gorjeta/conteúdo pago. Escreve `SECURITY_REPORT.md`.

### load-test-validator
k6 com rampas 100/500/1000 VUs contra a app local. Escreve `LOAD_REPORT.md` com ressalva de
que números de VM de dev são indicativos, não representativos de produção.

### analytics-validator
Confere que `audit_logs` registra ações sensíveis (login, pagamento, payout, verificação, gorjeta)
e que eventos-chave têm rastro.

### bug-hunter
Exploratório: entradas malformadas, limites (0 tokens, valores enormes), unicode, race conditions,
duplo-submit, expiração de token. Consolida achados em `TEST_RESULTS.md` seção Bugs.

---

## FASE 2 — MASSA DE DADOS (test-user-factory + synthetic-data-generator)

### Estratégia de imagens (SEGURANÇA)
Somente placeholders — **nunca** imagem explícita nem foto de pessoa real:
- Avatar: `https://api.dicebear.com/7.x/avataaars/svg?seed={username}` ou `https://i.pravatar.cc/300?u={username}`
- Cover: `https://picsum.photos/seed/{username}/1200/400`
- Galeria: `https://picsum.photos/seed/{username}-{n}/800/1000`

Guardar as URLs nos campos de mídia. Se o app espera arquivo local, baixar o placeholder para o
disco privado de teste. Nunca gerar conteúdo sexual/explícito.

### Dados fake seguros
- **CPF:** gerado com dígito verificador válido (usar a `Rule\CpfValido` como referência), mas
  fictício. Nunca CPF real.
- **E-mail:** domínio `@teste.limen.local` para não vazar para caixas reais.
- **Senha das contas de teste:** vem de `SEED_ADMIN_PASSWORD` no ambiente onde o seeder roda,
  e **nunca é escrita no repo** — nem aqui, nem no `TEST_ACCOUNTS.md`. Em local/testing o
  fallback é `Password1` (ambientes descartáveis); em staging/development a variável é
  obrigatória e o seeder aborta sem ela. Ver `RefusesUnsafeEnvironment::seedPassword()`.
- **Pagamento:** somente `FakeAsaasClient`. `ASAAS_ENV=sandbox`, jamais `production`.
- **KYC:** `FakeKycClient` retornando `approved` para os performers de teste.

### 50 Performers
Distribuir pelos 6 mundos (reusar o enum `category`): ~mulheres 20, homens 8, casais 8, trans 6,
gls 5, swing 3. Cada performer:
- `role=performer`, `status=active`, `email_verified_at=now()`, `age_verified_at=now()`
- `performer_profiles`: `display_name`, `username` único, `category` (mundo), `bio`,
  `is_verified=true`, `tip_min` (gorjeta mínima em tokens), preço de conteúdo,
  `is_live` (≈20% true), `rating` (3.5–5.0 aleatório), avatar/cover
- `identity_verifications`: registro aprovado (via FakeKyc)
- `token_wallet` criada (saldo pode ser 0 — performer ganha, não compra)
- Followers simulados: N membros aleatórios seguindo (usar tabela `follows`)
- Posts grátis e pagos **somente se o módulo feed existir**; senão pular e anotar no relatório

### 100 Membros
- `role=consumer`, `status=active`, `email_verified_at=now()`, `age_verified_at` conforme fluxo
- `preferred_world` sorteado entre os 6
- `token_wallet` com saldo inicial creditado **via ledger** (nunca UPDATE direto):
  usar `TokenService::credit()` com motivo `seed_initial` — saldos variados (0 a 6000 tokens)
- Histórico de compras: 0–5 `payments` `PAID` via FakeAsaas, cada um com crédito no ledger
- Histórico de gorjetas: 0–10 `tips` para performers aleatórios via `TipService` (split real)
- Histórico de follows: seguir 0–15 performers

### Implementação (reference — o orchestrator reconcilia com o schema real da Fase 0)

`database/factories/PerformerProfileFactory.php`:
```php
public function definition(): array
{
    $worlds = ['mulheres','homens','casais','trans','gls','swing'];
    $username = fake()->unique()->userName();
    return [
        'display_name' => fake()->firstName().' '.fake()->randomElement(['Luz','Bella','Rex','Nyx','Vip']),
        'username'     => $username,
        'category'     => fake()->randomElement($worlds),
        'bio'          => fake()->realText(160),
        'is_verified'  => true,
        'is_live'      => fake()->boolean(20),
        'rating'       => fake()->randomFloat(2, 3.5, 5.0),
        'tip_min'      => fake()->randomElement([10, 20, 50, 100]),
        'avatar_url'   => "https://i.pravatar.cc/300?u={$username}",
        'cover_url'    => "https://picsum.photos/seed/{$username}/1200/400",
    ];
}
```

`database/seeders/LimenTestSeeder.php` (esqueleto — usa services, nunca UPDATE de saldo):
```php
public function run(): void
{
    // 50 performers
    User::factory()->count(50)->performer()->create()->each(function ($u) {
        $profile = PerformerProfile::factory()->for($u)->create();
        app(KycService::class)->approveForTest($u);           // via FakeKyc
        // followers simulados adicionados depois que os membros existirem
    });

    // 100 membros
    $members = User::factory()->count(100)->consumer()->create();
    $members->each(function ($m) {
        $m->update(['preferred_world' => fake()->randomElement([...])]); // preferred_world é fillable-safe? validar
        // saldo inicial via ledger
        $tokens = fake()->randomElement([0,200,500,1200,2000,6000]);
        if ($tokens > 0) app(TokenService::class)->credit($m, $tokens, 'seed_initial');
        // compras simuladas via FakeAsaas
        // gorjetas via TipService para performers aleatórios
        // follows
    });
}
```

> Se `preferred_world` estiver fora do `$fillable` (correto por segurança), setar via atribuição
> explícita + `save()`, não mass-assignment. Se `KycService::approveForTest()` não existir, criar
> um método de teste ou inserir o registro `identity_verifications` aprovado diretamente no seeder.

Rodar:
```bash
php artisan migrate:fresh --seed --seeder=LimenTestSeeder   # ambiente de dev, não o de teste automatizado
```

---

## FASE 3 — MATRIZ DE TESTES FUNCIONAIS (waves em paralelo)

Os validadores criam/rodam testes Pest onde possível e, para fluxos de UI, testes de
navegação Inertia. Cada item vira uma linha em `TEST_RESULTS.md` com `PASS`/`FAIL`/`N/A`.

**Wave A (paralelo):** backend-qa + token-economy-validator + payments-validator
- Cadastro performer (`?tipo=performer` → `registerPerformer` → profile+KYC+wallet)
- Cadastro membro (`?tipo=membro` → consumer)
- Login performer / login membro / credenciais inválidas / usuário suspenso bloqueado
- Recuperação de senha (envia reset → redefine → login com nova senha)
- Wallet: compra de tokens (FakeAsaas), atualização de saldo via ledger, histórico
- PIX: geração de cobrança, confirmação, webhook `PAYMENT_RECEIVED`, **replay idempotente**,
  webhook com assinatura inválida rejeitado
- Gorjetas: envio (débito membro), recebimento (crédito performer com split), duas linhas no ledger,
  idempotência por `idempotency_key`
- Payout: solicitação, aprovação, atualização de saldo, payout falho vira `failed`+reversão
  (se não existir módulo → `N/A NÃO IMPLEMENTADO`)

**Wave B (paralelo, depende de dados prontos):** frontend-qa + feed-validator + media-validator + chat-validator
- Follow / unfollow (contadores corretos)
- Perfil: edição (performer) e visualização pública
- Dashboard performer / dashboard consumer (renderiza dados reais do seed)
- Feed: criar/editar/remover/visualizar post → `N/A` se ausente
- Conteúdo pago: publicar/desbloquear/consumir → `N/A` se ausente
- Chat: enviar/histórico/mídia → `N/A` se ausente
- Catálogo: filtra por `preferred_world`, busca por nome, filtros avançados, troca de mundo

**Wave C:** analytics-validator + bug-hunter (exploratório sobre o ambiente já povoado)

Critério de aprovação (qa-lead): um módulo é `blocker` se um teste de segurança de dinheiro
(saldo negativo, bypass de pagamento/gorjeta, dedup de webhook) falhar.

---

## FASE 4 — VALIDAÇÃO UX (ux-validator)

Rubrica por tela (0–10, média das dimensões):
1. Clareza do texto (PT-BR correto, tom premium/discreto, sem "lorem")
2. Fluxo/navegação (o próximo passo é óbvio)
3. Estado vazio (mensagem útil, não tela em branco)
4. Estado de loading (skeleton/spinner presente)
5. Mensagens de erro (específicas e acionáveis)
6. Consistência visual (paleta dourado/preto, tipografia serifada no display)
7. Responsividade (mobile 2 col, desktop 4+ col)

Telas a avaliar (as que existirem): Entrada, Age Gate, Intro, Login, Recuperar senha,
Cadastro membro, Cadastro performer, Onboarding performer, Catálogo, Perfil público,
Dashboard performer, Dashboard consumer, Wallet/compra, Verificar e-mail, Feed, Chat.

Saída → `UX_REPORT.md`: tabela `Tela | Nota | Pontos fortes | Problemas | Melhoria sugerida`,
mais a média geral de UX.

---

## FASE 5 — SEGURANÇA (security-validator)

Casos obrigatórios (cada um vira PASS/FAIL em `SECURITY_REPORT.md`):
- **IDOR:** membro A tenta ler wallet/perfil privado/mídia paga de membro B → deve 403/404
- **Autorização por role:** consumer acessando rota de performer/admin → bloqueado
- **Mass assignment:** POST de registro com `role=admin` / `status=active` / `is_verified=true`
  / `preferred_world` injetado → ignorado
- **Saldo negativo:** gastar mais tokens do que possui → rejeitado, ledger intacto
- **Bypass de pagamento:** creditar tokens sem webhook (chamar controller de compra direto) → não credita
- **Bypass de gorjeta:** enviar gorjeta sem saldo / com valor negativo / `idempotency_key` repetido → rejeitado/dedup
- **Bypass de conteúdo pago:** acessar mídia paga sem desbloquear (se módulo existir) → 403
- **Webhook forjado:** assinatura/token inválidos → rejeitado (timing-safe)
- **Rota de verificação:** link sem `signed`/expirado → rejeitado
- **CSRF/sessão:** session fixation após registro (regeneração), CSRF em forms web

Classificar cada achado: `CRÍTICO / ALTO / MÉDIO / BAIXO`. Rodar o subagente
`security-reviewer` existente do projeto como parte desta fase.

---

## FASE 6 — CARGA (load-test-validator)

k6 (instalar se preciso). Cenário: navegar catálogo + login + ver perfil (fluxo read-heavy real).

```javascript
// tests/load/limen-load.js
import http from 'k6/http';
import { check, sleep } from 'k6';
export const options = {
  scenarios: {
    ramp_100:  { executor: 'ramping-vus', startVUs: 0, stages: [{duration:'30s',target:100}, {duration:'1m',target:100}, {duration:'20s',target:0}] },
    ramp_500:  { executor: 'ramping-vus', startVUs: 0, startTime:'2m', stages: [{duration:'30s',target:500}, {duration:'1m',target:500}, {duration:'20s',target:0}] },
    ramp_1000: { executor: 'ramping-vus', startVUs: 0, startTime:'4m', stages: [{duration:'30s',target:1000},{duration:'1m',target:1000},{duration:'20s',target:0}] },
  },
  thresholds: { http_req_duration: ['p(95)<800'], http_req_failed: ['rate<0.02'] },
};
export default function () {
  const r = http.get(`${__ENV.BASE_URL}/catalogo`);
  check(r, { 'status 200/302': (x) => [200,302].includes(x.status) });
  sleep(1);
}
```

```bash
k6 run -e BASE_URL=http://localhost:8000 tests/load/limen-load.js
```

`LOAD_REPORT.md`: p50/p95/p99, throughput, taxa de erro, ponto de saturação por rampa,
gargalos observados (DB? PHP-FPM? memória?). **Ressalva obrigatória:** VM de dev satura cedo;
teste de carga representativo deve rodar no VPS de produção na Fase 12.

---

## FASE 7 — ENTREGÁVEIS (o orchestrator consolida)

Criar na raiz do repo, em `docs/qa/`:

### TEST_ACCOUNTS.md
Duas tabelas. **Sem coluna de senha:** a senha vem de `SEED_ADMIN_PASSWORD` e não é
publicada aqui — são ~150 contas ativas num staging alcançável, então publicá-la seria
credencial viva no repo.
```
## Performers (50)
| # | Nome artístico | Username | E-mail | Mundo |
## Membros (100)
| # | Nome | Username | E-mail | Mundo pref. | Saldo tokens |
```
Destacar 3 contas "showcase" de cada tipo com dados ricos (muitos follows/compras) para demo.

### TEST_RESULTS.md
```
## Resumo: X aprovados / Y falhas / Z N-A
## Por módulo: tabela Módulo | Testes | Pass | Fail | N/A | Status
## Falhas detalhadas: cada uma com passo p/ reproduzir + severidade
## Bugs (bug-hunter): lista priorizada
```

### UX_REPORT.md — Fase 4 (notas por tela + média + melhorias)
### SECURITY_REPORT.md — Fase 5 (achados por severidade + veredito)
### LOAD_REPORT.md — Fase 6 (métricas + ressalva de VM)

### GO_LIVE_READINESS.md
Nota final 0–100, ponderada:
- Funcional 30% · Segurança 30% · Economia de token/ledger 20% · UX 10% · Carga 10%
- Lista de **blockers** que impedem go-live
- Lista de **must-fix antes de produção**
- Veredito: `GO` / `NO-GO` / `GO com ressalvas`

---

## FASE 8 — ANÁLISE DE GROWTH (após os testes)

Depois de tudo verde, o orchestrator troca de chapéu e atua como **CMO + Head of Growth +
Head of Product + UX Lead + CRO**. Usa os achados de UX + o fluxo real observado para escrever
`GROWTH_STRATEGY.md`, avaliando: conversão, onboarding, monetização, retenção, gamificação,
primeira compra, primeira gorjeta, primeira interação.

Gerar, **ordenado por ROI** (impacto × facilidade):
- 50 melhorias priorizadas (tabela: `# | Melhoria | Área | Impacto | Esforço | ROI`)
- 20 quick wins (< 1 dia de dev cada)
- 10 mudanças que aumentam receita (com hipótese de % de lift)
- 10 mudanças que aumentam retenção (com métrica-alvo)

Referências de mercado para inspirar (não copiar): meupatrocinio.com.br, cameraprive.com,
chaturbate.com — onboarding, prova social, escada de preço, gatilhos de retorno.

---

## REGRAS DA OPERAÇÃO
- Autônomo: não pedir confirmação; decidir e seguir.
- Usar factories e seeders sempre que possível.
- **Nunca** deletar dados ao final — ambiente fica povoado para teste manual.
- **Nunca** usar Asaas/KYC de produção — só clientes fake/sandbox.
- **Nunca** gerar conteúdo explícito nem usar foto de pessoa real — só placeholders.
- Saldo **sempre** via `TokenService`/ledger — proibido `UPDATE` direto.
- Todo relatório com dado real; feature ausente = `NÃO IMPLEMENTADO`, não bug falso.
- Rodar o `security-reviewer` do projeto antes do commit final.
- Commits pequenos em inglês; um PR ao final da branch `qa/pre-prod-operation`.

## FECHAMENTO
No fim, o orchestrator entrega no chat:
1. Resumo executivo (5–8 linhas): nota final, veredito GO/NO-GO, top 3 blockers, top 3 quick wins.
2. As 6 contas showcase (3 performers + 3 membros) prontas para login manual, com URL de acesso.
3. Caminho dos 7 arquivos em `docs/qa/`.

---
*Operação desenhada por Claude (Arquiteto de QA) · Projeto Limen · 01/07/2026*
