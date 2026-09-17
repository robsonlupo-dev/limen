<?php

namespace App\Services;

use App\Exceptions\NicknameException;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\ChatContentFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apelido do membro (feat/member-nickname). Dona ÚNICA da regra: validação,
 * unicidade, cooldown de troca e remoção por moderação passam SÓ por aqui.
 *
 * A validação é MAIS RÍGIDA que a do chat de propósito: o filtro de chat NÃO
 * barra troca de contato (decisão do PO, registrada), mas o apelido é campo
 * PÚBLICO e PERMANENTE — então barra telefone, e-mail, link, rede social,
 * palavra reservada (se passar por plataforma/moderação), nome de performer
 * (personificação) e conduta abusiva. Reusa a normalização anti-desvio do
 * ChatContentFilter (leet/repetição/zero-width/fullwidth) para os casamentos.
 *
 * O apelido é APRESENTAÇÃO: nada no ledger/extrato/audit passa a depender dele —
 * lá continua o FanAlias.
 */
class MemberNicknameService
{
    /**
     * Chave canônica AGRESSIVA — é a unicidade E a base do casamento de keyword/
     * performer, e as duas PRECISAM ter a mesma força: se divergissem, um near-clone
     * barrado numa passaria na outra (achado da revisão de segurança). Reusa a
     * normalização anti-desvio do chat (leet/zero-width/fullwidth/lower/ascii),
     * colapsa repetição por completo e remove tudo que não for [a-z0-9] — mata espaço
     * e pontuação, que eram o furo ("j o a o" e "joao" agora colidem; "z a p" casa
     * "zap"). Case/acento/leet/espaço-insensível.
     */
    public static function normalizeUnique(string $nickname): string
    {
        $n = ChatContentFilter::normalizeForMatch(trim($nickname));
        $n = (string) preg_replace('/(.)\1+/u', '$1', $n);

        return (string) preg_replace('/[^a-z0-9]/u', '', $n);
    }

    /**
     * Valida o apelido. Lança NicknameException na primeira recusa. `$user` é
     * quem está definindo (excluído da checagem de unicidade — reusar o próprio
     * apelido não é conflito).
     */
    public function validate(string $nickname, ?User $user = null): void
    {
        $nickname = trim($nickname);
        $len = mb_strlen($nickname);
        if ($len < (int) config('nickname.min_length') || $len > (int) config('nickname.max_length')) {
            throw NicknameException::length();
        }

        // 1) Telefone disfarçado: sequência longa de dígitos DEPOIS de tirar
        //    separadores/espaços (checado ANTES do leet, que vira dígito em letra).
        $digitsOnly = preg_replace('/[\s().+\-_]/', '', $nickname);
        if (preg_match('/\d{'.(int) config('nickname.max_digit_run').',}/', (string) $digitsOnly)) {
            throw NicknameException::contact();
        }

        // 2) E-mail / arroba.
        if (str_contains($nickname, '@')) {
            throw NicknameException::contact();
        }

        // Forma normalizada anti-desvio (reusa a dona única do desvio) MAIS uma
        // colapsagem TOTAL de repetições (o filtro do chat só colapsa 3+→2 para não
        // destruir dígrafo legítimo numa frase; num apelido curto podemos ser mais
        // agressivos: "zaap"→"zap", "whaaats"→"whats", "teeele"→"tele"). Casamos os
        // keywords contra AS DUAS formas.
        $normalized = ChatContentFilter::normalizeForMatch($nickname);
        $collapsed = (string) preg_replace('/(.)\1+/u', '$1', $normalized);
        // Forma sem espaço/pontuação (a canônica). O filtro do chat PRESERVA espaço,
        // então "z a p", "i n s t a" e "o n l y f a n s" escapavam de todo casamento
        // de keyword (achado da revisão de segurança). Casamos contra as TRÊS formas.
        $spaceless = self::normalizeUnique($nickname);
        $hasKeyword = fn (string $needle) => $needle !== '' && (
            str_contains($normalized, $needle)
            || str_contains($collapsed, $needle)
            || str_contains($spaceless, $needle)
        );

        // 3) URL / domínio. (Casa na forma bruta em minúsculas — o leet
        //    trocaria o ponto/esquema.)
        $lower = Str::lower($nickname);
        foreach ((array) config('nickname.url_markers') as $marker) {
            if (str_contains($lower, $marker)) {
                throw NicknameException::contact();
            }
        }

        // 4) Rede social / contato (substring nas duas formas normalizadas).
        foreach ((array) config('nickname.contact_keywords') as $keyword) {
            if ($hasKeyword(ChatContentFilter::normalizeForMatch($keyword))) {
                throw NicknameException::contact();
            }
        }

        // 5) Palavra reservada (plataforma / moderação).
        foreach ((array) config('nickname.reserved') as $word) {
            if ($hasKeyword(ChatContentFilter::normalizeForMatch($word))) {
                throw NicknameException::reserved();
            }
        }

        // 6) Conduta abusiva — reusa o filtro de conteúdo do chat (ameaça/insulto).
        if (ChatContentFilter::blocks($nickname)) {
            throw NicknameException::conduct();
        }

        // 7) Personificação de performer + 8) unicidade entre membros — as duas sob
        //    a MESMA chave canônica (`normalizeUnique`) e a MESMA mensagem genérica
        //    (anti-oráculo). Alinhadas de propósito: com forças diferentes, um
        //    near-clone barrado numa (ex.: leet contra performer) passaria na outra
        //    (unicidade fraca entre membros) — era o furo de personificação membro→membro.
        $uniqueKey = $spaceless;

        if ($this->collidesWithPerformer($uniqueKey)) {
            throw NicknameException::unavailable();
        }

        $taken = User::where('nickname_normalized', $uniqueKey)
            ->when($user, fn ($q) => $q->whereKeyNot($user->id))
            ->exists();
        if ($taken) {
            throw NicknameException::unavailable();
        }
    }

