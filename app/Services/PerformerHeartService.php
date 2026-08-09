<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PerformerHeart;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * CORAÇÃO — o "interesse grátis" do catálogo de membros (home da performer).
 *
 * Dona única da regra. Direção performer → membro: a performer curte um membro
 * que ela vê no catálogo; o membro vê quem curtiu na lista "Performers
 * interessadas em você", COM a identidade da performer (ela não é anônima) e SEM
 * pagar nada. É o oposto do Interesse Controlado (pago, anônimo) — por isso vive
 * em `performer_hearts`, fora da máquina de opt-out/desbloqueio do InterestService.
 *
 * Grátis, ilimitado, sem cooldown: o único freio é o throttle da rota. O que
 * protege a privacidade do MEMBRO não é uma cota aqui — é o próprio catálogo, que
 * já só expõe membros que optaram por aparecer (MemberCatalogService); a performer
 * só pode curtir quem ela já podia ver.
 */
class PerformerHeartService
{
    /**
     * A performer curte um membro. Idempotente: recurtir devolve a linha
     * existente sem gravar de novo (UNIQUE do par + captura da corrida). Retorna
     * também se a linha foi criada AGORA, para o audit não registrar recurtidas.
     *
     * @return array{heart: PerformerHeart, created: bool}
     */
    public function heart(PerformerProfile $performerProfile, User $member): array
    {
        // firstOrCreate resolve o caso comum; o catch fecha a corrida de dois
        // cliques simultâneos (o UNIQUE do par garante uma linha só). Mesma
        // disciplina do toggle de Favorito e do upsert de MemberNote.
        try {
            $heart = PerformerHeart::firstOrCreate([
                'performer_profile_id' => $performerProfile->id,
                'member_id' => $member->id,
            ]);

            $created = $heart->wasRecentlyCreated;
        } catch (UniqueConstraintViolationException) {
            $heart = PerformerHeart::where('performer_profile_id', $performerProfile->id)
                ->where('member_id', $member->id)
                ->firstOrFail();

            $created = false;
        }

        if ($created) {
            AuditLog::create([
                'user_id' => $performerProfile->user_id,
                'action' => 'heart.sent',
                'subject_type' => PerformerHeart::class,
                'subject_id' => $heart->id,
                'ip' => request()->ip(),
                // member_id (chave interna, como interest.sent) — nunca o FanAlias,
                // que é apresentação. O audit é interno e sobrevive ao Hard Delete.
                'metadata' => ['member_id' => $member->id],
            ]);
        }

        return ['heart' => $heart, 'created' => $created];
    }

    /**
     * Já existe coração desta performer para este membro? Consumido pelo catálogo
     * para o card já vir com o coração preenchido (uma query para a página inteira
     * — ver ids()).
     */
    public function hasHearted(PerformerProfile $performerProfile, User $member): bool
    {
        return PerformerHeart::where('performer_profile_id', $performerProfile->id)
            ->where('member_id', $member->id)
            ->exists();
    }

    /**
     * Dos membros dados, quais esta performer já curtiu. Uma query para a página
     * inteira do catálogo (não N).
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, int>
     */
    public function heartedMemberIds(PerformerProfile $performerProfile, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return PerformerHeart::where('performer_profile_id', $performerProfile->id)
            ->whereIn('member_id', $memberIds)
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Lista "Performers interessadas em você" do MEMBRO. A performer NÃO é anônima
     * aqui — é o produto do coração: o membro vê nome artístico, slug e avatar
     * (identidade pública do catálogo, nada de PII). Só performers de pé (perfil
     * verificado, ativo, não encerrado): um coração de conta suspensa/em KYC não
     * aparece.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listForMember(User $member): Collection
    {
        return PerformerHeart::query()
            ->where('member_id', $member->id)
            ->whereHas('performerProfile', fn ($q) => $q
                ->where('is_verified', true)
                ->whereHas('user', fn ($u) => $u->where('status', 'active')))
            ->with('performerProfile:id,stage_name,slug,avatar_path')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (PerformerHeart $heart) {
                $profile = $heart->performerProfile;

                return [
                    'stage_name' => $profile->stage_name,
                    'slug' => $profile->slug,
                    // Avatar por rota assinada e expirável, chaveada pelo id do
                    // perfil (nunca user_id) — mesma regra do InterestController e
                    // do PerformerPublicResource.
                    'avatar_url' => $profile->avatar_path
                        ? URL::temporarySignedRoute(
                            'performer.media',
                            now()->addMinutes(60),
                            ['profile_id' => $profile->id, 'type' => 'avatar'],
                        )
                        : null,
                    // Faixa, nunca relógio — a mesma disciplina de horário do resto
                    // do projeto. Só a data quando fora do dia corrente.
                    'hearted_slot' => $heart->created_at->isToday()
                        ? 'Hoje'
                        : $heart->created_at->format('d/m/Y'),
                ];
            });
    }
}
