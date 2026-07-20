# Auditoria de pixels de terceiros — 20/07/2026

**Escopo:** `resources/js/`, `resources/views/`, `public/`, `package.json`,
seeders e comandos que produzem URLs renderizadas no browser.
**Base auditada:** branch `age-verification`, commit `0372e1e`.
**Atualizado em 20/07/2026:** os itens 1, 2 e 3 foram fechados e um achado novo
(item 5) entrou — ver "Correções aplicadas" no fim.
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
| 5 | `resources/views/vendor/mail/html/header.blade.php:6` | `<img>` hospedado em `laravel.com` no header de e-mail | **CORRIGIDO** |

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

**Status: CORRIGIDO** (self-host, ver "Correções aplicadas"). Na primeira
passada ficou em aberto porque tirar os `<link>` sem mais nada derrubaria a
tipografia do produto inteiro — as duas famílias não existiam localmente — e
self-host é implementação, não auditoria. O PO aprovou logo em seguida.

### 3 — `welcome.blade.php` (OK, apagado)

Sobra do skeleton do Laravel, presente desde `e56a47c`. **Não tinha rota** —
nada em `routes/` apontava para ela, era código morto. Os links externos eram
institucionais do Laravel, sem script de tracking. Removida por limpeza, não por
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

### 5 — `<img>` remoto no header de e-mail (CORRIGIDO)

**Achado que a primeira passada desta auditoria não pegou.** A varredura
original procurou `<script src>` e `<link href>` externos e não olhou `<img>`,
então passou batido: o header de e-mail publicado do Laravel trocava o nome da
aplicação por uma imagem hospedada em `laravel.com` quando o slot era
exatamente `"Laravel"`. Imagem remota em e-mail **é** pixel de rastreio na
prática — quem hospeda vê IP, cliente de e-mail e hora de abertura de cada
destinatário.

Em produção o ramo estava morto (`APP_NAME=Limen` cai no `@else`), mas o
`.env.example` ainda traz `APP_NAME=Laravel`: bastava um ambiente com o default
para os e-mails saírem com o pixel. O `@if` inteiro foi removido; o header
sempre renderiza o slot como texto.

Quem encontrou foi o teste de invariante (follow-up 3, agora implementado) —
o escopo dele é mais largo que o da varredura manual que escreveu este doc.

## Correções aplicadas — 20/07/2026

1. **Fontes self-hosted.** Cormorant Garamond e Inter agora vêm de
   `public/fonts/` via `resources/css/fonts.css`. Os `<link>` do Google saíram
   de `app.blade.php` e de `errors/layout.blade.php`. Itens 1 e 2 fechados: **a
   área logada não faz nenhuma requisição a origem de terceiro.**
   - São fontes **variáveis** — os `.woff2` que o Google serve por peso são
     byte-idênticos (conferido por md5). Um arquivo por família+estilo+subset,
     com `font-weight` em intervalo: 6 arquivos, 288 KB.
   - Subsets `latin` e `latin-ext` apenas. Cyrillic/greek/vietnamese ficaram de
     fora (plataforma BR); texto nesses alfabetos cai no fallback do sistema.
   - As páginas de erro declaram os `@font-face` inline, sem `@vite`: elas têm
     que renderizar mesmo com o manifest do build quebrado — é justamente o
     cenário em que aparecem.
2. **`welcome.blade.php` apagado** — item 3, código morto sem rota.
3. **Invariante em teste:** `tests/Unit/ExternalAssetPolicyTest.php` varre todo
   `resources/views/**.blade.php` procurando origem externa em tag que o cliente
   baixa sozinho (`script`/`img`/`iframe`/`link`/`source`/`embed`/`object`/
   `video`/`audio`, mais `url()` e `@import` em CSS inline). Falha apontando
   arquivo:linha. `ALLOWED_EXTERNAL_ORIGINS` está **vazia** — cada entrada nova
   é um terceiro vendo o IP de quem abre a página, e exige aval do PO.
   `<a href>` externo não conta: é navegação que o usuário escolhe, não
   requisição automática.

## Follow-ups remanescentes

1. **`APP_NAME=Laravel` no `.env.example`.** Não é mais vetor de pixel (item 5
   corrigido), mas segue como default que vaza para assunto de e-mail e título
   de página em ambiente mal configurado.
2. **O teste cobre Blade, não Vue.** Um `<img src="https://...">` dentro de um
   `.vue` passaria. Hoje não existe nenhum (verificado nesta auditoria), mas
   estender a varredura a `resources/js/` fecharia o flanco.
