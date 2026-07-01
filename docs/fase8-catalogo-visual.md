# Fase 8 — Catálogo Visual de Performers (Limen)

A vitrine do produto — o que o consumer vê logo após o login.
Encaixa nas rotas web (Inertia+Vue) da Fase 7 e na API de perfis da Fase 4.
O Catalog.vue já existe como placeholder — vamos substituí-lo.

## Escopo
- Tela de catálogo com grid de performers (filtros, busca, paginação).
- Tela de perfil público individual do performer.
- Componente de card de performer (avatar, nome artístico, categoria, status ao vivo, rating).
- Botão de follow/unfollow integrado (consumer autenticado).
- Contador de seguidores atualizado no ato.
- Badge "Verificado" e indicador "Ao vivo" nos cards.
- Estado vazio (nenhum performer ainda) tratado com elegância.

## Regras de negócio (herdar da Fase 4)
- Só performers `active + is_verified = true` aparecem — NUNCA performers pending.
- Filtros: categoria (mulheres/homens/casais/trans/gls/swing), ao vivo (is_live),
  busca por stage_name.
- Ordenação: rating (desc) | seguidores (desc) | mais recentes.
- Paginação: 20 por página, scroll ou botão "carregar mais".
- Avatar e cover: URLs temporárias assinadas (60 min) — nunca path direto.
- Perfil público NÃO expõe: user_id, email, CPF, split_pct, dados internos.

## Controllers web (novos, finos)
- `Web/CatalogController`: index (lista) e show (perfil por slug).
  Chama os mesmos Services/Repositories da API da Fase 4.
  Retorna Inertia::render() com os dados prontos.
- `Web/FollowController`: store e destroy (follow/unfollow via POST/DELETE),
  retorna redirect()->back() com flash de sucesso.

## Rotas web (adicionar em routes/web.php)
- GET  /catalogo              → CatalogController@index   (auth)
- GET  /catalogo/{slug}       → CatalogController@show    (auth)
- POST /catalogo/{slug}/seguir   → FollowController@store  (auth, consumer)
- DELETE /catalogo/{slug}/seguir → FollowController@destroy (auth, consumer)

## Páginas Vue (resources/js/Pages/)
### Catalog/Index.vue
- Header com filtros (categoria, ao vivo, busca, ordenação) — inline, sem modal.
- Grid responsivo de PerformerCard (2 col mobile, 3 tablet, 4 desktop).
- Paginação no final (botão "Ver mais" ou links de página).
- Estado vazio: mensagem elegante + sugestão de remover filtros.
- Skeleton loading enquanto carrega (usando Tailwind animate-pulse).

### Catalog/Show.vue
- Hero com cover (imagem ou gradiente dourado se ausente).
- Avatar sobreposto ao cover + badge Verificado.
- Stage name (serifada, grande) + categoria + rates.
- Bio completa. Rating com estrelas. Contadores (seguidores, gorjetas).
- Botão Seguir/Deixar de seguir (estado reativo via Inertia useForm).
- Seção "O que ofereço" (work_modes como badges).
- CTA "Enviar gorjeta" (abre modal com input de amount — placeholder,
  lógica real na Fase 9).
- Indicador "Ao vivo agora" com pulsação dourada se is_live=true.

## Componentes (resources/js/Components/)
- `PerformerCard.vue`: card com avatar, nome, categoria, is_live badge,
  rating_avg, followers_count, botão seguir.
- `FilterBar.vue`: filtros do catálogo.
- `FollowButton.vue`: segue/deixa de seguir com estado loading.
- `StarRating.vue`: exibe rating_avg de 0-5 com estrelas douradas.
- `LiveBadge.vue`: pulsação dourada "Ao vivo".
- `VerifiedBadge.vue`: ✓ Verificado discreto.

## Design (skill limen-design-system)
- Cards: fundo surface (#16161A), borda sutil, hover eleva com sombra dourada sutil.
- Avatar: circular, borda dourada fina nos cards.
- Badge Verificado: dourado discreto, pequeno.
- Live badge: ponto pulsante vermelho/dourado + texto "Ao vivo".
- Filtros: estilo minimalista, fundo surface-2, acento gold no selecionado.
- Sem elementos vulgares ou explícitos — posicionamento premium.

## Testes (Pest, feature) obrigatórios
1. GET /catalogo sem auth → redireciona para /login.
2. GET /catalogo com consumer autenticado → renderiza Catalog/Index.
3. Performers pending NÃO aparecem no catálogo.
4. Performers active+verified aparecem.
5. Filtro por categoria funciona.
6. Busca por stage_name funciona.
7. GET /catalogo/{slug} renderiza Catalog/Show com dados corretos.
8. GET /catalogo/{slug} de performer pending → 404.
9. POST /seguir cria follow e redireciona com flash.
10. DELETE /seguir remove follow.
11. Consumer não pode seguir a si mesmo (se for performer).
12. Perfil público não expõe user_id, email, CPF nos dados da página.

## Definição de pronto
- Catálogo e perfil funcionando visualmente com a marca Limen.
- Filtros e busca operacionais.
- Follow/unfollow funcionando.
- 12 testes verdes.
- Subagente security-reviewer rodado.
- Commit e push.
