<?php

namespace App\Rules;

use App\Support\ChatContentFilter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Recusa um IDENTIFICADOR público (hoje: o nome artístico da performer) que
 * carregue conteúdo proibido — proteção à infância, risco legal FOSTA-SESTA ou
 * conduta abusiva. Delega ao App\Support\ChatContentFilter (mesmas listas e
 * blindagem anti-desvio do chat/apelido/slug) — não é filtro novo.
 *
 * Por que NÃO é o SafeProfileText (que o apelido/bio usam): este NÃO barra
 * contato/rede social. Um nome artístico é texto curto e criativo; aplicar o
 * filtro de contato aqui geraria falso-positivo em nome legítimo. O que não se
 * tolera em NENHUMA hipótese é o conteúdo proibido — e é só isso que o
 * ChatContentFilter::blocks cobre.
 *
 * Mensagem genérica de propósito (anti-oráculo): não revela QUE categoria
 * disparou — acima de tudo a de proteção à infância, cuja lógica de detecção
 * nunca se narra para não ensinar o desvio.
 */
class ProhibitedContentText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (ChatContentFilter::blocks($value)) {
            $fail('Este nome não pode ser usado. Por favor, escolha outro.');
        }
    }
}
