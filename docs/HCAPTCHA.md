# hCaptcha — anti-bot em login e cadastro

**Sprint 9.** Decisão do PO: hCaptcha, não reCAPTCHA (mesmo modelo do Seeking).

**Estado: montado, DESLIGADO.** `HCAPTCHA_ENABLED=false` é o padrão, o valor
versionado e o estado de dev, teste e CI. Enquanto estiver assim, o captcha é
no-op completo — ver "O que 'desligado' significa".

---

## ⚠️ Bloqueadores de conformidade — ler ANTES de ligar em produção

hCaptcha é **subprocessador**. Ligado, o browser de cada pessoa que abre
`/login` ou `/cadastro` passa a falar direto com `hcaptcha.com`, entregando
**IP, User-Agent e horário** à Intuition Machines (operadora do hCaptcha).

Numa plataforma de conteúdo adulto isso não é telemetria banal: o fato de que
"este IP tentou entrar na Limen" já é dado sensível por si só, e é gerado
**antes** de a pessoa sequer ter conta.

Três itens precisam estar fechados antes de `HCAPTCHA_ENABLED=true`:

1. **Política de privacidade** — hCaptcha listado com finalidade (prevenção de
   fraude/abuso), dados tratados (IP, User-Agent, sinais de interação) e base
   legal (LGPD art. 7º — legítimo interesse é o candidato, e precisa de
   avaliação registrada).
2. **Registro de subprocessadores** — hCaptcha ao lado de Asaas e Didit.
3. **DPA assinado e arquivado** com a Intuition Machines.

Nenhum dos três é código. São pré-condição para o interruptor, e é por isso que
ele nasce desligado em vez de nascer ligado "para testar".

> **Disciplina de linguagem** (mesma regra do painel de visitantes e do
> geobloqueio): captcha **não** é garantia de que não há bot. Serviços de
> resolução de captcha custam centavos por desafio. Isto encarece automação em
> massa; não a elimina. Não escreva "bots não conseguem se cadastrar" em
> política, contrato ou auditoria.

---

## Onde é aplicado — e onde deliberadamente NÃO é

Quatro portas, todas via `App\Rules\HCaptchaValid`:

| Porta | Form Request |
|---|---|
| `POST /login` (web) | `Auth\LoginRequest` |
| `POST /api/v1/auth/login` | `Auth\LoginRequest` (o mesmo — as duas portas compartilham) |
| `POST /cadastro` (web, membro **e** wizard da performer) | `Web\RegisterWebRequest` |
| `POST /api/v1/auth/register/{consumer,performer}` | `RegisterConsumerRequest` (o performer herda) |

**Fora do captcha, por decisão do PO:** reset de senha, verificação de e-mail e
todo o resto. A regra é de uma dona só justamente para a quinta porta não
nascer sem ela — é a lição que o `CLAUDE.md` tira do `documents.accepted`.

---

## O que "desligado" significa

`HCAPTCHA_ENABLED=false` é no-op nos três níveis, e o do meio é o que garante
que nenhum byte sai para o terceiro:

1. **Validação** — `HCaptchaValid::rules()` devolve `['nullable']`. O campo não
   é exigido; cliente antigo e teste antigo continuam passando sem mandá-lo.
2. **Frontend** — `HandleInertiaRequests` não manda o `sitekey`, o componente
   `HCaptcha.vue` não é montado e o SDK **não é buscado**.
3. **Verificador** — `HCaptchaVerifier::verify()` devolve `true` na primeira
   linha, sem tocar na rede.

---

## Por que o SDK não está no `app.blade.php`

`docs/PIXEL_AUDIT.md` audita uma regra que o `CLAUDE.md` chama de inviolável:
**zero pixels de terceiros em área logada**. Foi por ela que as fontes do Google
viraram self-host.

`app.blade.php` é a view raiz de **toda** página Inertia. Uma `<script src>` do
hCaptcha ali carregaria em catálogo, chat, carteira e painel da performer — cada
tela logada viraria uma requisição a `hcaptcha.com` com IP e horário do membro,
o que é exatamente o que a auditoria fechou.

Por isso o SDK é injetado por `resources/js/Components/HCaptcha.vue`, que só
existe nas duas telas de auth (públicas, deslogadas). **Não mova o script para o
Blade** — seria desfazer a auditoria inteira para economizar dez linhas.

---

## Decisões de implementação

**Sem pacote de terceiro.** O composer deste ambiente não alcança o packagist, e
o contrato do hCaptcha é um POST de formulário com dois campos. `HCaptchaVerifier`
segue o padrão do `DiditKycClient`/`AsaasHttpClient` (`Http::` + timeout curto),
o que também deixa o teste usar `Http::fake()`.

**`remoteip` NÃO é enviado no siteverify.** O campo é opcional. O browser já
entregou o IP ao hCaptcha ao carregar o widget; mandá-lo de novo do servidor
seria a Limen **transmitindo ativamente** o IP do titular ao subprocessador —
que é o peso exato da ressalva acima. Ligar isso é decisão de produto.

**Falha de rede não derruba o login.** Token recusado pelo hCaptcha bloqueia (é o
ponto). Mas timeout, 5xx ou provedor fora do ar **deixam passar**, com
`Log::warning`. É a mesma escolha, e pelo mesmo motivo, do fail-OPEN do
`GeoBlock` (`config/geo.php`): indisponibilidade de terceiro não pode trancar a
plataforma inteira para fora. Quem quiser fail-closed precisa decidir isso
conscientemente e aceitar que uma queda do hCaptcha vira uma queda do login.

**Token é de uso único.** Depois de um submit recusado por qualquer motivo
(senha errada, e-mail duplicado), o token foi junto e queimou. Os três
formulários chamam `captcha.reset()` no `onError` — sem isso a segunda tentativa
falharia no captcha, e a pessoa veria "verificação de segurança falhou" quando o
erro real era a senha.

**CSP não precisou mudar.** `SecurityHeaders` manda só
`Content-Security-Policy: frame-ancestors 'self'`, que restringe quem pode
enquadrar *as nossas* páginas — não o que nós embutimos. O iframe do hCaptcha
cairia sob `frame-src`, que não está definido. Se um `default-src`/`script-src`
completo for adicionado no futuro, `hcaptcha.com` e `js.hcaptcha.com` precisam
entrar na allowlist ou o widget morre em silêncio.

---

## Passos para ativar

1. Fechar os três bloqueadores de conformidade acima.
2. Criar o site no dashboard do hCaptcha e pegar o par de chaves.
3. No `.env` do servidor: `HCAPTCHA_SITEKEY`, `HCAPTCHA_SECRET`,
   `HCAPTCHA_ENABLED=true`.
4. `php artisan config:cache` (o valor é lido por `config()`, então cache velho
   mantém o captcha desligado).
5. Conferir em staging que login, cadastro de membro e o **passo 3 do wizard da
   performer** exibem o widget e completam.
