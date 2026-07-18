#!/usr/bin/env bash
#
# setup-reverb-server.sh — configura o Reverb no servidor DEPOIS do deploy.
#
# Idempotente: pode rodar várias vezes sem duplicar linhas de .env, blocos de
# nginx ou programas do supervisor. Deve rodar NO servidor, como root (mexe em
# /etc, supervisorctl e nginx). Os passos de artisan/npm rodam como o usuário
# da aplicação (deploy).
#
# Pré-requisito: o branch com laravel/reverb JÁ precisa estar deployado (o
# passo 1 aborta se `php artisan reverb:start` não existir).
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
APP_DIR="/var/www/limen"
APP_USER="deploy"
DOMAIN="limen.dev.br"
ENV_FILE="${APP_DIR}/.env"
NGINX_SITE="/etc/nginx/sites-available/${DOMAIN}"
SUPERVISOR_CONF="/etc/supervisor/conf.d/limen-reverb.conf"
REVERB_PORT="8080"
TS="$(date +%Y%m%d-%H%M%S)"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2; exit 1; }

# Roda um comando como o usuário da aplicação (deploy), preservando permissões
# dos arquivos que o Laravel escreve (cache, storage) e do processo Reverb.
as_app() { sudo -u "${APP_USER}" "$@"; }

# set_env KEY VALUE — troca a linha KEY=... no .env se existir, senão adiciona.
# Não faz append cego (evita chaves duplicadas). Os valores usados aqui são
# simples (sem barras/&), então o sed com delimitador | é seguro.
set_env() {
  local key="$1" val="$2"
  if grep -qE "^${key}=" "${ENV_FILE}"; then
    sed -i -E "s|^${key}=.*|${key}=${val}|" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${val}" >> "${ENV_FILE}"
  fi
}

# ---------------------------------------------------------------------------
# Pré-checagens
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "rode como root (mexe em /etc, supervisor e nginx)."
[ -d "${APP_DIR}" ]  || die "APP_DIR não encontrado: ${APP_DIR}"
[ -f "${ENV_FILE}" ] || die ".env não encontrado: ${ENV_FILE}"
command -v php >/dev/null       || die "php não está no PATH."
PHP_BIN="$(command -v php)"     # caminho absoluto p/ o supervisor (PATH limitado)

# ---------------------------------------------------------------------------
# 1. Reverb precisa estar instalado (senão o supervisor entraria em crash-loop)
# ---------------------------------------------------------------------------
log "1/8 Verificando que laravel/reverb está instalado…"
if ! as_app "${PHP_BIN}" "${APP_DIR}/artisan" reverb:start --help >/dev/null 2>&1; then
  die "comando 'reverb:start' não existe — laravel/reverb não está instalado. Deploye o branch do chat/reverb (composer install) antes de rodar este script."
fi
echo "    ok — reverb:start disponível."

# ---------------------------------------------------------------------------
# 2 + 3. .env — BROADCAST_CONNECTION=reverb e VITE_REVERB_* para wss/443
#         (mantém os REVERB_APP_ID/KEY/SECRET reais que já estão no arquivo)
# ---------------------------------------------------------------------------
log "2/8 + 3/8 Atualizando ${ENV_FILE} (backup em .bak.${TS})…"
ENV_OWNER="$(stat -c '%U:%G' "${ENV_FILE}")"
ENV_MODE="$(stat -c '%a' "${ENV_FILE}")"
cp -a "${ENV_FILE}" "${ENV_FILE}.bak.${TS}"

set_env BROADCAST_CONNECTION reverb
set_env VITE_REVERB_HOST   "${DOMAIN}"
set_env VITE_REVERB_PORT   "443"
set_env VITE_REVERB_SCHEME "wss"

# sed -i pode trocar o dono do arquivo p/ root; restaura o original.
chown "${ENV_OWNER}" "${ENV_FILE}"
chmod "${ENV_MODE}" "${ENV_FILE}"
echo "    BROADCAST_CONNECTION=reverb ; VITE_REVERB_* -> ${DOMAIN}:443/wss (REVERB_APP_* preservados)."

# ---------------------------------------------------------------------------
# 4. Recriar cache de config (o .env não tem efeito enquanto o config estiver
#    cacheado — a box roda com bootstrap/cache/config.php)
# ---------------------------------------------------------------------------
log "4/8 Recriando cache de config…"
as_app "${PHP_BIN}" "${APP_DIR}/artisan" config:clear
as_app "${PHP_BIN}" "${APP_DIR}/artisan" config:cache

# ---------------------------------------------------------------------------
# 4b. Reinicia os workers p/ recarregarem BROADCAST_CONNECTION=reverb.
#     Os workers são long-running e leem o config UMA vez, no boot. Se subiram
#     ANTES deste config:cache (ex.: no passo de deploy, que reinicia os workers
#     antes deste script rodar), seguem com o driver de broadcast antigo
#     (null/log) em memória e DESCARTAM os eventos MessageSent em silêncio — a
#     mensagem persiste (201) mas nunca chega ao Reverb, então o outro lado não
#     recebe nada em tempo real. queue:restart sinaliza cada worker p/ sair
#     graciosamente após o job atual; o supervisor os sobe de novo já com o
#     config novo. (Usamos queue:restart, não supervisorctl: o nome do programa
#     do worker não é de responsabilidade deste script.)
# ---------------------------------------------------------------------------
log "4b/8 Reiniciando workers (queue:restart) p/ recarregar o broadcast driver…"
as_app "${PHP_BIN}" "${APP_DIR}/artisan" queue:restart

