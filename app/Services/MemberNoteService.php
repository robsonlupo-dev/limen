<?php

namespace App\Services;

use App\Models\MemberNote;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\FanAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Notas privadas da performer sobre membros (Sprint 11) — dona única da regra.
 *
 * "Anotação sobre o Membro #0042 de sempre", e **privada**: o membro nunca vê,
 * e nenhuma superfície do lado dele a expõe (ver o cabeçalho de MemberNote).
 * Toda a assimetria — como no FavoriteService — mora aqui: este serviço não
 * tem, e não pode ganhar, um método que responda pelo lado do membro ("quem
 * anotou sobre mim"). Superfície nova pergunta a este serviço; uma segunda
 * cópia da regra divergiria, e a divergência é o vazamento.
 *
 * Nada deste fluxo entra em `audit_logs`: aquela tabela sobrevive ao Hard
 * Delete com o IP em claro, e uma trilha de notas seria a cópia permanente do
 * dossiê que o Hard Delete apaga (`DeletionService::purgeMemberNotes*`). Mesmo
 * raciocínio dos favoritos e do slug do Estilo de Vida.
 *
 * Este serviço NÃO resolve o handle: quem resolve `member_handle` → membro é o
 * Form Request (contra os seguidores/visitantes listáveis, o padrão do
 * Interesse). Aqui só se opera sobre `User`/`PerformerProfile` já resolvidos —
 * a mesma fronteira que o FavoriteService mantém com o `findBySlug` do
 * controller.
 */
class MemberNoteService
{
    /**
     * Cria ou atualiza a nota da performer sobre um membro. Uma linha por par
     * (perfil, membro) — o `updated_at` reflete a última edição.
     *
     * Sob transação com `lockForUpdate` porque a nota é salva de um modal que
     * pode disparar dois PUTs em voo (clique duplo em "Salvar"): sem serializar,
     * os dois leriam "não existe" e os dois tentariam INSERT — o UNIQUE
     * transformaria o segundo num 500 em vez de num update idempotente. O
     * `lockForUpdate` serializa os caminhos; o catch do UNIQUE é a segunda linha
     * de defesa (o gap lock do InnoDB não trava uma linha que ainda não existe),
     * e nele relemos e atualizamos — a corrida perdida termina com o conteúdo do
     * último a chegar, nunca com exceção nem linha duplicada. Mesma disciplina do
     * FavoriteService::toggle().
     */
    public function upsert(PerformerProfile $profile, User $member, string $content): MemberNote
    {
        return DB::transaction(function () use ($profile, $member, $content) {
            $note = MemberNote::query()
                ->where('performer_profile_id', $profile->id)
                ->where('user_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($note !== null) {
                $note->content = $content;
                $note->save();

                return $note;
            }

            try {
                // FKs fora do $fillable (ver MemberNote): atribuição explícita,
                // nunca um create() sobre array de request.
                $note = new MemberNote;
                $note->performer_profile_id = $profile->id;
                $note->user_id = $member->id;
                $note->content = $content;
                $note->save();

                return $note;
            } catch (UniqueConstraintViolationException) {
                // Corrida perdida: a outra requisição criou a linha primeiro.
                // Releitura e update — o resultado é o conteúdo do último a
                // chegar, que é o comportamento esperado de um "salvar".
                $note = MemberNote::query()
                    ->where('performer_profile_id', $profile->id)
                    ->where('user_id', $member->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $note->content = $content;
                $note->save();

                return $note;
            }
        });
    }

    /**
     * Apaga a nota da performer sobre um membro. Idempotente: apagar o que não
     * existe é no-op (0 linhas), nunca erro — dois DELETEs em voo não brigam.
     *
     * Escopado por (perfil, membro): uma performer só alcança as próprias notas.
     * O par (performer_profile_id, user_id) é a chave, então não há como tocar a
     * nota de outra performer nem passando o mesmo membro.
     */
    public function delete(PerformerProfile $profile, User $member): int
    {
        return MemberNote::query()
            ->where('performer_profile_id', $profile->id)
            ->where('user_id', $member->id)
            ->delete();
    }

    /**
     * Conteúdo das notas desta performer para um conjunto de membros — UMA query
     * para a página inteira do painel de seguidores (evita N+1 e N descriptografias
     * espalhadas).
     *
     * Devolve o mapa `user_id => content`. É consumido pelo controller, que o
     * traduz para o handle antes de mandar ao front — o `user_id` cru não sai
     * daqui.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, string>
     */
    public function contentByMember(PerformerProfile $profile, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return MemberNote::query()
            ->where('performer_profile_id', $profile->id)
            ->whereIn('user_id', $memberIds)
            ->get(['user_id', 'content'])
            ->mapWithKeys(fn (MemberNote $note) => [$note->user_id => (string) $note->content])
            ->all();
    }

    /**
     * A lista de notas da performer, paginada, editadas mais recentemente
     * primeiro. Traz a relação `member` só para derivar o FanAlias no
     * controller — o id cru nunca chega ao front.
     *
     * A busca da tela é client-side (o conteúdo é cifrado, então não há como
     * filtrar no SQL): a página carrega as notas e o front filtra por rótulo e
     * texto. Bounded na prática — é o conjunto de membros que ESTA performer
     * anotou.
     */
    public function paginateFor(PerformerProfile $profile, int $perPage = 30): LengthAwarePaginator
    {
        return MemberNote::query()
            ->where('performer_profile_id', $profile->id)
            ->orderByDesc('updated_at')
            // Desempate estável: notas editadas no mesmo segundo embaralhariam
            // entre páginas e uma linha apareceria duas vezes.
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Payload de uma nota para o front da performer: FanAlias no lugar do id,
     * conteúdo em claro (é a nota DELA), carimbo de edição em faixa de data.
     *
     * Ponto único de montagem para as duas telas (lista e painel), para que o
     * `user_id` só vire `handle`/`label` num lugar.
     *
     * @return array{member_handle:string,label:string,content:string,updated_at:string}
     */
    public function present(MemberNote $note): array
    {
        return [
            'member_handle' => FanAlias::handle($note->performer_profile_id, $note->user_id),
            'label' => FanAlias::label($note->performer_profile_id, $note->user_id, 'Membro #'),
            'content' => (string) $note->content,
            'updated_at' => $note->updated_at->format('d/m/Y'),
        ];
    }
}
