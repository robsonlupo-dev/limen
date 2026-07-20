# Auditoria de pixels de terceiros — 20/07/2026

**Escopo:** `resources/js/`, `resources/views/`, `public/`, `package.json`,
seeders e comandos que produzem URLs renderizadas no browser.
**Base auditada:** branch `age-verification`, commit `0372e1e`.
**Regra sob teste:** zero pixels de terceiros em área logada (decisão
arquitetural inviolável — ver `CLAUDE.md`, princípio 1).

## Resultado

**Nenhum script de tracking em lugar nenhum do projeto** — nem em área logada,
nem em página pública. A busca por `gtag`, `ga(`, `fbq`, `_fbq`, `pixel`,
`analytics`, `hotjar`, `clarity`, `mixpanel`, `segment`, `amplitude`, `heap`,
`intercom`, `crisp`, `tawk` em `resources/js/` e `resources/views/` retornou
**zero ocorrências**. Não há uma única tag `<script src="...">` externa em
nenhum Blade: todo JS entra pelo bundle do Vite.

Há **uma requisição a terceiro em área logada**, que não é pixel mas cai no
mesmo perímetro de risco. Está classificada abaixo como ATENÇÃO e depende de
decisão do PO.

## Ocorrências

| # | Arquivo:linha | O que é | Classificação |
|---|---|---|---|
| 1 | `resources/views/app.blade.php:40-42` | `preconnect` + CSS do Google Fonts (Cormorant Garamond, Inter) | **ATENÇÃO — área logada** |
| 2 | `resources/views/errors/layout.blade.php:7-9` | idem, nas páginas de erro (403/404/419/500) | **ATENÇÃO — área logada** |
| 3 | `resources/views/welcome.blade.php` | skeleton do Laravel, links para `laravel.com`, `github.com`, `laracasts.com` | OK — arquivo morto |
| 4 | `database/seeders/LimenStagingSeeder.php:251` | `i.pravatar.cc` para avatar de staging | OK — server-side |

### 1 e 2 — Google Fonts (ATENÇÃO, não BLOQUEANTE)

`app.blade.php` é a view raiz de **todas** as páginas Inertia, então esses três
`<link>` carregam em cada tela logada: catálogo, chat, carteira, painel da
performer. Não é analytics e não é pixel — o Google Fonts não seta cookie e não
existe intenção de rastreio. Mas é requisição de browser a servidor do Google
partindo de sessão autenticada, e ela carrega inevitavelmente **IP, User-Agent
e horário** do membro.

O que **limita** o dano: `SecurityHeaders.php:21` já manda
`Referrer-Policy: strict-origin-when-cross-origin`. Com isso o Google recebe só
a origem (`https://thelimen.com.br`), nunca o caminho — não descobre *qual*
performer o membro está vendo. A exposição é "este IP acessou a Limen", não
"este IP viu este perfil".

**Não removi, e por quê:** tirar os `<link>` sem mais nada quebra a tipografia
de todo o produto — as duas famílias não existem localmente (`resources/css/`
não tem `@font-face`, não há `public/fonts/`). O conserto correto é
**self-host**: baixar os `.woff2`, servir de `public/fonts/` e declarar
`@font-face` no `app.css`. Isso é implementação, não auditoria, e muda como o
design é entregue — decisão do PO. Enquanto não acontece, a regra "zero
terceiros em área logada" tem esta exceção conhecida, e é honesto registrar que
ela existe em vez de declarar a auditoria limpa.

### 3 — `welcome.blade.php` (OK)

Sobra do skeleton do Laravel, presente desde `e56a47c`. **Não tem rota** — nada
em `routes/` aponta para ela, é código morto. Os links externos são institucionais
do Laravel, sem script de tracking. Candidata a remoção por limpeza, não por
segurança.

### 4 — `i.pravatar.cc` no seeder de staging (OK)

Parece terceiro em área logada, mas não é: o seeder faz `Http::get()`
**server-side** e grava a imagem no disco privado (`storeAvatar()`,
`LimenStagingSeeder.php:246-258`). O browser do membro busca o avatar da rota
`performer.media` da própria Limen — nunca fala com o pravatar. Sem rede, cai
num SVG gerado offline. O `performers:backfill-avatars` usa só o placeholder
offline (`AvatarPlaceholder::store`), sem rede nenhuma.

## O que também foi verificado e está limpo

- **Sem CDN.** `pusher-js` e `laravel-echo` entram como dependência npm no
  bundle, não por `<script>` remoto. Nenhum Blade carrega JS externo.
- **`preconnect`/`dns-prefetch` para analytics:** nenhum. Os dois `preconnect`
  existentes são os do Google Fonts (itens 1 e 2).
- **`package.json`:** seis dependências, todas de framework
  (`@inertiajs/vue3`, `@vitejs/plugin-vue`, `laravel-echo`, `pusher-js`, `vue`,
  `ziggy-js`). Nenhum SDK de tracking.
- **Imagens e iframes remotos em componentes Vue:** nenhum.
- **`public/`:** só assets próprios (favicon, og-image, build do Vite).

## Follow-ups

1. **Self-host das fontes** (Cormorant Garamond + Inter) para fechar os itens 1
   e 2 e deixar a área logada sem nenhuma origem de terceiro. Requer decisão do
   PO.
2. **Apagar `resources/views/welcome.blade.php`** — código morto do skeleton.
3. **Guarda de regressão:** hoje nada impede um `<script src>` de terceiro
   voltar num Blade. Um teste que varra `resources/views/` por origem externa
   não-permitida transformaria esta auditoria pontual em invariante contínua.
