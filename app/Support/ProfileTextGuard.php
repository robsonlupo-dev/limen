<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Guarda de contato/conduta para TEXTO LIVRE do perfil público do membro (a bio
 * "Sobre mim", feat/member-profile-v2). Campo PÚBLICO — a performer lê —, então
 * recebe a MESMA disciplina do apelido: barra telefone disfarçado, e-mail/@, URL,
 * rede social (com anti-desvio leet/espaço) e conduta abusiva.
 *
 * NÃO é um filtro novo: reusa a dona única do desvio (ChatContentFilter::
 * normalizeForMatch) e as MESMAS listas do apelido (config/nickname.php —
 * contact_keywords, url_markers, max_digit_run). O que muda em relação ao
 * MemberNicknameService é só o que NÃO se aplica a texto de parágrafo:
 * unicidade, personificação de performer e cooldown ficam de fora (a bio não é
 * identificador). Por isso vive à parte, sem tocar aquele serviço.
 */
final class ProfileTextGuard
{
    /**
     * Primeiro tipo de violação encontrado ('contact' | 'conduct') ou null se o
     * texto passa. 'contact' cobre telefone/e-mail/URL/rede social; 'conduct'
     * é o filtro de conteúdo do chat (ameaça/insulto direcionado).
     */
    public static function violation(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // 1) Telefone disfarçado: N+ dígitos consecutivos DEPOIS de tirar
        //    separadores/espaços. Mesma regra e mesmo limite do apelido.
        $digitsOnly = preg_replace('/[\s().+\-_]/', '', $text);
        if (preg_match('/\d{'.(int) config('nickname.max_digit_run').',}/', (string) $digitsOnly)) {
            return 'contact';
        }

        // 2) E-mail / arroba.
        if (str_contains($text, '@')) {
            return 'contact';
        }

        // 3) URL / domínio (na forma bruta em minúsculas — o leet trocaria o ponto).
        $lower = Str::lower($text);
        foreach ((array) config('nickname.url_markers') as $marker) {
            if (str_contains($lower, (string) $marker)) {
                return 'contact';
            }
        }

        // Três formas normalizadas anti-desvio, como o apelido: a normalizada
        // (espaço preservado), a de repetições colapsadas ("zaap"→"zap") e a sem
        // espaço/pontuação ("z a p"→"zap", "i n s t a"→"insta"). Casa o keyword
        // contra as três.
        $normalized = ChatContentFilter::normalizeForMatch($text);
        $collapsed = (string) preg_replace('/(.)\1+/u', '$1', $normalized);
        $spaceless = (string) preg_replace('/[^a-z0-9]/u', '', $collapsed);
        $hasKeyword = fn (string $needle) => $needle !== '' && (
            str_contains($normalized, $needle)
            || str_contains($collapsed, $needle)
            || str_contains($spaceless, $needle)
        );

        // 4) Rede social / contato.
        foreach ((array) config('nickname.contact_keywords') as $keyword) {
            if ($hasKeyword(ChatContentFilter::normalizeForMatch((string) $keyword))) {
                return 'contact';
            }
        }

        // 5) Conduta abusiva — reusa o filtro de conteúdo do chat.
        if (ChatContentFilter::blocks($text)) {
            return 'conduct';
        }

        return null;
    }

    public static function blocks(string $text): bool
    {
        return self::violation($text) !== null;
    }
}
