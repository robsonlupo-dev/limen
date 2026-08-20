<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Gancho ("teaser") da mensagem que o membro ainda NÃO pagou para ler
 * (feat/message-preview-teaser). Mostra as primeiras palavras em claro e sinaliza
 * que há mais — bloqueio total converte menos, o teaser cria curiosidade sem
 * abrir mão da monetização (o gate de chat M.13.1 segue igual).
 *
 * DONA ÚNICA do corte, e o corte é SERVER-SIDE por design: a regra crítica é que
 * o membro nunca recebe o corpo completo no payload antes de pagar. Mandar o corpo
 * inteiro e borrar via CSS deixaria o texto legível no DevTools — por isso é o
 * BACKEND que corta e só o trecho trafega. Consumida por ChatController::index (o
 * preview da lista), ChatController::show (o gancho no banner do paywall) e
 * ChatService::broadcastListUpdate (o preview do broadcast → lista em tempo real +
 * toast). Regra nova de teaser entra aqui, nunca copiada numa das superfícies.
 *
 * Piso de segurança: o teaser NUNCA revela a mensagem inteira. Em mensagem curta
 * mostra no máximo METADE das palavras (piso), independente do número configurado
 * — uma mensagem de 3 palavras mostra 1, uma de 2 mostra 1, uma de 1 mostra só um
 * pedaço da única palavra. Assim `config('message_teaser.words')` é um TETO, e
 * afrouxá-lo nunca faz o gancho vazar tudo.
 */
class MessageTeaser
{
    /** Reticências que sinalizam "tem mais — desbloqueie para ler". */
    public const ELLIPSIS = '…';

    /**
     * Trecho de abertura do corpo, cortado no servidor. `null` quando não há o que
     * mostrar (corpo vazio/só espaços, ou mensagem inexistente) — o chamador cai no
     * cadeado sem gancho.
     */
    public static function for(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $total = count($words);

        if ($total === 0) {
            return null;
        }

        $wordLimit = max(1, (int) config('message_teaser.words', 8));
        $charLimit = max(1, (int) config('message_teaser.chars', 40));

        // Piso de segurança: NUNCA a mensagem inteira. Em mensagem curta corta na
        // METADE das palavras (2 mostram 1, 4 mostram 2), independentemente do teto.
        $wordCap = min($wordLimit, intdiv($total, 2));

        if ($wordCap < 1) {
            // 1 palavra (metade = 0): a palavra inteira vazaria tudo — só um PEDAÇO
            // dela (metade dos caracteres) + reticências.
            return self::partialSingleWord($words[0]);
        }

        // Acumula palavra a palavra até o que vier PRIMEIRO: o teto de palavras (já
        // no wordCap) ou o de caracteres. O corte é SERVER-SIDE — só este trecho
        // trafega; o corpo completo nunca vai ao cliente não-pago.
        $taken = [];
        $length = 0;
        foreach (array_slice($words, 0, $wordCap) as $word) {
            $addition = ($taken === [] ? 0 : 1) + Str::length($word); // +1 do espaço
            if ($taken !== [] && $length + $addition > $charLimit) {
                break; // o teto de caracteres veio primeiro
            }
            $taken[] = $word;
            $length += $addition;
        }

        // A 1ª palavra sozinha já estoura o teto de caracteres: mostra ao menos ela
        // (está dentro do wordCap, então não é a mensagem inteira).
        if ($taken === []) {
            $taken[] = $words[0];
        }

        return implode(' ', $taken).self::ELLIPSIS;
    }

    /**
     * Pedaço de uma única palavra (mensagem de 1 palavra): metade dos caracteres,
     * para não entregar a palavra completa. Palavra de 1 caractere vira só as
     * reticências (nada a revelar sem vazar tudo).
     */
    private static function partialSingleWord(string $word): string
    {
        $half = intdiv(Str::length($word), 2);

        if ($half < 1) {
            return self::ELLIPSIS;
        }

        return Str::substr($word, 0, $half).self::ELLIPSIS;
    }
}