# ---------------------------------------------------------------------------
# 5. Supervisor — programa limen-reverb (sobrescreve o conf; idempotente)
# ---------------------------------------------------------------------------
log "5/8 Escrevendo ${SUPERVISOR_CONF}…"
cat > "${SUPERVISOR_CONF}" <<EOF
[program:limen-reverb]
command=${PHP_BIN} ${APP_DIR}/artisan reverb:start --host=0.0.0.0 --port=${REVERB_PORT}
directory=${APP_DIR}
user=${APP_USER}
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/limen-reverb.log
stopwaitsecs=10
EOF

supervisorctl reread
supervisorctl update
# `update` já sobe programas novos/alterados; o restart garante que uma config
# nova seja aplicada mesmo se o programa já existia e não mudou de hash.
supervisorctl restart limen-reverb || supervisorctl start limen-reverb

# ---------------------------------------------------------------------------
# 6. nginx — insere `location /app/` DENTRO do bloco server 443 (não no EOF).
#    Usa Python p/ casar as chaves e inserir antes do } de fechamento do bloco.
# ---------------------------------------------------------------------------
log "6/8 Inserindo location /app/ no bloco server 443 de ${NGINX_SITE}…"
[ -f "${NGINX_SITE}" ] || die "site nginx não encontrado: ${NGINX_SITE}"
cp -a "${NGINX_SITE}" "${NGINX_SITE}.bak.${TS}"

# Heredoc com aspas ('PYEOF') para o bash NÃO expandir $http_upgrade / $host.
if ! NGINX_SITE="${NGINX_SITE}" python3 <<'PYEOF'
import os, sys

path = os.environ["NGINX_SITE"]
snippet = (
    "\n"
    "    location /app/ {\n"
    "        proxy_pass http://127.0.0.1:8080;\n"
    "        proxy_http_version 1.1;\n"
    "        proxy_set_header Upgrade $http_upgrade;\n"
    "        proxy_set_header Connection upgrade;\n"
    "        proxy_set_header Host $host;\n"
    "        proxy_cache_bypass $http_upgrade;\n"
    "    }\n"
)

with open(path) as fh:
    content = fh.read()

if "location /app/" in content:
    print("    nginx: location /app/ já presente — pulando insert.")
    sys.exit(0)


def find_server_blocks(text):
    """Retorna [(idx_da_{_do_server, idx_do_}_correspondente), ...]."""
    blocks = []
    i, L = 0, len(text)
    while i < L:
        j = text.find("server", i)
        if j == -1:
            break
        k = j + len("server")
        # 'server' precisa ser seguido (após espaços) de '{' — descarta
        # 'server_name' e afins.
        while k < L and text[k] in " \t\r\n":
            k += 1
        if k < L and text[k] == "{":
            depth, m = 0, k
            while m < L:
                if text[m] == "{":
                    depth += 1
                elif text[m] == "}":
                    depth -= 1
                    if depth == 0:
                        break
                m += 1
            blocks.append((k, m))
            i = m + 1
        else:
            i = j + len("server")
    return blocks


target_close = None
for open_idx, close_idx in find_server_blocks(content):
    body = content[open_idx:close_idx]
    # o bloco 443 é o que faz TLS (listen 443 / ssl_certificate)
    if "listen 443" in body or "ssl_certificate" in body:
        target_close = close_idx
        break

if target_close is None:
    sys.stderr.write("    nginx: não achei o bloco server 443.\n")
    sys.exit(2)

new_content = content[:target_close] + snippet + content[target_close:]
with open(path, "w") as fh:
    fh.write(new_content)
print("    nginx: location /app/ inserido no bloco server 443.")
PYEOF
then
  warn "edição do nginx falhou — restaurando backup."
  cp -a "${NGINX_SITE}.bak.${TS}" "${NGINX_SITE}"
  die "não foi possível inserir o location /app/ no nginx."
fi

log "Validando config do nginx (nginx -t)…"
if nginx -t; then
  systemctl reload nginx
  echo "    nginx recarregado."
else
  warn "nginx -t falhou — restaurando backup e abortando."
  cp -a "${NGINX_SITE}.bak.${TS}" "${NGINX_SITE}"
  die "nginx -t reprovou; nada foi recarregado."
fi

# ---------------------------------------------------------------------------
# 7. Build do front p/ assar as VITE_REVERB_* novas nos assets
# ---------------------------------------------------------------------------
log "7/8 Rodando npm run build (aplica as VITE_REVERB_* novas)…"
as_app bash -lc "cd '${APP_DIR}' && npm run build"

# ---------------------------------------------------------------------------
# 8. Verificação
# ---------------------------------------------------------------------------
log "8/8 Verificando o Reverb…"
sleep 2
supervisorctl status limen-reverb || true
echo "    resposta em http://127.0.0.1:${REVERB_PORT} :"
curl -s "http://127.0.0.1:${REVERB_PORT}" | head -5 || warn "sem resposta na porta ${REVERB_PORT} (o processo subiu?)."

log "Concluído. Cheque 'supervisorctl status limen-reverb' e o WebSocket via wss://${DOMAIN}/app/."
