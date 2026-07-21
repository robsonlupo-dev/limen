<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Segundo fator (TOTP, RFC 6238) das performers.
 *
 * Por que performer e não todo mundo: a conta da performer guarda o KYC
 * (documento + selfie) e é a identidade verificada sob a qual o conteúdo é
 * publicado. Um take-over ali vaza PII sensível E deixa um terceiro publicar
 * como se fosse ela — os dois piores resultados do produto, e nenhum dos dois
 * é reversível por suporte.
 *
 * A regra tem uma dona só (mesma disciplina do DiscreteModeService): as duas
 * portas de auth e o middleware perguntam a ESTE service se o 2FA está ligado
 * e se um código serve. Nenhum controller compara código na mão.
 */
class TwoFactorService
{
    /**
     * Marca de sessão que diz "esta sessão já apresentou o segundo fator".
     * Vive na sessão e não no banco de propósito: o fator é da SESSÃO, não da
     * conta — logar de outro dispositivo tem que desafiar de novo.
     */
    public const SESSION_KEY = '2fa_verified';

    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Janela de tolerância, em passos de 30s, para cada lado do relógio.
     * 1 = aceita o código anterior e o seguinte (±30s), cobrindo o relógio
     * torto do celular. Aumentar isto multiplica diretamente a chance de acerto
     * de um chute — 2 já dobra a superfície de brute force por tentativa.
     */
    private const WINDOW = 1;

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
        $this->engine->setWindow(self::WINDOW);
    }

    /**
     * Marca a sessão como tendo apresentado o fator, trocando o id antes.
     *
     * O regenerate é o que impede fixação em cima do segundo fator: sem ele, um
     * id de sessão que o atacante tivesse plantado ANTES do desafio (ele sabe a
     * senha; o que falta é o fator) sairia daqui já carimbado como verificado.
     * Trocar o id na elevação de privilégio invalida o que ele tem.
     */
    public function markSessionVerified(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $user->getKey());
    }

    /**
     * A marca guarda o ID DO USUÁRIO, não `true`.
     *
     * Assim ela não é transferível: uma sessão que trocou de dono sem passar
     * por logout (qualquer caminho de login futuro que esqueça de limpar a
     * marca) não herda o fator de quem estava antes. É imunidade por
     * construção, em vez de depender de lembrar do forget em cada controller.
     */
    public function sessionHasFactor(Request $request, ?User $user): bool
    {
        return $user !== null
            && $request->hasSession()
            && $request->session()->get(self::SESSION_KEY) === $user->getKey();
    }

    /** 2FA ligado = confirmado. Ter secret gravado não basta — ver a migration. */
    public function isEnabled(?User $user): bool
    {
        return $user?->two_factor_confirmed_at !== null;
    }

    /** Segredo gerado, autenticador ainda não provado. Estado do meio do setup. */
    public function isPending(?User $user): bool
    {
        return $user !== null
            && $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at === null;
    }

    /**
     * Gera segredo + recovery codes e devolve o material de cadastro.
     *
     * NÃO confirma: quem confirma é confirm(), depois que a performer prova que
     * o autenticador dela gera código válido.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string, recovery_codes: array<int, string>}
     */
    public function enable(User $user): array
    {
        // Fail-closed contra o reset silencioso: com o 2FA já confirmado, um
        // POST em /enable regeneraria o segredo e devolveria QR + recovery
        // codes novos SEM apresentar nenhum fator. Quem tivesse roubado a
        // sessão trocaria o segundo fator por um seu — o gate viraria enfeite.
        // Trocar o autenticador exige desligar (com código) e ligar de novo.
        if ($this->isEnabled($user)) {
            throw new LogicException('2FA já está ativo para este usuário; desative antes de gerar um novo segredo.');
        }

        $secret = $this->engine->generateSecretKey(32);
        $codes = $this->generateRecoveryCodes();

        // Atribuição explícita: as colunas de 2FA não são mass-assignable.
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = $codes;
        $user->two_factor_confirmed_at = null;
        // Zera o contador de replay: o timestep do segredo ANTERIOR não diz
        // nada sobre o novo, e herdá-lo recusaria os primeiros códigos válidos
        // do autenticador recém-configurado.
        $user->two_factor_last_used_ts = null;
        $user->save();

        // Sem o segredo e sem os códigos no metadata — o audit_logs não é lugar
        // de material de autenticação (a mesma razão pela qual eles são
        // cifrados na users).
        Audit::log('performer.2fa_enrollment_started', $user);

        $uri = $this->engine->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->qrSvg($uri),
            'recovery_codes' => $codes,
        ];
    }

    /**
     * Fecha o cadastro. Aceita SÓ TOTP — recovery code não serve aqui: o ponto
     * do passo é provar que o app autenticador foi configurado, e um recovery
     * code (que a própria tela acabou de mostrar) não prova nada disso. Deixar
     * confirmar por ele ligaria o 2FA de alguém sem autenticador nenhum.
     */
    public function confirm(User $user, string $code): bool
    {
        if (! $this->isPending($user) || ! $this->verifyTotp($user, $code)) {
            return false;
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        Audit::log('performer.2fa_enabled', $user);

        return true;
    }

    /**
     * Desliga o 2FA, exigindo um fator válido (TOTP ou recovery code) antes.
     *
     * Sem essa exigência, sequestrar a sessão bastaria para remover o segundo
     * fator e deixar a conta valendo só a senha — que é a hipótese contra a
     * qual o 2FA existe.
     */
    public function disable(User $user, string $code): bool
    {
        if (! $this->isEnabled($user) || ! $this->verify($user, $code)) {
            return false;
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_used_ts = null;
        $user->save();

        Audit::log('performer.2fa_disabled', $user);

        return true;
    }

    /**
     * Valida um fator: TOTP ou recovery code. O recovery code é CONSUMIDO —
     * uso único, some da lista.
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /** Quantos recovery codes ainda restam (a tela mostra o número, não a lista). */
    public function remainingRecoveryCodes(?User $user): int
    {
        return count($user?->two_factor_recovery_codes ?? []);
    }

    /**
     * Emite um lote novo de recovery codes, invalidando o anterior. Exige um
     * fator válido pelo mesmo motivo de disable(): quem só tem a sessão não
     * pode sair daqui com 8 bypasses permanentes no bolso.
     *
     * Existe porque os códigos são de uso único e são 8: sem reemissão, a
     * performer que os gastasse ficaria sem rede de segurança para a perda do
     * celular, e a única saída seria o suporte reabrir a conta na mão — que é
     * exatamente o canal de engenharia social que o 2FA deveria fechar.
     *
     * @return array<int, string>|null os códigos novos, ou null se o fator não bater
     */
    public function regenerateRecoveryCodes(User $user, string $code): ?array
    {
        if (! $this->isEnabled($user) || ! $this->verify($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes;
        $user->save();

        Audit::log('performer.2fa_recovery_codes_regenerated', $user);

        return $codes;
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    /**
     * O usuário digita o código com espaço no meio ("123 456") o tempo todo —
     * o app autenticador mostra assim. Normalizar aqui evita que a UI tenha que
     * lembrar disso em cada campo.
     */
    private function verifyTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // verifyKeyNewer e não verifyKey: o timestep aceito tem que ser MAIOR
        // que o último consumido, o que faz o código valer uma vez só
        // (RFC 6238 §5.2). Com verifyKey, o mesmo código servia pelos ~90s da
        // janela — e servia em rotas diferentes: quem capturasse o código
        // usado no desafio o reapresentava em /2fa/disable e desligava o fator.
        //
        // Sob lock, e relendo dentro da transação, pelo mesmo motivo do
        // consumo de recovery code: dois POSTs simultâneos com o MESMO código
        // liam o mesmo `last_used_ts` e os dois passavam, desfazendo o uso
        // único que a troca de método acabou de comprar.
        return DB::transaction(function () use ($user, $code) {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->two_factor_secret === null) {
                return false;
            }

            // `?? 0` e não `null`: com null a lib devolve `true` em vez do
            // timestep (Google2FA::findValidOTP), e aí não haveria o que
            // gravar — o uso único morreria no primeiro código. O 0 não custa
            // varredura: makeStartingTimestamp usa max(agora - janela, old+1),
            // então o laço continua limitado à janela.
            $timestamp = $this->engine->verifyKeyNewer(
                $locked->two_factor_secret,
                $code,
                $locked->two_factor_last_used_ts ?? 0,
            );

            // A lib devolve false na recusa e o timestep (int) no acerto.
            // Comparação estrita: um timestep 0 é falsy e passaria batido.
            if ($timestamp === false) {
                return false;
            }

            $locked->two_factor_last_used_ts = $timestamp;
            $locked->save();

            $user->refresh();

            return true;
        });
    }

    /**
     * Consome um recovery code sob lock de linha.
     *
     * O lock é o que faz o "uso único" valer: sem ele, dois POSTs simultâneos
     * com o MESMO código leem a mesma lista, os dois acham a entrada e os dois
     * passam — o código de uso único autenticaria duas sessões. É o mesmo
     * padrão de leitura-modificação-escrita que o ledger evita por append-only;
     * aqui, como a lista é uma coluna, o lock resolve.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $consumed = DB::transaction(function () use ($user, $code) {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            $codes = $locked->two_factor_recovery_codes ?? [];

            $remaining = [];
            $matched = false;
            foreach ($codes as $stored) {
                // hash_equals e não ===: a comparação é entre um segredo e um
                // valor controlado pelo cliente.
                if (! $matched && hash_equals((string) $stored, $code)) {
                    $matched = true;

                    continue;
                }
                $remaining[] = $stored;
            }

            if (! $matched) {
                return false;
            }

            $locked->two_factor_recovery_codes = array_values($remaining);
            $locked->save();

            return true;
        });

        if ($consumed) {
            // A instância do chamador ficou com a lista velha em memória; sem o
            // refresh, um segundo verify() no mesmo request ainda enxergaria o
            // código que acabou de ser queimado.
            $user->refresh();

            Audit::log('performer.2fa_recovery_code_used', $user, [
                'remaining' => $this->remainingRecoveryCodes($user),
            ]);
        }

        return $consumed;
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        return collect()
            ->times(self::RECOVERY_CODE_COUNT, fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }

    /**
     * QR renderizado LOCALMENTE, em SVG inline.
     *
     * Nunca por serviço externo de QR (o `chart.googleapis.com/...` que aparece
     * em quase todo tutorial de TOTP): a otpauth:// carrega o segredo em claro,
     * então terceirizar o desenho é entregar o segundo fator de todas as
     * performers a um host de terceiro — e deixá-lo no log de acesso dele.
     * Mesma regra do resto do projeto: nada de asset externo.
     */
    private function qrSvg(string $uri): string
    {
        $writer = new Writer(
            new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)
        );

        return $writer->writeString($uri);
    }
}
