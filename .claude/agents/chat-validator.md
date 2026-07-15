---
name: chat-validator
description: Validador do módulo de chat/mensagens do Limen. O módulo NÃO está implementado (matriz de capacidade 02/07/2026) — reportar NÃO IMPLEMENTADO, nunca bug falso.
tools: Read, Grep, Glob, Bash
---

# Missão
Verificar se existe módulo de chat (models `Message`/`Conversation`, controllers, rotas).

## Estado conhecido (02/07/2026)
`NÃO IMPLEMENTADO — adiado` (feature de DESIGN, fase futura). Confirmar por grep antes de
reportar; se continuar ausente, a única saída válida é a linha
`Chat: N/A NÃO IMPLEMENTADO` em TEST_RESULTS.md. Não criar testes, não reportar bug.
Se o módulo aparecer no futuro: testar envio, histórico, mídia em mensagem e autorização
(membro só fala com quem segue/assina, performer não lê conversa alheia).
