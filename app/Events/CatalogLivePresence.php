<?php

namespace App\Events;

use App\Models\PerformerProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Presença de live no CATÁLOGO (Pacote 2 do UAT Fase 9): acende/apaga o badge
 * "AO VIVO" e o círculo da trilha "Agora" SEM F5. Um evento por TRANSIÇÃO
 * (start, stop/ban e a reconciliação de sala morta do LiveSessionService), num
 * canal POR MUNDO (`catalog.{world}`) — o perfil pode viver em mais de um mundo
 * (coluna `worlds` JSON, fallback legado `category`), então broadcastOn devolve
 * um canal por mundo VÁLIDO do perfil (interseção com WORLDS, fail-closed; sem
 * mundo válido → sem canal → sem broadcast). O volume é o de começos/fins de
 * live — irrisório para o Reverb do servidor (2 vCPU).
 *
 * Payload = EXATAMENTE o item da trilha "Agora" que o CatalogController serve
 * (slug, stage_name, avatar_url ASSINADA por profile_id — nunca user_id) +
 * `live`. Nada do membro e nada da sessão LiveKit (room_name NUNCA sai).
 *
 * ShouldDispatchAfterCommit: os dispatches acontecem DENTRO das transações do
 * LiveSessionService — só transmite se o commit confirmar o estado novo.
 */
class CatalogLivePresence implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $worlds  mundos VÁLIDOS do perfil (já saneados)
     * @param  ?int  $avatarProfileId  id do perfil quando há avatar; null sem foto
     */
    public function __construct(
        public array $worlds,
        public string $slug,
        public string $stageName,
        public ?int $avatarProfileId,
        public bool $live,
    ) {}

    public static function fromProfile(PerformerProfile $profile, bool $live): self
    {
        // Mesmo fallback do scopeInWorld: `worlds` JSON ou o `category` legado.
        $worlds = $profile->worlds ?: ($profile->category ? [$profile->category] : []);

        return new self(
            worlds: array_values(array_intersect($worlds, PerformerProfile::WORLDS)),
            slug: $profile->slug,
            stageName: $profile->stage_name,
            avatarProfileId: $profile->avatar_path ? $profile->id : null,
            live: $live,
        );
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $world) => new PrivateChannel('catalog.'.$world),
            $this->worlds,
        );
    }

    public function broadcastAs(): string
    {
        return 'catalog.live';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'slug' => $this->slug,
            'stage_name' => $this->stageName,
            // Assinada NA HORA do broadcast, mesmo formato/validade (60 min) do
            // item da trilha no CatalogController.
            'avatar_url' => $this->avatarProfileId !== null
                ? URL::temporarySignedRoute('performer.media', now()->addMinutes(60), [
                    'profile_id' => $this->avatarProfileId,
                    'type' => 'avatar',
                ])
                : null,
            'live' => $this->live,
        ];
    }
}
