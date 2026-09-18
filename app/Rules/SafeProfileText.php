<?php

namespace App\Rules;

use App\Support\ProfileTextGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Recusa a bio "Sobre mim" do membro (feat/member-profile-v2) quando ela carrega
 * contato (telefone/e-mail/@/URL/rede social) ou conduta abusiva. Delega ao
 * App\Support\ProfileTextGuard, que reusa ChatContentFilter + a config do apelido
 * — não é filtro novo.
 *
 * Por que MAIS rígida que o `looking_for`/`seeking` (NoProhibitedOffer, só TIPO
 * 1): a bio do membro é campo PÚBLICO e PERMANENTE que a performer lê, então cai
 * na mesma classe do APELIDO (barra troca de contato) e não na do texto de
 * afinidade privado. O que fica de fora — unicidade, personificação, cooldown —
 * é o que só faz sentido para um IDENTIFICADOR, não para um parágrafo.
 *
 * Mensagem específica por tipo (contato × conduta), anti-oráculo sem ser vaga:
 * como o apelido, dizer o que violou vale mais do que uma vaguidade que só
 * atrapalha quem escreveu de boa-fé.
 */
class SafeProfileText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $violation = ProfileTextGuard::violation($value);

        if ($violation === 'contact') {
            $fail('Sua bio não pode conter telefone, e-mail, link ou rede social. Reescreva sem esses dados.');

            return;
        }

        if ($violation === 'conduct') {
            $fail('Sua bio contém conteúdo que viola nossos Termos de Uso. Reescreva em outro tom.');
        }
    }
}
