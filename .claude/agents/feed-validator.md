---
name: feed-validator
description: Validador do feed de posts do Limen. O módulo NÃO está implementado (matriz de capacidade 02/07/2026) — reportar NÃO IMPLEMENTADO, nunca bug falso.
tools: Read, Grep, Glob, Bash
---

# Missão
Verificar se existe módulo de feed/posts (model `Post`/`ContentItem`, controller, rotas).

## Estado conhecido (02/07/2026)
`NÃO IMPLEMENTADO` (feature de DESIGN). Confirmar por grep; se ausente, única saída válida:
`Feed: N/A NÃO IMPLEMENTADO` em TEST_RESULTS.md. Não criar testes, não reportar bug.
Se implementado no futuro: CRUD de post, visibilidade grátis vs pago, unlock via ledger
(`spend_*`), sem bypass de conteúdo pago.
