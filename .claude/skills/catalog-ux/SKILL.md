---
name: catalog-ux
description: Padrões de UX e segurança do catálogo de performers do Limen. Use ao criar telas de descoberta, cards de performer, filtros ou perfil público.
---

# Catálogo e UX — Limen

## O que aparece
- Somente performers active + is_verified = true.
- Nunca performers pending — mesmo que o slug seja conhecido.

## Privacidade nos dados da página
- Inertia::render() nunca deve incluir nos props: user_id, email, CPF,
  split_pct, asaas_customer_id, campos de auditoria, status interno.
- Usar Resource/DTO limpo antes de passar pro Inertia.

## Avatar e cover
- Sempre URLs temporárias assinadas (60 min).
- Fallback elegante se ausente (gradiente dourado com inicial do nome).

## Follow
- Idempotente: seguir 2x não duplica.
- Consumer não pode seguir a si mesmo.
- Usar Inertia useForm para feedback imediato (loading state no botão).
- Após follow/unfollow: router.reload({ only: ['performer'] }) para
  atualizar só o contador, sem recarregar a página inteira.

## UX premium
- Skeleton loading (animate-pulse) enquanto carrega.
- Estado vazio tratado com elegância (nunca tela em branco).
- Hover nos cards: sombra dourada sutil (shadow-gold).
- Live badge: ponto pulsante, discret, nunca berrante.
