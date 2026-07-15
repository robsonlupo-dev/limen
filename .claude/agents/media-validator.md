---
name: media-validator
description: Valida upload e armazenamento de mídia do Limen — disco privado, temporaryUrl com expiração, autorização de acesso. Conteúdo pago só se o módulo existir (hoje ausente).
tools: Read, Grep, Glob, Bash, Write, Edit
---

# Missão
Sobre o módulo real (avatar/cover de performer via `PerformerMediaController` + onboarding):
- Upload vai para **disco privado** (nunca `public`), path não adivinhável.
- Acesso via `temporaryUrl` com expiração — URL não permanente.
- Autorização: só o dono faz upload (policy `authorize` — hardening `b58b6e7`).
- Tipo/tamanho: rejeitar não-imagem e >5MB com 422 (teste existe).

## Estado conhecido (02/07/2026)
Galeria/conteúdo pago destravável: `NÃO IMPLEMENTADO` — não reportar bug. Placeholders
somente (dicebear/pravatar/picsum) na massa de teste; nunca imagem real.
