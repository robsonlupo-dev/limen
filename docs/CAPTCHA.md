# Captcha — anti-bot em login e cadastro (driver abstrato)

**Sprint 9** trouxe o hCaptcha; **Sprint 16** o transformou num **driver
abstrato** com dois provedores intercambiáveis — **hCaptcha** e **Cloudflare
Turnstile** —, motivado pelo fim do trial Pro do hCaptcha (11/08/2026), que cairia
para o plano free e seus limites. O Turnstile é gratuito e sem os mesmos limites.

**Estado: montado, DESLIGADO.** `CAPTCHA_PROVIDER=none` é o padrão, o valor
versionado e o estado de dev, teste e CI. Enquanto estiver assim, o captcha é
no-op completo — ver "O que 'none' significa".

---

## Como alternar entre os provedores

Uma variável decide tudo:

```env
CAPTCHA_PROVIDER=none        # padrão — captcha desligado (no-op total)
CAPTCHA_PROVIDER=hcaptcha    # usa HCAPTCHA_SITEKEY / HCAPTCHA_SECRET
CAPTCHA_PROVIDER=turnstile   # usa TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY
```

Chaves por provedor no `.env`:

```env
HCAPTCHA_SITEKEY=
HCAPTCHA_SECRET=
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

Depois de mudar: `php artisan config:cache` (o valor é lido por `config()`, então
cache velho mantém o provedor antigo).

**Ponte de compatibilidade:** se `CAPTCHA_PROVIDER` não estiver definido, um
servidor legado com `HCAPTCHA_ENABLED=true` continua no hCaptcha — o deploy não
desliga um gate anti-bot ativo em silêncio. Sem nada definido, cai em `none`.

---

## Arquitetura — a lógica não é duplicada por provedor

| Peça | Papel |
|---|---|
| `config/captcha.php` | Fonte única: provider ativo, campo, timeout, chaves/URLs por provedor. |
| `App\Services\Captcha\CaptchaManager` | **Dona única da escolha do provedor.** Resolve o driver a partir do config. Único ponto que a regra e as props do Inertia consultam. |
| `App\Services\Captcha\CaptchaDriver` | Contrato (`verify` / `enabled` / `frontend`). |
| `App\Services\Captcha\RemoteCaptchaDriver` | Base com o POST de siteverify + fail-open + log. hCaptcha e Turnstile têm contratos server-side IDÊNTICOS (POST `secret`+`response`, resposta `success: bool`); só mudam chaves e URL. |
| `HcaptchaDriver` / `TurnstileDriver` | Só declaram sua chave de config. |
| `NullCaptchaDriver` | Provedor `none`: `verify()` → `true` sem rede, `frontend()` → `null`. |
| `App\Rules\CaptchaValid` | **Dona única do contrato do captcha** nas portas de auth. `FIELD = 'captcha_token'` (neutro por provedor). |
| `resources/js/Components/Captcha.vue` | Widget único: carrega o SDK do provedor ativo e renderiza hCaptcha OU Turnstile (APIs quase idênticas). |

Trocar de provedor, ligar ou desligar é **uma linha no `.env`** — nenhum
controller, Form Request ou página Vue conhece o driver concreto.

---

## ⚠️ Bloqueadores de conformidade — ler ANTES de ligar em produção

**Todo provedor de captcha é subprocessador.** Ligado, o browser de cada pessoa
que abre `/login` ou `/cadastro` passa a falar direto com o host do provedor
(`hcaptcha.com` ou `challenges.cloudflare.com`), entregando **IP, User-Agent e
horário** a um terceiro (Intuition Machines no hCaptcha; Cloudflare, Inc. no
Turnstile). Trocar de provedor **troca o subprocessador**, não elimina a
exposição.

Numa plataforma de conteúdo adulto isso não é telemetria banal: o fato de que
"este IP tentou entrar na Limen" já é dado sensível por si só, e é gerado
**antes** de a pessoa sequer ter conta.

Três itens precisam estar fechados antes de `CAPTCHA_PROVIDER` sair de `none`,
para o provedor ESCOLHIDO:

1. **Política de privacidade** — o provedor listado com finalidade (prevenção de
   fraude/abuso), dados tratados (IP, User-Agent, sinais de interação) e base
   legal (LGPD art. 7º — legítimo interesse é o candidato, e precisa de avaliação
   registrada).
2. **Registro de subprocessadores** — o provedor ao lado de Asaas e Didit.
3. **DPA assinado e arquivado** com o provedor (Intuition Machines ou Cloudflare).

Nenhum dos três é código. São pré-condição para o interruptor, e é por isso que
ele nasce em `none` em vez de nascer ligado "para testar".

> **Disciplina de linguagem** (mesma regra do painel de visitantes e do
> geobloqueio): captcha **não** é garantia de que não há bot. Serviços de
> resolução de captcha custam centavos por desafio. Isto encarece automação em
> massa; não a elimina. Não escreva "bots não conseguem se cadastrar" em
> política, contrato ou auditoria.

---

## Onde é aplicado — e onde deliberadamente NÃO é

Todas as portas via `App\Rules\CaptchaValid`:

| Porta | Form Request |
|---|---|
| `POST /login` (web) | `Auth\LoginRequest` |
| `POST /api/v1/auth/login` | `Auth\LoginRequest` (o mesmo — as duas portas compartilham) |
| `POST /cadastro` (web, membro **e** wizard da performer) | `Web\RegisterWebRequest` |
| `POST /api/v1/auth/register/{consumer,performer}` | `RegisterConsumerRequest` (o performer herda) |
| `POST /entrar-com-codigo` + a porta API do OTP | `Auth\RequestOtpRequest` |

**Fora do captcha, por decisão do PO:** reset de senha, verificação de e-mail e
todo o resto. A regra é de uma dona só justamente para a próxima porta não nascer
sem ela — é a lição que o `CLAUDE.md` tira do `documents.accepted`.

---

## O que "none" significa

`CAPTCHA_PROVIDER=none` é no-op nos três níveis, e o do meio é o que garante que
nenhum byte sai para o terceiro:

1. **Validação** — `CaptchaValid::rules()` devolve `['nullable']`. O campo não é
   exigido; cliente antigo e teste antigo continuam passando sem mandá-lo.
2. **Frontend** — `HandleInertiaRequests` não manda o `sitekey`, o componente
   `Captcha.vue` não é montado e o SDK **não é buscado**.
3. **Verificador** — o `NullCaptchaDriver::verify()` devolve `true` sem tocar na
   rede.

---

## Por que o SDK não está no `app.blade.php`

`docs/PIXEL_AUDIT.md` audita uma regra que o `CLAUDE.md` chama de inviolável:
**zero pixels de terceiros em área logada**. Foi por ela que as fontes do Google
viraram self-host.

`app.blade.php` é a view raiz de **toda** página Inertia. Uma `<script src>` do
provedor ali carregaria em catálogo, chat, carteira e painel da performer — cada
tela logada viraria uma requisição ao terceiro com IP e horário do membro, o que
é exatamente o que a auditoria fechou.

Por isso o SDK é injetado por `resources/js/Components/Captcha.vue`, que só existe
nas duas telas de auth (públicas, deslogadas). **Não mova o script para o Blade.**

> **A URL de cada SDK é LITERAL no `Captcha.vue`**, uma por provedor, para que a
> varredura de origem externa (`tests/Unit/ExternalAssetPolicyTest`) a enxergue.
> Esconder as URLs atrás de um mapa/variável faria o terceiro passar despercebido
> pela auditoria. `js.hcaptcha.com` e `challenges.cloudflare.com` estão na
> allowlist `ALLOWED_JS_ORIGINS`, com o aval do PO.

---

## Decisões de implementação

**Sem pacote de terceiro.** O composer deste ambiente não alcança o packagist, e
o contrato dos dois provedores é um POST de formulário com dois campos.
`RemoteCaptchaDriver` segue o padrão do `DiditKycClient`/`AsaasHttpClient`
(`Http::` + timeout curto), o que também deixa o teste usar `Http::fake()`.

**`remoteip` NÃO é enviado no siteverify** (vale para os dois provedores; o campo
é opcional em ambos). O browser já entregou o IP ao provedor ao carregar o
widget; mandá-lo de novo do servidor seria a Limen **transmitindo ativamente** o
IP do titular ao subprocessador. Ligar isso é decisão de produto.

**Falha de rede não derruba o login.** Token recusado bloqueia (é o ponto). Mas
timeout, 5xx ou provedor fora do ar **deixam passar**, com `Log::warning`. É a
mesma escolha, e pelo mesmo motivo, do fail-OPEN do `GeoBlock` (`config/geo.php`).

**Token é de uso único** (nos dois provedores). Depois de um submit recusado por
qualquer motivo (senha errada, e-mail duplicado), o token foi junto e queimou. Os
formulários chamam `captcha.reset()` no `onError` — sem isso a segunda tentativa
falharia no captcha, e a pessoa veria "verificação de segurança falhou" quando o
erro real era a senha.

**Campo neutro `captcha_token`.** O front captura o token pelo callback do widget
e o envia neste campo via `useForm` (o Inertia serializa o objeto JS, não o input
oculto que o SDK injeta), então UM nome serve aos dois provedores. Fonte do valor:
`config('captcha.field')` / `CaptchaValid::FIELD`.

**CSP não precisou mudar.** `SecurityHeaders` manda só
`Content-Security-Policy: frame-ancestors 'self'`, que restringe quem pode
enquadrar *as nossas* páginas — não o que nós embutimos. O iframe do widget cairia
sob `frame-src`, que não está definido, e o script sob `script-src`, também não
definido. **Não há o que adicionar hoje** — e adicionar um `script-src` parcial
(sem `'self'`/Vite/inline) quebraria o app inteiro. Se um `default-src`/`script-src`
completo for adicionado no futuro, `hcaptcha.com`, `js.hcaptcha.com` e
`challenges.cloudflare.com` precisam entrar na allowlist ou o widget morre em
silêncio.

---

## Passos para ativar

1. Fechar os três bloqueadores de conformidade acima, para o provedor escolhido.
2. Criar o site no dashboard do provedor (hCaptcha ou Cloudflare) e pegar o par
   de chaves.
3. No `.env` do servidor: as chaves do provedor + `CAPTCHA_PROVIDER=hcaptcha`
   (ou `=turnstile`).
4. `php artisan config:cache`.
5. Conferir em staging que login, cadastro de membro e o **passo 3 do wizard da
   performer** exibem o widget certo e completam.
