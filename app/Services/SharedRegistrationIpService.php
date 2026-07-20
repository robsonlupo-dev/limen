<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Detecta contas de PERFORMER que se cadastraram do mesmo IP.
 *
 * Os advogados levantaram o risco de rede de exploração: alguém cadastrando
 * várias pessoas sob coerção deixaria esse rastro. O sinal é **fraco sozinho** —
 * NAT de operadora, universidade, coworking, lan house e casal no mesmo Wi-Fi
 * compartilham IP legitimamente, e IPv4 residencial no Brasil é rotativo (duas
 * performers sem relação nenhuma podem pegar o mesmo IP em semanas diferentes).
 *
 * Por isso: SINALIZA, nunca bloqueia. A decisão é humana, e a ausência de flag
 * também não prova nada — quem coage sabe usar 4G e trocar de rede.
 */
class SharedRegistrationIpService
{
    /**
     * Quantas OUTRAS performers compartilham o IP de cadastro de cada usuário.
     *
     * @param  Collection<int, User>  $users  performers já carregadas (a página
     *                                        atual do painel, não a base toda)
     * @return array<int, int> user_id => nº de outras contas, só para quem tem
     *                         pelo menos uma. Ausente = sem colisão.
     */
    public function othersCountFor(Collection $users): array
    {
        $hashes = $users
            ->where('role', 'performer')
            ->pluck('registration_ip_hash')
            ->filter()
            ->unique()
            ->all();

        if ($hashes === []) {
            return [];
        }

        // Uma query para a página inteira, não uma por linha. O total conta a
        // base toda, não só a página: duas contas do mesmo IP em páginas
        // diferentes do painel continuam sendo duas contas do mesmo IP.
        $totals = User::query()
            // `withTrashed` é o ponto todo: conta banida ou excluída CONTINUA
            // contando. Churn de contas é comportamento típico de quem opera
            // rede de coerção — sem isso, apagar a segunda conta apaga o flag da
            // primeira, e o caso em que o sinal mais importa é justamente o que
            // o desligaria.
            ->withTrashed()
            ->whereIn('registration_ip_hash', $hashes)
            ->where('role', 'performer')
            ->groupBy('registration_ip_hash')
            ->selectRaw('registration_ip_hash, count(*) as total')
            ->pluck('total', 'registration_ip_hash');

        $counts = [];

        foreach ($users as $user) {
            if ($user->role !== 'performer' || ! $user->registration_ip_hash) {
                continue;
            }

            // -1: o total inclui a própria conta, e o painel diz "compartilhado
            // com N OUTRAS". Sem o desconto, uma performer sozinha no IP
            // apareceria como "compartilhado com 1".
            $others = (int) ($totals[$user->registration_ip_hash] ?? 1) - 1;

            if ($others > 0) {
                $counts[$user->id] = $others;
            }
        }

        return $counts;
    }
}
