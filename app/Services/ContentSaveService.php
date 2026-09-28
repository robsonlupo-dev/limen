<?php

namespace App\Services;

use App\Exceptions\ContentException;
use App\Models\ContentSave;
use App\Models\PerformerContent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Salvos" do membro (roadmap social, Onda 3 §3.1) — dona única da regra.
 *
 * "Salvar para ver depois", e **privado**: a performer nunca sabe que a peça dela
 * foi salva. Toda a assimetria com qualquer sinal público vive aqui e no model
 * `ContentSave` — este serviço não tem, e não pode ganhar, um método que responda
 * a partir do lado dela ("quem salvou", "quantos salvaram"). Mesma disciplina do
 * FavoriteService, e pelo mesmo motivo.
 *
 * Nada deste fluxo entra em `audit_logs` (ver o cabeçalho de ContentSave/Favorite).
 */
class ContentSaveService
{
    public function __construct(private ContentVisibilityService $visibility) {}

    /**
     * Salva/desalva uma peça (um clique no bookmark). Devolve o estado NOVO.
     *
     * **Salvar exige poder VER a peça** (decisão do PO): não se salva um cadeado —
     * evita virar lista de desejos que revelaria intenção. Recusa 403 (`forbidden`),
     * indistinguível de "sem alcance". Desalvar é sempre permitido (mesmo que o
     * acesso tenha expirado depois), idempotente.
     *
     * Sob transação com `lockForUpdate` + catch do UNIQUE (mesmo par do
     * FavoriteService): o bookmark fica num tile de grade, exposto a duplo-submit;
     * o resultado correto de "criar o que já existe" é o estado final (salvo),
     * nunca um 500.
     */
    public function setSaved(User $member, PerformerContent $content, bool $on): bool
    {
        if (! $on) {
            ContentSave::query()
                ->where('user_id', $member->id)
                ->where('performer_content_id', $content->id)
                ->delete();

            return false;
        }

        if (! $this->visibility->canView($member, $content)) {
            throw ContentException::forbidden();
        }

        return DB::transaction(function () use ($member, $content) {
            $existing = ContentSave::query()
                ->where('user_id', $member->id)
                ->where('performer_content_id', $content->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return true; // já salvo — idempotente
            }

            try {
                // FKs fora do $fillable (ver ContentSave): atribuição explícita.
                $save = new ContentSave;
                $save->user_id = $member->id;
                $save->performer_content_id = $content->id;
                $save->save();
            } catch (UniqueConstraintViolationException) {
                // Corrida perdida: a outra requisição já criou a linha. O estado
                // pedido foi alcançado — "salvo" é a resposta correta.
            }

            return true;
        });
    }

    public function isSaved(?User $member, PerformerContent $content): bool
    {
        if ($member === null) {
            return false;
        }

        return ContentSave::query()
            ->where('user_id', $member->id)
            ->where('performer_content_id', $content->id)
            ->exists();
    }

    /**
     * Quais destas peças o membro já salvou — UMA query (sem N+1 na vitrine).
     *
     * @param  array<int, int>  $contentIds
     * @return array<int, int>
     */
    public function savedContentIds(?User $member, array $contentIds): array
    {
        if ($member === null || $contentIds === []) {
            return [];
        }

        return ContentSave::query()
            ->where('user_id', $member->id)
            ->whereIn('performer_content_id', $contentIds)
            ->pluck('performer_content_id')
            ->all();
    }

    /**
     * A lista salva pelo membro, paginada, mais recentes primeiro.
     *
     * Só peças PRONTAS de performers NO AR (perfil não soft-deletado, conta ativa)
     * — uma performer que saiu do ar some da lista em vez de virar um card que leva
     * a 404, como nas superfícies de catálogo. O salvo em si continua guardado (se
     * ela voltar, o card volta). Ordem por `content_saves.created_at` (não existe do
     * lado da peça); colunas qualificadas porque as duas tabelas têm `id`.
     */
    public function paginateFor(User $member, int $perPage = 20): LengthAwarePaginator
    {
        return PerformerContent::query()
            ->ready()
            ->join('content_saves', 'content_saves.performer_content_id', '=', 'performer_content.id')
            ->where('content_saves.user_id', $member->id)
            ->whereHas('performerProfile', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('status', 'active')))
            ->with('performerProfile:id,user_id,stage_name,slug,avatar_path')
            ->select('performer_content.*')
            ->orderByDesc('content_saves.created_at')
            ->orderByDesc('content_saves.id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
