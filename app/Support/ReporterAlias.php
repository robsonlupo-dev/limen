<?php

namespace App\Support;

/**
 * Pseudônimo do denunciante no painel de moderação.
 *
 * Moderar uma denúncia não exige saber quem a abriu — exige saber o que foi
 * denunciado. Mostrar o user_id cru na fila entregaria a identidade de quem
 * denunciou a todo admin (e a todo print/ombro sobre a tela), o que é
 * exatamente o risco que faz alguém não denunciar coerção.
 *
 * Estável de propósito: "este mesmo denunciante abriu 12 denúncias hoje" é
 * sinal que a moderação precisa ler para separar denúncia de retaliação em
 * massa. HMAC sobre a APP_KEY, então o alias não volta a ser id.
 *
 * O reporter_id continua na tabela — é o que responde a ordem judicial. Isto
 * é camada de apresentação, não anonimização real.
 */
final class ReporterAlias
{
    /** Rótulo pronto: "Denunciante #a3f9c1d2". */
    public static function label(int $reporterId): string
    {
        return 'Denunciante #'.self::handle($reporterId);
    }

    public static function handle(int $reporterId): string
    {
        return substr(
            hash_hmac('sha256', "reporter_alias:v1:{$reporterId}", (string) config('app.key')),
            0,
            16,
        );
    }
}
