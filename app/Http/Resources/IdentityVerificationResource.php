<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'age_confirmed' => $this->age_confirmed,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'performer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'stage_name' => $this->user->performerProfile?->stage_name,
            ]),
            // Possível rede de exploração: outras contas de performer que se
            // cadastraram do mesmo IP. O contador é anexado pelo controller
            // (AdminKycController::index) — o resource não consulta banco.
            //
            // O hash NUNCA sai daqui: exposto, viraria oráculo de "esta conta
            // veio do mesmo IP que aquela" para quem tivesse o JSON, e o
            // espaço de IPv4 é pequeno o bastante para reverter o digest por
            // varredura com a APP_KEY em mãos. O admin precisa do SINAL, não
            // do identificador.
            'shared_registration_ip' => $this->whenLoaded('user', function () {
                $others = (int) ($this->user->shared_registration_ip_others ?? 0);

                return [
                    'flagged' => $others > 0,
                    'others_count' => $others,
                    'label' => $others > 0
                        ? "IP de cadastro compartilhado com {$others} outra".($others > 1 ? 's' : '').' performer'.($others > 1 ? 's' : '')
                        : null,
                ];
            }),
        ];
    }
}
