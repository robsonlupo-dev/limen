<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base dos provedores que verificam por um POST de siteverify — hoje hCaptcha e
 * Turnstile, cujos contratos server-side são IDÊNTICOS: um POST de formulário
 * com `secret` + `response`, e a resposta traz `success: bool`. A lógica de
 * rede, o fail-open e a disciplina de log vivem AQUI, uma vez só; cada driver
 * concreto só declara seu `provider()` (a chave em config/captcha.php).
 *
 * Não há pacote instalado: o composer do projeto não alcança o packagist neste
 * ambiente, e o contrato é simples. O padrão é o mesmo do DiditKycClient e do
 * AsaasHttpClient — `Http::` com timeout curto —, o que também deixa o teste
 * usar `Http::fake()` em vez de um SDK de terceiro.
 */
abstract class RemoteCaptchaDriver implements CaptchaDriver
{
    /** Chave deste provedor em config('captcha.providers.*'). */
    abstract protected function provider(): string;

    protected function config(string $key): ?string
    {
        $value = config("captcha.providers.{$this->provider()}.{$key}");

        return $value === null ? null : (string) $value;
    }

    public function enabled(): bool
    {
        return true;
    }

    /**
     * @return array{provider: string, sitekey: string|null}
     */
    public function frontend(): array
    {
        // Só a chave PÚBLICA. O segredo NUNCA aparece aqui — este array vai
        // literalmente para as props do Inertia (HandleInertiaRequests).
        return [
            'provider' => $this->provider(),
            'sitekey' => $this->config('sitekey'),
        ];
    }

    public function verify(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('captcha.timeout', 5))
                ->post((string) $this->config('verify_url'), [
                    'secret' => (string) $this->config('secret'),
                    'response' => $token,
                    // `remoteip` é OPCIONAL no siteverify (dos dois provedores) e
                    // fica de fora de propósito. O browser já entregou o IP ao
                    // provedor ao carregar o widget; mandá-lo de novo daqui seria
                    // a Limen TRANSMITINDO ativamente o IP do titular a um
                    // terceiro, que é o peso exato da ressalva de subprocessador.
                    // Ligar isto é decisão de produto, não ajuste técnico.
                ]);
        } catch (Throwable $e) {
            // Sem corpo e sem token no log: o token é credencial de uso único e
            // a exceção do Guzzle pode carregar a URL com o segredo em anexo.
            Log::warning('captcha verify unreachable', [
                'provider' => $this->provider(),
                'error' => $e::class,
            ]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('captcha verify failed', [
                'provider' => $this->provider(),
                'status' => $response->status(),
            ]);

            return true;
        }

        return $response->json('success') === true;
    }
}
