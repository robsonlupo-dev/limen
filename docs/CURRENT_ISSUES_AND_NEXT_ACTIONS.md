# LIMEN — PROBLEMAS ATUAIS E PRÓXIMAS AÇÕES

> Gerado em 02/07/2026 (commit `afa2e5e`). Lista os problemas conhecidos, o que o próximo
> Claude deve fazer, o que **não** pode mudar, e o contexto crítico. Complemento operacional
> de `TECHNICAL_HANDOFF_MASTER.md` e `QA_HANDOFF_MASTER.md`.

---

## PROBLEMAS ATUAIS CONHECIDOS

### P0 — HSTS divergente entre repo e servidor (será sobrescrito no deploy)
- **Descrição:** `app/Http/Middleware/SecurityHeaders.php` seta HSTS
  `max-age=31536000; includeSubDomains; preload` sob HTTPS. Em staging o valor foi reduzido
  **manualmente no servidor** para `max-age=300`. Como o deploy agora usa `git reset --hard
  origin/main` (D10), **a próxima publicação restaura 1 ano automaticamente**, desfazendo a
  redução manual.
- **Causa:** correção feita fora do versionamento (só no servidor).
- **Impacto:** em staging, 1 ano de HSTS + `preload` é quase irreversível e atrapalha dev;
  `preload` bloqueia proxies de inspeção SSL corporativos (Zscaler).
- **Prioridade:** P0 (armadilha silenciosa — reaparece sozinho no deploy).
- **Solução sugerida:** tornar o HSTS condicional ao ambiente no próprio código — em
  `local`/`staging` usar `max-age=300` (sem `preload`), em `production` o valor completo.
  Assim o repo é a fonte da verdade e o reset --hard não reintroduz o problema.
- **Arquivos:** `app/Http/Middleware/SecurityHeaders.php`, `config/app.php` (env).

### P0 — Rota nova usada no frontend fora do allowlist do Ziggy = tela preta
- **Descrição:** `config/ziggy.php` tem um `only` (allowlist) das rotas expostas ao front.
  Se um componente Vue chamar `route('x')` e `x` não estiver na lista, o Ziggy lança erro, o
  Vue morre na montagem e **todas as páginas ficam pretas**. Foi exatamente o bug de hoje
  (`entrada` e rotas de reset faltando) — já corrigido em `f4bf6ef`.
- **Causa:** rota nova no front sem entrada correspondente no allowlist.
- **Impacto:** crítico — derruba a renderização do site inteiro, não só a tela afetada.
- **Prioridade:** P0 (processo, não bug aberto).
- **Solução sugerida:** **toda rota nova usada no frontend PRECISA ser adicionada a
  `config/ziggy.php`.** Ideal: teste de fumaça que carregue o layout guest/app e falhe se o
  Ziggy reclamar de rota ausente.
- **Arquivos:** `config/ziggy.php`, layouts/componentes Vue que chamam `route()`.

### P1 — FIXes de UX (Fase 12) sem validação manual tela a tela
- **Descrição:** os 8 FIXes foram deployados (entrada Membro/Performer, age gate overlay,
  intro animada, e-mail PT-BR + redirect, esqueci senha, catálogo por mundo, cadastro
  performer, quick wins) mas ainda **não** foram validados um a um em navegador real.
- **Impacto:** regressões visuais/UX podem estar no ar em staging.
- **Prioridade:** P1.
- **Solução sugerida:** validar via túnel 8443 com o banco **populado** (rode a Operação de QA
  antes — telas com dados reais são muito mais fáceis de validar que telas vazias). Anotar
  melhorias em lote num único documento e gerar um próximo pacote de FIXes.
- **Arquivos:** telas em `resources/js/Pages/*` e componentes.

### P2 — Acesso pela rede Verallia bloqueado pelo Zscaler (NÃO é bug do site)
- **Descrição:** a rede corporativa da Verallia bloqueia `limen.dev.br` via Zscaler (categoria
  "Newly Registered and Observed Domains" + conteúdo adulto). Confirmado por `Server: Zscaler/6.2`
  e `<!--Verallia-->` na resposta; na VM aparecia "wrong version number" (Zscaler quebrando o TLS).
- **Impacto:** impossível abrir o site direto de dentro da Verallia.
- **Prioridade:** P2 (externo, não é defeito do produto).
- **Solução atual:** túnel SSH na VM (`~/tunel-limen.sh`) → acesso via
  `https://limen.dev.br:8443/entrada`. O túnel não afeta o servidor.

### P2 — `.env.example` induz a SQLite
- **Descrição:** `.env.example` traz `DB_CONNECTION=sqlite`, mas o projeto usa MySQL (Docker
  em dev, service no CI). Rodar `php artisan test` sem sobrescrever as vars falha com
  "could not find driver".
- **Impacto:** fricção para quem clona e roda testes.
- **Prioridade:** P2.
- **Solução sugerida:** documentar o comando de teste com MySQL (feito no handoff) ou ajustar
  `phpunit.xml`/`.env.example` para o MySQL de dev.

### P2 — Integrações reais (Asaas/KYC) ainda em Fake
- **Descrição:** só `FakeAsaasClient`/`FakeKycClient` configurados. Chaves de sandbox/produção
  e credenciais do provedor de KYC pendentes.
