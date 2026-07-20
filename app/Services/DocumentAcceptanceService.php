<?php

namespace App\Services;

use App\Models\DocumentAcceptance;
use App\Models\User;
use App\Support\ClientFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fonte única de "esta performer aceitou a versão vigente de tudo?".
 *
 * O middleware, a tela de aceite e o POST consultam este serviço. Se
 * divergissem, o par redirect/200 do middleware viraria oráculo do estado que a
 * tela diz o contrário — mesmo raciocínio do FollowerVisibilityService.
 */
class DocumentAcceptanceService
{
    /**
     * Documentos cuja versão vigente ainda não foi aceita por este usuário.
     *
     * @return list<string> subconjunto de DocumentAcceptance::REQUIRED
     */
    public function pendingFor(User $user): array
    {
        // Pares type|version, não `pluck` chaveado por type: depois de um bump a
        // performer tem VÁRIAS linhas do mesmo tipo (o histórico), e um pluck
        // chaveado colapsaria para uma delas — qual, depende da ordem que o
        // banco devolveu. Com uma versão que não ordene junto com o id
        // ("2026-07-20b", "v2"), o serviço leria a versão velha, o middleware
        // bloquearia e o POST viraria no-op pelo unique: loop sem saída.
        // A pergunta certa é de presença, não de "a mais recente".
        $accepted = $user->documentAcceptances()
            ->whereIn('document_type', DocumentAcceptance::REQUIRED)
            ->get(['document_type', 'document_version'])
            ->map(fn (DocumentAcceptance $row) => $row->document_type.'|'.$row->document_version)
            ->all();

        return array_values(array_filter(
            DocumentAcceptance::REQUIRED,
            fn (string $type) => ! in_array(
                $type.'|'.DocumentAcceptance::currentVersion($type),
                $accepted,
                true,
            ),
        ));
    }

    public function hasAcceptedAll(User $user): bool
    {
        return $this->pendingFor($user) === [];
    }

    /**
     * Grava o aceite da versão vigente de todos os documentos exigidos.
     *
     * Tudo ou nada, numa transação: aceite pela metade deixaria a performer
     * presa no middleware com uma linha de evidência já gravada — pior que não
     * ter gravado nada, porque o histórico passaria a mostrar um aceite que a
     * plataforma não honrou.
     *
     * `firstOrCreate` sobre o unique (user, type, version): re-submeter é no-op,
     * e o accepted_at preservado é o do PRIMEIRO aceite daquela versão — é essa
     * a data com valor jurídico, não a do último clique.
     *
     * @return list<DocumentAcceptance>
     */
    public function acceptAll(User $user, Request $request): array
    {
        $fingerprint = ClientFingerprint::of($request);

        return DB::transaction(function () use ($user, $fingerprint) {
            return array_map(
                fn (string $type) => DocumentAcceptance::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'document_type' => $type,
                        'document_version' => DocumentAcceptance::currentVersion($type),
                    ],
                    ['accepted_at' => now()] + $fingerprint,
                ),
                DocumentAcceptance::REQUIRED,
            );
        });
    }
}
