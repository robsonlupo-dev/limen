<?php

namespace App\Services;

use App\Exceptions\ReferralLinkException;
use App\Models\PerformerProfile;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\Audit;
use App\Support\ChatContentFilter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links personalizados de indicação (feat/referral-custom-links). Dona ÚNICA da
 * regra: formato, teto, reservadas, anti-personificação e unicidade passam SÓ
 * por aqui; a criação é a única escrita (links são imutáveis — ver migration).
 *
 * A validação herda a disciplina do apelido do membro (MemberNicknameService):
 * o slug é um nome PÚBLICO e PERMANENTE numa URL da casa, então barra contato
 * (telefone/rede social), palavra reservada (plataforma/rota), nome artístico de
 * criadora (personificação) e conduta — tudo casado nas formas normalizadas
 * anti-desvio (leet/repetição/zero-width), e TUDO com a mesma resposta genérica
 * (anti-oráculo). Só formato e teto têm mensagens próprias.
 */
class ReferralLinkService
{
    /** Normaliza a entrada para a forma canônica do slug (minúsculo, aparado). */
    public static function normalizeSlug(string $raw): string
    {
        return Str::lower(trim($raw));
    }

    /**
     * Cria um link personalizado para o usuário. Lança ReferralLinkException na
     * primeira recusa. A UNIQUE do banco é a rede final contra corrida.
     */
    public function create(User $user, string $rawSlug, ?Request $request = null): ReferralLink
    {
        $slug = self::normalizeSlug($rawSlug);

        $this->validateFormat($slug);
        $this->validateAvailability($slug);

        $max = (int) config('referral.custom_links_max', 2);

        try {
            $link = DB::transaction(function () use ($user, $slug, $max) {
                // Serializa o duplo-submit no lock da linha do usuário e relê o
                // teto sob o lock (mesma disciplina do apelido).
                User::whereKey($user->id)->lockForUpdate()->first();

                if (ReferralLink::where('user_id', $user->id)->count() >= $max) {
                    throw ReferralLinkException::limit($max);
                }

                $link = new ReferralLink;
                $link->forceFill(['user_id' => $user->id, 'slug' => $slug])->save();

                return $link;
            });
        } catch (QueryException $e) {
            // Corrida na UNIQUE(slug): alguém levou o nome entre a checagem e o
            // insert. Mesma resposta genérica.
            throw ReferralLinkException::unavailable();
        }

        Audit::log('referral_link_created', $user, ['slug' => $slug], $request);

        return $link;
    }

    /** Dono do slug, para a rota pública /r/{slug}. Null quando não existe. */
    public function resolveOwner(string $rawSlug): ?User
    {
        $slug = self::normalizeSlug($rawSlug);

        return ReferralLink::where('slug', $slug)->first()?->user;
    }

    // ── Validação ─────────────────────────────────────────────────────────────

    private function validateFormat(string $slug): void
    {
        // 3–20, [a-z0-9-], sem hífen nas pontas (vira URL limpa e à prova de typo).
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,19})?$/', $slug)
            || mb_strlen($slug) < 3
            || str_ends_with($slug, '-')) {
            throw ReferralLinkException::format();
        }
    }

    private function validateAvailability(string $slug): void
    {
        // Telefone disfarçado: sequência longa de dígitos depois de tirar o hífen
        // (teto herdado do apelido). Resposta genérica — não revela a regra.
        $digitsOnly = str_replace('-', '', $slug);
        if (preg_match('/\d{'.(int) config('nickname.max_digit_run', 5).',}/', $digitsOnly)) {
            throw ReferralLinkException::unavailable();
        }

        // Formas normalizadas anti-desvio, iguais às do apelido: a bruta já é
        // minúscula/ascii; a colapsada mata repetição ("zaap"→"zap"); a sem-hífen
        // fecha o desvio "z-a-p".
        $normalized = ChatContentFilter::normalizeForMatch($slug);
        $collapsed = (string) preg_replace('/(.)\1+/u', '$1', $normalized);
        $spaceless = MemberNicknameService::normalizeUnique($slug);
        $hasKeyword = fn (string $needle) => $needle !== '' && (
            str_contains($normalized, $needle)
            || str_contains($collapsed, $needle)
            || str_contains($spaceless, $needle)
        );

        // Reservadas da plataforma + contato/rede social + marcadores de URL
        // (listas do apelido) + reservadas específicas de rota (lista própria).
        $wordLists = array_merge(
            (array) config('nickname.reserved'),
            (array) config('nickname.contact_keywords'),
            (array) config('referral.link_reserved'),
        );
        foreach ($wordLists as $word) {
            if ($hasKeyword(ChatContentFilter::normalizeForMatch((string) $word))) {
                throw ReferralLinkException::unavailable();
            }
        }
        foreach ((array) config('nickname.url_markers') as $marker) {
            if (str_contains($slug, (string) $marker)) {
                throw ReferralLinkException::unavailable();
            }
        }

        // Conduta abusiva — mesma dona única do chat/apelido.
        if (ChatContentFilter::blocks($slug)) {
            throw ReferralLinkException::unavailable();
        }

        // Personificação de criadora: o slug canônico não pode bater com nome
        // artístico (mesma comparação em PHP do apelido; ceiling registrado lá).
        if ($spaceless !== '' && PerformerProfile::query()
            ->pluck('stage_name')
            ->contains(fn (?string $name) => $name !== null
                && MemberNicknameService::normalizeUnique($name) === $spaceless)) {
            throw ReferralLinkException::unavailable();
        }

        // Já tomado (por qualquer usuário, inclusive o próprio — são imutáveis).
        if (ReferralLink::where('slug', $slug)->exists()) {
            throw ReferralLinkException::unavailable();
        }
    }
}