- **Impacto:** não bloqueia dev/QA, mas é pré-requisito de go-live.
- **Prioridade:** P2 (P0 no checklist de produção).

---

## O QUE O NOVO CLAUDE DEVE FAZER PRIMEIRO (ordem de prioridade)

1. **Corrigir o HSTS por ambiente** (P0) para o `reset --hard` não reintroduzir 1 ano em staging.
2. **Rodar a Operação de QA** (`LIMEN-QA-OPERATION.md`): popular 50 performers + 100 membros
   (só Fake + ledger) e rodar a bateria; gerar os relatórios. Deixa o ambiente pronto para
   validação manual.
3. **Apoiar a validação manual tela a tela** dos FIXes (P1) e consolidar as observações do PO
   num único doc de melhorias.
4. **Gerar o próximo pacote de FIXes** a partir dessas observações (em lote, como foi a Fase 12).
5. Só depois: avançar para features de DESIGN (feed, conteúdo pago, chat, streaming) conforme
   prioridade do PO.

> **Sempre:** ao adicionar rota usada no front, atualizar `config/ziggy.php`. Ao mexer em
> cadastro/KYC/pagamento/payout, rodar o subagente `security-reviewer`. Testes verdes antes de
> marcar pronto.

---

## O QUE NÃO DEVE SER ALTERADO (decisões travadas)

- **Ledger append-only:** nunca `UPDATE saldo = saldo + x`; todo movimento é linha nova em
  `token_ledger`; saldo é a soma. Update/delete no ledger são bloqueados (testado).
- **CPF só no checkout** e PII isolada/criptografada em storage privado; nunca em log/URL.
- **`category` é o "mundo"** (mulheres/homens/casais/trans/gls/swing). **Não** criar coluna
  `world` — `preferred_world` no user já cobre a preferência.
- **Rota `/cadastro` reutilizada** — não criar `/registro`.
- **Catálogo é auth-gated** — não expor publicamente.
- **Deploy via `git fetch + reset --hard origin/main`** — o servidor é sempre igual ao repo;
  não voltar para `git pull`, e **não editar arquivos manualmente no servidor** (serão perdidos).
- **Sudoers do deploy** (`/etc/sudoers.d/deploy-limen`, NOPASSWD só para `chown storage` +
  `supervisorctl restart limen-worker:*`) — não ampliar.
- **Idempotência de pagamento** por id de evento — não creditar fora do webhook.
- **Stack Blade/Inertia+Vue+Tailwind** — mudar só com aprovação do PO.

---

## CONTEXTO CRÍTICO A NÃO PERDER

- **Servidor:** Hetzner, IP `62.238.46.212`, Ubuntu 24.04, usuários SSH `deploy` e `root`,
  projeto em `/var/www/limen`, nginx + `php8.4-fpm`, SSL Let's Encrypt (ECDSA) via Certbot.
- **Repo:** `github.com/robsonlupo-dev/limen`, branch `main`, deploy automático em push.
- **Secrets do GitHub:** `HETZNER_HOST`, `HETZNER_SSH_KEY`.
- **Domínios:** `limen.dev.br` (staging, ativo), `limen.com.br` (produção futura).
- **VM de trabalho:** VirtualBox Ubuntu dentro da máquina Verallia; acesso ao site só via
  túnel SSH `:8443` (Zscaler bloqueia direto).
- **Testes:** 173 verdes; rodar contra Docker MySQL `limen_test` (não há SQLite local).
- **Bug histórico de referência:** tela preta = Ziggy sem a rota no allowlist.
- **Permissões de git no servidor** já resolvidas (`deploy:www-data`, `core.sharedRepository
  group`, `deploy` no grupo `www-data`) — não rodar git como `root` lá.

---

## RESUMO EXECUTIVO PARA O PRÓXIMO CLAUDE

Limen é uma plataforma adulta verificada (Laravel 13/PHP 8.5 + Inertia/Vue3/Tailwind, MySQL 8.4
+ Redis). Fases 1–12 entregues: auth, wallet/tokens (ledger append-only), PIX/Asaas (Fake em
dev, webhook idempotente), KYC (Fake), gorjetas (split por nível), catálogo auth-gated por
mundo, follows, dashboard de performer e payout via PIX. **173 testes verdes.** Staging no ar em
`limen.dev.br` com **deploy automático** (push na `main` → testes → SSH deploy que faz
`git reset --hard`, migra, cacheia, reinicia workers). Features de DESIGN ainda **não**
construídas: feed, conteúdo pago destravável, chat, streaming LiveKit — **não reportar bug**
nelas.

Estado: os FIXes de UX da Fase 12 estão no ar mas faltam validar tela a tela; e falta rodar a
Operação de QA para popular o banco. Armadilhas ativas: (1) HSTS de 1 ano no repo será
restaurado pelo `reset --hard` — corrigir por ambiente; (2) qualquer rota nova usada no front
tem de entrar em `config/ziggy.php` senão o site inteiro fica em tela preta; (3) nada de editar
arquivos direto no servidor (o deploy sobrescreve). Próximos passos, em ordem: corrigir HSTS por
ambiente → rodar QA (popular dados) → validar telas em lote → gerar próximo pacote de FIXes.
Antes de qualquer coisa sensível, rodar o subagente `security-reviewer`.
