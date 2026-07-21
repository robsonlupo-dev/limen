# Geobloqueio por país (FOSTA-SESTA)

> **Estado: MONTADO, NÃO ATIVO.** Com `GEO_DRIVER=none` — o padrão e o valor de
> hoje em produção — o middleware `GeoBlock` roda em toda requisição `web` e
> `api` e **não bloqueia ninguém**. Falta a fonte de geolocalização.
>
> Enquanto isso não mudar, **o Limen não bloqueia usuários americanos.** Não
> descreva o contrário em política, contrato, pitch ou auditoria.

## Por que existe

A FOSTA-SESTA (EUA, 2018) retirou a imunidade da Section 230 para plataformas
em conteúdo de terceiros ligado a prostituição. O Limen não opera nos EUA e não
quer usuários americanos; barrar na borda reduz exposição e demonstra intenção.

## O que está implementado

| Peça | Arquivo |
| --- | --- |
| Configuração (driver, lista de países, fail-open) | `config/geo.php` |
| Resolução do país | `app/Services/GeoLocationService.php` |
| Bloqueio + audit | `app/Http/Middleware/GeoBlock.php` |
| Testes | `tests/Feature/GeoBlockTest.php` |

- Resposta **451 Unavailable For Legal Reasons** (não 403: o 451 existe para
  "eu poderia te atender, a lei é que não deixa", e misturar os dois estraga o
  monitoramento). HTML na porta web, JSON em `api/*`.
- Países em `BLOCKED_COUNTRIES` (CSV, ISO 3166-1 alfa-2). Padrão: `US`.
- `/up` fica **de fora** de propósito: monitor de uptime costuma sondar dos EUA.
- Tentativa barrada vira `access.geo_blocked` no `audit_logs`, **uma linha por
  IP por hora** (`GEO_AUDIT_DEDUP_MINUTES`). Sem a deduplicação, um bot em laço
  num endpoint não autenticado enterra a trilha real.

## Como ativar

### Opção A — Cloudflare (mais barato, exige trava de rede)

1. Colocar o domínio atrás do Cloudflare.
2. **Fechar o origin**: o nginx do `62.238.46.212` tem que aceitar apenas os
   ranges publicados do Cloudflare.
3. `GEO_DRIVER=cloudflare` no `.env` + `php artisan config:cache`.

> ⚠️ **O passo 2 não é opcional — sem ele o driver não bloqueia nada.**
> `CF-IPCountry` é um header comum, e o PHP não tem como saber se foi o
> Cloudflare que o escreveu. Batendo direto no IP do servidor com
> `curl -H 'CF-IPCountry: BR'`, um usuário dos EUA passa. Pior que não ter:
> o painel mostraria "geobloqueio ativo".

### Opção B — MaxMind GeoLite2 (independe de proxy)

1. Conta gratuita na MaxMind, chave de licença, e o `.mmdb` no servidor com
   atualização automática (`geoipupdate`).
2. `composer require geoip2/geoip2`.
3. Driver `maxmind` em `GeoLocationService` (resolve por `$request->ip()`).
4. **Configurar `TrustProxies`** — hoje o projeto não configura nenhum. Sem
   isso, no dia que entrar qualquer proxy na frente, `$request->ip()` passa a
   ser o IP do proxy e a geolocalização inteira aponta para o datacenter.

Recomendação: **B**. Não depende de o origin estar fechado, e não some se o
Cloudflare for retirado.

## Limite conhecido — não é garantia jurídica

Geobloqueio por IP é contornado por qualquer VPN, e VPN é barata e comum. Isto
**reduz exposição**; não impede acesso. É o mesmo cuidado de linguagem que o
`CLAUDE.md` já exige para o painel de visitantes ("não descreva como anônimo"):

- ✅ "bloqueamos acessos identificados como originários dos EUA"
- ❌ "usuários dos EUA não conseguem acessar a plataforma"

O bloqueio também é só de **acesso HTTP**. Não há verificação de nacionalidade
no cadastro, nem no KYC (o Didit valida documento, não cidadania). Um americano
com VPN cria conta normalmente — fechar isso é decisão de produto sobre o
cadastro, não sobre este middleware.
