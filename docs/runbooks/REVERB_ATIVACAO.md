# Runbook — Ativar o Laravel Reverb (chat em tempo real) em produção

> **O que isto liga:** o recebimento de mensagens **em tempo real** no chat 1:1
> (texto e voz). Hoje `BROADCAST_CONNECTION=log`: o remetente vê a bolha "brotar"
> na hora, mas o outro lado só recebe no próximo reload. Com o Reverb de pé, a
> mensagem chega ao outro lado sozinha, estilo WhatsApp.
>
> **Rede de proteção:** o chat **NÃO depende** do Reverb para funcionar. Se o
> Reverb cair ou nunca subir, o envio otimista + o reload seguem entregando as
> mensagens. O Reverb só adiciona o "empurrão" em tempo real. Por isso o passo
> perigoso (virar o driver) é o **último** e reverter é **uma linha**.

O código já está pronto: `laravel/reverb` está no `composer.json`, o canal privado
`conversation.{id}` está autorizado em `routes/channels.php` (só os dois
participantes), e o `Chat/Show.vue` já assina o canal e renderiza o que chega. Só
falta a infra abaixo.

**Faça na ordem.** Passos 1→5 são preparação e não mudam nada para o usuário. O
passo 6 é o único que muda o comportamento — só chegue nele depois de validar o 5.

Tudo roda no servidor, no checkout de produção `/var/www/limen` (que fica **sempre
na main**). Onde disser `<...>`, troque pelo valor real.

---

## Pré-requisitos (confirmar uma vez)

```bash
cd /var/www/limen
git branch --show-current            # tem que ser: main
php -r "echo class_exists('Laravel\\Reverb\\ReverbServiceProvider')?'reverb ok\n':'FALTA reverb\n';"
redis-cli ping                       # PONG — o Reverb usa Redis para escalar conexões
which supervisorctl nginx            # os dois têm que existir
```

Se algum falhar, pare e resolva antes de continuar.

---

## Passo 1 — Credenciais e variáveis no `.env` (NÃO virar o driver ainda)

Gere um trio de credenciais do app Reverb (valores livres, só precisam ser
secretos e casar entre servidor e cliente):

```bash
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 32)"
```

Edite o `.env` de produção e adicione/ajuste **exatamente** estas linhas
(mantendo `BROADCAST_CONNECTION=log` por enquanto):

```dotenv
# --- Reverb: onde o PROCESSO escuta (interno, atrás do nginx) ---
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# --- Reverb: credenciais do app (do passo acima) ---
REVERB_APP_ID=<gerado>
REVERB_APP_KEY=<gerado>
REVERB_APP_SECRET=<gerado>

# --- Reverb: endereço PÚBLICO (o PHP publica aqui e o navegador conecta aqui) ---
REVERB_HOST="thelimen.com.br"
REVERB_PORT=443
REVERB_SCHEME=https

# --- Espelho para o cliente (Echo em resources/js/bootstrap.js) ---
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# ⚠️ NÃO mude ainda: só no passo 6.
BROADCAST_CONNECTION=log
```

Por que dois hosts: o **processo** escuta em `127.0.0.1:8080` (`REVERB_SERVER_*`);
o **PHP** e o **navegador** falam com `https://thelimen.com.br:443` (`REVERB_*` /
`VITE_REVERB_*`), e o nginx (passo 3) faz a ponte de um para o outro. O `${...}`
funciona porque o Vite expande no build; se seu `.env` não expandir, repita o
valor literal nas `VITE_*`.

---

## Passo 2 — Programa do supervisor (mantém o Reverb de pé)

Crie `/etc/supervisor/conf.d/limen-reverb.conf` (como root):

```ini
[program:limen-reverb]
process_name=%(program_name)s
command=php /var/www/limen/artisan reverb:start
directory=/var/www/limen
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/limen/storage/logs/reverb.log
stopwaitsecs=10
```