    /**
     * Define/troca o apelido do membro. Valida, aplica o cooldown de troca e
     * grava (fora do $fillable — forceFill). Idempotente para o MESMO apelido
     * (reenviar o que já é o seu não conta como troca nem esbarra no cooldown).
     */
    public function set(User $user, string $nickname, ?Request $request = null): void
    {
        $nickname = trim($nickname);
        $uniqueKey = self::normalizeUnique($nickname);

        // Reenviar o próprio apelido: no-op (sem cooldown, sem nova validação
        // pesada de unicidade que ele mesmo ocuparia).
        if ($user->nickname_normalized === $uniqueKey && $user->nickname !== null) {
            return;
        }

        // Cooldown de TROCA: só quando já havia apelido definido (a primeira
        // escolha é livre). O relógio é o `nickname_set_at`.
        if ($user->nickname_set_at !== null) {
            $cooldownEnds = $user->nickname_set_at->copy()->addDays((int) config('nickname.cooldown_days'));
            if ($cooldownEnds->isFuture()) {
                throw NicknameException::cooldown((int) ceil(now()->diffInDays($cooldownEnds, false)));
            }
        }

        $this->validate($nickname, $user);

        // Serializa a corrida de duplo-submit no lock da linha do próprio membro,
        // e relê a unicidade sob o lock (a UNIQUE do banco é a rede final).
        DB::transaction(function () use ($user, $nickname, $uniqueKey) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $user->forceFill([
                'nickname' => $nickname,
                'nickname_normalized' => $uniqueKey,
                'nickname_set_at' => now(),
            ])->save();
        });

        Audit::log('member_nickname_set', $user, [], $request);
    }

    /**
     * Remove o apelido. Sem apelido, o membro volta ao FanAlias na exibição. O
     * `nickname_set_at` é PRESERVADO — a remoção por moderação não zera o cooldown
     * (senão bastaria denunciar-se para trocar toda semana). $byModerator só
     * muda o evento de audit.
     */
    public function remove(User $user, bool $byModerator = false, ?Request $request = null): void
    {
        $user->forceFill([
            'nickname' => null,
            'nickname_normalized' => null,
            // nickname_set_at intocado de propósito (cooldown segue valendo).
        ])->save();

        Audit::log($byModerator ? 'member_nickname_removed_by_moderator' : 'member_nickname_removed', $user, [], $request);
    }

    /**
     * O apelido normalizado bate com o nome artístico de alguma performer?
     * Comparação em PHP (a normalização é leet/ascii, não SQL). Carrega só os
     * nomes — barato no volume de lançamento; se a base de performers crescer para
     * dezenas de milhares, indexar um `stage_name_normalized`. (Ceiling registrado,
     * como o de FanAlias::resolveHandle.)
     */
    private function collidesWithPerformer(string $canonicalNickname): bool
    {
        if ($canonicalNickname === '') {
            return false;
        }

        return PerformerProfile::query()
            ->pluck('stage_name')
            ->contains(fn (?string $name) => $name !== null
                && self::normalizeUnique($name) === $canonicalNickname);
    }
}
