# Fase 4 — Perfis de Performers + Catálogo Público (Limen)

Catálogo navegável e gestão de perfil do performer. Encaixa em `performer_profiles`
(Fase 1) e no sistema de auth (Fase 2). O performer gerencia seu próprio perfil e
mídia; o consumer navega, pesquisa e segue performers; visitantes acessam o catálogo
sem autenticação.

## Princípios centrais de segurança

- **PII nunca no catálogo público.** `user_id`, email, CPF, rates e `split_pct` são
  campos internos e NUNCA aparecem na `PerformerPublicResource`.
- **Mídia em storage privado.** Avatares e covers ficam no disco local (fora do
  `public/`). O acesso é sempre via signed URL temporária (60 min), nunca via
  caminho direto em `/storage/`.
- **Signed URL usa `profile_id`, não `user_id`.** Evita expor o ID interno do user
  em URLs públicas (URL de mídia pode ser compartilhada).
- **Catálogo filtra ativos+verificados.** `scopePublicCatalog` garante que performers
  `pending`, `suspended` ou `is_verified=false` não aparecem nem por slug.

## Migrations

- `add_slug_to_performer_profiles` — coluna `slug` (unique, nullable) em
  `performer_profiles`. Gerado automaticamente no primeiro update de perfil se ausente.
- `create_follows_table` — tabela `follows` (`user_id`, `performer_profile_id`,
  unique composto). `followers_count` já existia em `performer_profiles` (Fase 1);
  é incrementado/decrementado atomicamente via `DB::increment/decrement`.

## Endpoints

### Catálogo público (sem auth)

`GET /api/v1/performers` — lista paginada (20/página) de performers ativos e verificados.
- Filtros: `?category=` `?work_mode=` `?is_live=1` `?search=` (stage_name ou bio).
- Ordenação: `?sort=rating_avg` (padrão) | `followers_count` | `newest`.
- Resposta: `PerformerPublicResource` (slug, stage_name, bio, category, work_modes,
  is_live, rating_avg, rating_count, followers_count, avatar_url, cover_url).

`GET /api/v1/performers/{slug}` — perfil público de um performer.
- 404 se não existir ou não passar em `scopePublicCatalog`.

### Gestão de perfil (auth:sanctum + role:performer)

`GET /api/v1/performer/profile` — perfil completo do próprio performer
  (`PerformerPrivateResource`, inclui rates e level).

`PUT /api/v1/performer/profile` — atualiza bio, category, work_modes, rates.
- Validação via `UpdatePerformerProfileRequest`.
- Gera `slug` automaticamente no primeiro update (se ainda não tiver).
- Policy `PerformerProfilePolicy@update` garante que só o dono atualiza.

`POST /api/v1/performer/profile/avatar` — upload de avatar (jpeg/png/webp, max 5 MB).
- Armazena em `performer-media/{user_id}/avatar.{ext}` no disco `local`.
- Atualiza `avatar_path` no perfil.
- Retorna `{ avatar_url: <signed_url> }`.

`POST /api/v1/performer/profile/cover` — upload de cover. Mesma lógica do avatar,
  armazena como `cover.{ext}` e retorna `cover_url`.

### Follow/unfollow (auth:sanctum + role:consumer)

`POST /api/v1/performers/{slug}/follow` — segue um performer.
- Idempotente: `firstOrCreate` garante que `Follow` não é duplicado.
- Incrementa `followers_count` atomicamente dentro de transação.
- Retorna `{ following: true, followers_count: N }`.

`DELETE /api/v1/performers/{slug}/follow` — deixa de seguir.
- Só decrementa se o `Follow` existia (evita contagem negativa).
- Retorna `{ following: false, followers_count: N }`.

`GET /api/v1/performers/{slug}/following` — verifica se o consumer autenticado segue o performer.
- Retorna `{ following: true|false, followers_count: N }`.

### Mídia privada (signed URL)

`GET /api/v1/performer-media?profile_id=&type=avatar|cover&...` — middleware `signed`.
- Verifica `profile_id` + `type`, lê do disco local e faz stream do arquivo.
- URL expira em 60 min; parâmetros adulterados retornam 403 (assinatura inválida).

## Testes (Pest) — 12 casos obrigatórios

1. Performer atualiza o próprio perfil → recebe dados atualizados; DB atualizado.
2. Consumer tenta atualizar perfil de performer → 403.
3. Catálogo retorna só performers active+verified (pending, suspended e unverified excluídos).
4. Filtro por category funciona.
5. Busca por stage_name funciona.
6. Perfil público não expõe user_id, email, cpf, rates, split_pct, level.
7. Follow incrementa followers_count e é idempotente na segunda chamada.
8. Unfollow decrementa followers_count.
9. GET /following retorna boolean correto antes e depois do follow.
10. Upload de avatar salva em storage privado e retorna signed URL (não contém `/storage/`).
11. Arquivo inválido (pdf ou > 5 MB) retorna 422 com erro no campo `file`.
12. Performer pending não aparece no catálogo e retorna 404 no slug direto.

## Definição de pronto

- Todos os 12 testes verdes.
- Mídia servida exclusivamente via signed URL (nenhuma rota pública para `storage/`).
- PII ausente de qualquer resposta pública.
- Subagente `security-reviewer` rodado no fluxo de perfil + mídia, achados triados.
