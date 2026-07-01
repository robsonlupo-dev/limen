# Briefing para o Claude Code — Fase 8

/clear antes de colar. Reinicie a sessão.

---

Você é o dev do projeto Limen. Leia CLAUDE.md, as skills `catalog-ux`,
`limen-design-system`, `frontend-inertia-vue` e `security-checklist`,
e a spec `docs/fase8-catalogo-visual.md`.

Implemente a Fase 8 conforme a spec:

1. Controllers web: Web/CatalogController (index + show) e Web/FollowController
   (store + destroy). Finos, reusando os Services/repositórios da Fase 4.
   Retornar Inertia::render() com Resource limpo (nunca expor user_id/email/CPF).
   URLs de avatar/cover como temporaryUrl de 60 min.

2. Rotas web em routes/web.php: GET /catalogo, GET /catalogo/{slug},
   POST e DELETE /catalogo/{slug}/seguir.

3. Componentes Vue: PerformerCard, FilterBar, FollowButton, StarRating,
   LiveBadge, VerifiedBadge — seguindo o design system Limen (dark, gold).

4. Páginas Vue: Catalog/Index.vue (grid + filtros + paginação + skeleton +
   estado vazio) e Catalog/Show.vue (hero cover + avatar + bio + follow +
   CTA gorjeta placeholder).

5. O Catalog.vue placeholder da Fase 7 deve ser substituído/removido.

6. Escreva os 12 testes da spec.

Quando terminar:
- invoque o subagente security-reviewer no CatalogController e nos dados
  passados para o Inertia (verificar que nenhum campo sensível vaza)
- rode os testes até verde
- npm run build (confirmar que compila sem erro)
- mostre resumo, resultado e achados de segurança
- commit "feat: add performer catalog and profile pages (fase 8)"
- push

Não faça nada além da Fase 8. Em caso de ambiguidade, pergunte antes.
