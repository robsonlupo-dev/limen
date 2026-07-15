# LIMEN — LOAD REPORT (Operação de QA · 02/07/2026)

> k6 v0.57.0 · cenário read-heavy (`/`, `/entrada`, `/catalogo` com redirect→login) ·
> rampas 100 → 500 → 1000 VUs (6m20s total) · alvo: `php artisan serve` local
> (`PHP_CLI_SERVER_WORKERS=8`) na VM de dev, banco povoado com a massa de QA.
> Script versionado em `tests/load/limen-load.js`.

## ⚠️ RESSALVA OBRIGATÓRIA (leia antes dos números)
Estes números são de uma **VM VirtualBox de desenvolvimento** servindo PHP pelo servidor
embutido de dev (single-process, 8 workers) — **não representam produção** (VPS + nginx +
php-fpm + opcache + cache de config/rotas). Servem para (a) provar que a app não quebra
funcionalmente sob concorrência e (b) dar um piso de referência. O teste representativo
deve rodar no VPS antes do go-live.

## Números reais da execução

| Métrica | Valor |
|---|---|
| Requisições totais | 9.601 (25,3 req/s) |
| Iterações completas | 7.098 (+359 interrompidas no rampdown) |
| Checks 2xx/3xx | 94,54% (6.737 ✓ / 389 ✗) |
| `http_req_failed` | 4,05% (389 timeouts — todos na rampa de 1000) |
| Latência média | 15,49s |
| p50 / p90 / p95 / máx | 15,2s / 33,9s / 37,9s / 40,9s |
| Dados recebidos | 183 MB (480 kB/s) |
| Thresholds (`p95<800ms`, `fail<2%`) | ❌ ambos estourados |

## Ponto de saturação
Capacidade observada do alvo: **~25 req/s**. Com `sleep(1)` por iteração, a demanda de
100 VUs já é ~4× a capacidade — ou seja, **a saturação ocorre ainda na rampa de 100 VUs**;
as rampas de 500 e 1000 só empilham fila (latência cresce linearmente até 40s e o k6
passa a cortar por timeout na rampa de 1000: as 389 falhas são todas `request timeout`,
nenhum 5xx da aplicação).

## Gargalo observado
PHP built-in server (8 workers síncronos) + ausência de opcache/config-cache no modo dev.
O MySQL não foi o limitador (queries do catálogo retornam em ms com 50 performers). Nenhum
erro de aplicação, nenhum deadlock, nenhuma corrupção de dados durante todo o teste — o
banco povoado permaneceu íntegro (invariantes re-verificados após a carga).

## Leitura honesta
- ✅ **Estabilidade funcional sob concorrência:** zero 5xx, zero erro de app, ledger íntegro.
- ❌ **Métricas de latência não aproveitáveis** para capacidade de produção (ambiente errado).
- 📋 **Antes do go-live:** repetir no VPS com nginx+fpm+opcache e caches de produção;
  meta razoável para o read-path: p95 < 300ms @ 100 VUs.
