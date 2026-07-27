<?php

namespace App\Rules;

use App\Support\ChatContentFilter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Recusa texto livre de perfil que ofereça encontro mediante pagamento —
 * a categoria TIPO 1 (`legal`) do [[ChatContentFilter]].
 *
 * Por que o filtro do chat vale aqui: `bio` e `looking_for` são publicados em
 * `/performers/{slug}`, que é PÚBLICA e indexável. Uma oferta escrita ali tem
 * alcance maior que a mesma frase numa conversa privada — é a exposição
 * FOSTA-SESTA que o filtro do chat existe para evitar, só que sem destinatário
 * e com o Google lendo.
 *
 * **Só TIPO 1.** Conduta (ameaça, insulto direcionado) fica de fora de
 * propósito: no próprio perfil não há alvo. Insulto que a performer escreve
 * sobre si mesma é auto-sabotagem comercial, não vetor de ataque, e barrá-lo
 * seria a plataforma editando o tom de voz de quem paga para estar nela.
 *
 * **Não gera audit.** No chat o audit existe porque a moderação age por
 * REPETIÇÃO — sem o corpo, o admin vê "usuário X disparou a categoria 9x". Aqui
 * o texto nem chega a ser persistido: o formulário volta com o erro e a
 * performer reescreve. Registrar cada rascunho recusado encheria a trilha de
 * tentativas de boa-fé e enterraria justamente o sinal que o audit do chat
 * carrega.
 *
 * As ressalvas do filtro valem inteiras (ver o cabeçalho de
 * config/chat_filters.php): troca de contato passa, palavrão consensual passa,
 * e encontro SEM valor monetário passa. Isto não é anti-evasão — a lista está
 * no repo. Ausência de bloqueio não é prova de que nada foi combinado.
 */
class NoProhibitedOffer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        // categoryOf() e não blocks(): `blocks` casaria TIPO 2 também, e a
        // conduta é deliberadamente permitida no perfil.
        if (ChatContentFilter::categoryOf($value) !== ChatContentFilter::LEGAL) {
            return;
        }

        // Mensagem própria do contexto de perfil — a do chat fala em "mensagem"
        // e em destinatário, que aqui não existem. Específica de propósito,
        // mesma disciplina do chat: dizer o que foi violado vale mais do que
        // uma vaguidade que só prejudica quem escreveu de boa-fé.
        $fail('Este texto contém conteúdo que viola nossos Termos de Uso: não é permitido oferecer ou combinar encontros mediante pagamento. Reescreva sem essa oferta.');
    }
}
