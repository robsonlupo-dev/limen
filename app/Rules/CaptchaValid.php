<?php

namespace App\Rules;

use App\Services\Captcha\CaptchaManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O token do captcha confere?
 *
 * Dona única do contrato do captcha nas portas que o usam: login web, cadastro
 * web, `POST /api/v1/auth/login`, os dois `register` da API e o pedido de OTP.
 * Cada Form Request só chama `rules()` e `messages()` daqui — sem isso a lista
 * de regras seria copiada e a próxima porta nasceria sem captcha, que é a lição
 * que o CLAUDE.md tira do `documents.accepted` ("gate que fecha uma porta só não
 * é gate"). Qual PROVEDOR responde (hCaptcha/Turnstile/nenhum) é decisão do
 * CaptchaManager; esta regra não conhece o driver concreto.
 *
 * Por que `rules()` estático em vez de só `new CaptchaValid` na lista: com o
 * captcha DESLIGADO o campo não pode ser exigido, e com ele LIGADO o campo
 * ausente precisa falhar. Um objeto de regra sozinho não resolve os dois — o
 * validator do Laravel pula regras não-implícitas quando o campo não veio, e o
 * token ausente passaria batido. Montar a lista aqui resolve sem depender de
 * regra implícita: desligado vira `nullable`, ligado vira `required` + esta
 * verificação.
 */
class CaptchaValid implements ValidationRule
{
    /**
     * Nome do campo que carrega o token no formulário. Neutro por provedor — o
     * front captura o token pelo callback do widget e o envia aqui, seja
     * hCaptcha ou Turnstile. Fonte do valor: config('captcha.field').
     */
    public const FIELD = 'captcha_token';

    /**
     * Mensagem única para os dois modos de falha (ausente e recusado).
     *
     * Deliberadamente a mesma: distinguir "você não resolveu o desafio" de "seu
     * token foi recusado" não ajuda quem agiu de boa-fé — o caminho é o mesmo,
     * resolver de novo — e só serviria para quem está sondando o comportamento
     * do captcha. Mesma disciplina da mensagem do filtro de chat.
     */
    public const MESSAGE = 'Verificação de segurança falhou. Tente novamente.';

    /**
     * As regras do campo, conforme haja provedor ativo ou não.
     *
     * DESLIGADO (`captcha.provider=none`) devolve `nullable` e mais nada: o
     * campo vira opcional e ignorado, então nenhum teste e nenhum cliente antigo
     * precisa mandá-lo. É o que mantém dev, teste e CI funcionando sem tocar no
     * phpunit.xml.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        if (! app(CaptchaManager::class)->enabled()) {
            return ['nullable'];
        }

        return ['required', 'string', new self];
    }

    /**
     * Mensagens do campo, para o Form Request mesclar nas suas.
     *
     * O `required` também precisa da mensagem: sem ela o Laravel geraria "The
     * captcha token field is required", que expõe o nome interno do campo.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            self::FIELD.'.required' => self::MESSAGE,
            self::FIELD.'.string' => self::MESSAGE,
        ];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(CaptchaManager::class)->verify(is_string($value) ? $value : null)) {
            $fail(self::MESSAGE);
        }
    }
}
