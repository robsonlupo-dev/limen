<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificação server-side do token do hCaptcha.
 *
 * Não há pacote instalado: o composer do projeto não alcança o packagist neste
 * ambiente, e o contrato do hCaptcha é um POST de formulário com dois campos.
 * O padrão aqui é o mesmo do DiditKycClient e do AsaasHttpClient — `Http::` com
 * timeout curto e um método só —, o que também deixa o teste usar `Http::fake()`
 * em vez de um SDK de terceiro.
 *
 * A regra de validação (App\Rules\HCaptchaValid) é a única chamadora.
 */
class HCaptchaVerifier
{
    /**
     * O token é válido?
     *
     * Três respostas possíveis, e a do meio é a que exige atenção:
     *
     *  - hCaptcha diz `success: true`  → true;
     *  - hCaptcha diz `success: false` → false (token forjado, expirado ou já
     *    usado — o token do hCaptcha é de uso ÚNICO, então um resubmit sem
     *    resetar o widget cai aqui);
     *  - hCaptcha não respondeu (rede, timeout, 5xx) → **true**, com log.
     *
     * O último é fail-OPEN deliberado, e é a mesma escolha do GeoBlock: uma
     * indisponibilidade do provedor não pode trancar login e cadastro da
     * plataforma inteira. O captcha existe para encarecer bot, não para ser
     * ponto único de falha da autenticação. Ver config/hcaptcha.php, item 3.
     */
    public function verify(?string $token): bool
    {
        // Desligado: ninguém fala com hcaptcha.com. Redundante com a regra, que
        // nem chega aqui, mas é a garantia de que um chamador futuro não
        // consegue disparar requisição a terceiro com a feature desligada.
        if (! config('hcaptcha.enabled')) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('hcaptcha.timeout', 5))
                ->post((string) config('hcaptcha.verify_url'), [
                    'secret' => (string) config('hcaptcha.secret'),
                    'response' => $token,
                    // `remoteip` é OPCIONAL no siteverify e fica de fora de
                    // propósito. O browser já entregou o IP ao hCaptcha ao
                    // carregar o widget; mandá-lo de novo daqui seria a Limen
                    // TRANSMITINDO ativamente o IP do titular a um terceiro,
                    // que é exatamente o que a ressalva de subprocessador pesa.
                    // Ligar isto é decisão de produto, não ajuste técnico.
                ]);
        } catch (Throwable $e) {
            // Sem corpo e sem token no log: o token é credencial de uso único e
            // a exceção do Guzzle pode carregar a URL com o segredo em anexo.
            Log::warning('hcaptcha verify unreachable', ['error' => $e::class]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('hcaptcha verify failed', ['status' => $response->status()]);

            return true;
        }

        return $response->json('success') === true;
    }
}