Suba:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start limen-reverb
sudo supervisorctl status limen-reverb        # tem que dizer RUNNING
```

Confirme que está escutando na porta interna:

```bash
ss -ltnp | grep 8080                          # deve aparecer o processo do reverb
tail -n 30 /var/www/limen/storage/logs/reverb.log
```

> O nome `limen-reverb` casa com o restart tolerante que já vai no `deploy.yml`
> (`sudo supervisorctl restart limen-reverb || true`). Depois que este programa
> existir, todo deploy reinicia o Reverb junto com os workers.

---

## Passo 3 — nginx: proxy do WebSocket (WSS)

Dentro do `server { ... }` do vhost **TLS** de `thelimen.com.br` (o bloco `listen
443 ssl`), acrescente os dois `location` abaixo. O `/app` é o WebSocket do
navegador; o `/apps` é a API HTTP por onde o PHP publica os eventos.

```nginx
    # Reverb — WebSocket do chat em tempo real
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "Upgrade";
        proxy_read_timeout  3600s;
        proxy_send_timeout  3600s;
    }

    # Reverb — API HTTP de publicação (PHP -> Reverb)
    location /apps {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
```

Antes de recarregar, confirme que a app **não** usa rotas começando com `/app` ou
`/apps` (o Limen usa `/chat`, `/moderacao`, etc., então não colide — mas cheque):

```bash
grep -rnE "->prefix\('app" routes/ ; grep -rnE "Route::.*'/apps?" routes/   # esperado: vazio
sudo nginx -t
sudo systemctl reload nginx
```

---

## Passo 4 — Rebuild do front com as `VITE_*`

O Echo só instancia se `VITE_REVERB_APP_KEY` existir **no momento do build**. Como
você acabou de preencher no passo 1, rebuilde:

```bash
cd /var/www/limen
mkdir -p public/build && sudo chown -R deploy:deploy public/build
npm ci
npm run build
php artisan config:cache
```

> No próximo deploy pela Action isso acontece sozinho; aqui é manual porque você
> está preenchendo o `.env` fora do fluxo de deploy.

---

## Passo 5 — VALIDAÇÃO (antes de virar o driver)

Ainda com `BROADCAST_CONNECTION=log`, prove que o caminho público chega no Reverb:

```bash
# 1) O handshake WSS responde através do nginx (401/400 do protocolo já prova que
#    o proxy alcança o Reverb; o que NÃO pode é 404/502).
curl -sS -o /dev/null -w "%{http_code}\n" "https://thelimen.com.br/app/${REVERB_APP_KEY}"

# 2) O processo está de pé e sem erro no log
sudo supervisorctl status limen-reverb
tail -n 30 /var/www/limen/storage/logs/reverb.log
```

- `404` no item 1 → o nginx não está mandando `/app` pro Reverb (revise o passo 3).
- `502` → o processo do Reverb não está de pé (revise o passo 2).
- `400`/`426`/uma resposta do protocolo Pusher → **ótimo**, o caminho está fechado.

Só siga para o passo 6 quando o item 1 não for 404/502.

---

## Passo 6 — O FLIP (o único passo que muda o comportamento)

```bash
cd /var/www/limen
# edite o .env: troque a linha
#   BROADCAST_CONNECTION=log   ->   BROADCAST_CONNECTION=reverb
php artisan config:cache
sudo supervisorctl restart limen-worker:*
# se o site não pegar a config nova na hora, recarregue o php-fpm:
#   sudo systemctl reload php8.4-fpm   (se o sudoers permitir; senão, no próximo deploy pega)
```

**Teste de aceitação (2 navegadores):** abra a mesma conversa como o membro e como
a performer, em janelas diferentes. Envie uma mensagem de um lado — ela deve
aparecer no outro **sem reload**. Repita com uma **mensagem de voz**: no
remetente ela nasce "Processando áudio…" e, quando o job termina, o player
aparece nos dois lados sozinho.

---

## Rollback (uma linha, imediato)

Se algo sair errado, volte o driver — o chat segue funcionando (só sem o tempo
real), como estava antes:

```bash
cd /var/www/limen
# .env:  BROADCAST_CONNECTION=reverb  ->  BROADCAST_CONNECTION=log
php artisan config:cache
sudo supervisorctl restart limen-worker:*
```

Pode deixar o processo `limen-reverb` rodando — sem o driver apontando pra ele,
ele fica ocioso e inofensivo. Reverter NÃO exige mexer em nginx nem no supervisor.

---

## Fazer primeiro no staging (recomendado)

Se `/var/www/limen` for produção e existir um ambiente de staging separado, rode
os passos 1→6 lá primeiro, com `REVERB_HOST` = domínio de staging, valide o teste
dos 2 navegadores, e só então repita em produção. O runbook é idêntico; muda só o
host.

---

## Endurecimento opcional (depois de estabilizar)

- **`allowed_origins`**: hoje `config/reverb.php` traz `['*']`. Restringir para o
  domínio real fecha conexões de outras origens. É uma mudança de config (PR):
  trocar `'allowed_origins' => ['*']` por `['thelimen.com.br']` (ou ler de um
  `REVERB_ALLOWED_ORIGINS`). Não bloqueia a ativação; é um aperto posterior.
- **Firewall**: garanta que a porta `8080` **não** esteja aberta ao mundo (só
  `127.0.0.1`). O `REVERB_SERVER_HOST=0.0.0.0` escuta em todas as interfaces do
  host; o acesso externo tem que passar **só** pelo nginx (443). Se o host tiver
  IP público sem firewall, prefira `REVERB_SERVER_HOST=127.0.0.1`.

---

## Referência rápida

| Item | Valor |
|---|---|
| Processo | `php artisan reverb:start` sob supervisor `limen-reverb` |
| Escuta interna | `127.0.0.1:8080` (`REVERB_SERVER_HOST`/`REVERB_SERVER_PORT`) |
| Público | `wss://thelimen.com.br:443` via nginx (`/app`, `/apps`) |
| Driver | `.env` `BROADCAST_CONNECTION=reverb` (era `log`) |
| Canal | `conversation.{id}` privado (`routes/channels.php`) |
| Evento | `App\Events\MessageSent` (`.message.sent`) |
| Log | `storage/logs/reverb.log` |
| Rollback | `BROADCAST_CONNECTION=log` + `config:cache` |
