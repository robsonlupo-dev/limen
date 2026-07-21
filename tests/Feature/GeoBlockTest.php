<?php

use App\Services\GeoLocationService;
use App\Support\ClientFingerprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Geobloqueio por país (FOSTA-SESTA).
 *
 * O ponto destes testes é o COMPORTAMENTO POR DRIVER, não "bloqueia americano":
 * com o driver padrão (`none`, o estado de hoje) o middleware é um no-op
 * deliberado, e um teste que provasse bloqueio sem dizer sob qual configuração
 * daria a impressão de que a proteção está de pé em produção. Não está — ver
 * config/geo.php.
 */
beforeEach(function () {
    Cache::flush();
});

// ─── Driver `none` (padrão de hoje) ─────────────────────────────────────────

it('não bloqueia ninguém com o driver padrão', function () {
    config(['geo.driver' => 'none', 'geo.blocked_countries' => ['US']]);

    // Mesmo anunciando-se como EUA: sem fonte de geolocalização o país é
    // desconhecido, e desconhecido não é barrado (fail-open).
    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
        ->get(route('landing'))
        ->assertOk();
});

it('driver desconhecido no .env não vira bloqueio geral nem passe livre', function () {
    config(['geo.driver' => 'clodflare', 'geo.blocked_countries' => ['US']]);

    // Erro de digitação no .env resolve para "não sei" — o estado que o
    // operador sabe interpretar —, não para o site fora do ar.
    $this->get(route('landing'))->assertOk();
});

// ─── Driver `cloudflare` ────────────────────────────────────────────────────

it('bloqueia com 451 o país da lista', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
        ->get(route('landing'))
        ->assertStatus(451)
        ->assertSee('Este serviço não está disponível na sua região.', false);
});

it('deixa passar país fora da lista', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'BR')
        ->get(route('landing'))
        ->assertOk();
});

it('a lista é case-insensitive e vem do config', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US', 'CA']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'ca')
        ->get(route('landing'))
        ->assertStatus(451);
});

it('responde 451 em JSON na porta da API', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
        ->getJson('/api/v1/performers')
        ->assertStatus(451)
        ->assertJson(['message' => 'Este serviço não está disponível na sua região.']);
});

it('header malformado não vira país nem entra no log', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    // 4 KB de lixo no header: vira "não sei", não vira metadata de audit.
    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, str_repeat('U', 4096))
        ->get(route('landing'))
        ->assertOk();

    expect(DB::table('audit_logs')->where('action', 'access.geo_blocked')->count())->toBe(0);
});

it('XX do Cloudflare é desconhecido, não um país', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'XX')
        ->get(route('landing'))
        ->assertOk();
});

// ─── /up fica fora ──────────────────────────────────────────────────────────

it('não bloqueia o health check', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    // Monitor de uptime costuma sondar dos EUA: barrar /up transformaria o
    // geobloqueio em alarme falso permanente.
    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
        ->get('/up')
        ->assertOk();
});

// ─── block_unknown ──────────────────────────────────────────────────────────

it('block_unknown barra quem não pôde ser localizado', function () {
    config([
        'geo.driver' => 'cloudflare',
        'geo.blocked_countries' => ['US'],
        'geo.block_unknown' => true,
    ]);

    // Sem header (bateu direto no origin, sem passar pelo Cloudflare).
    $this->get(route('landing'))->assertStatus(451);
});

// ─── Audit ──────────────────────────────────────────────────────────────────

it('registra a tentativa bloqueada sem o IP em claro no metadata', function () {
    config(['geo.driver' => 'cloudflare', 'geo.blocked_countries' => ['US']]);

    $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
        ->get(route('landing'))
        ->assertStatus(451);

    $log = DB::table('audit_logs')->where('action', 'access.geo_blocked')->first();

    expect($log)->not->toBeNull();

    $metadata = json_decode((string) $log->metadata, true);

    expect($metadata['country'])->toBe('US')
        ->and($metadata['ip_hash'])->toBe(ClientFingerprint::hash('127.0.0.1'))
        ->and($metadata['ip_hash'])->not->toContain('127.0.0.1');
});

it('deduplica o audit por IP na janela — um bot em laço não inunda a trilha', function () {
    config([
        'geo.driver' => 'cloudflare',
        'geo.blocked_countries' => ['US'],
        'geo.audit_dedup_minutes' => 60,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->withHeader(GeoLocationService::CLOUDFLARE_HEADER, 'US')
            ->get(route('landing'))
            ->assertStatus(451);
    }

    // Cinco tentativas, uma linha: a trilha (quem, de onde, quando começou)
    // sobrevive; o vetor de flood de um endpoint não autenticado, não.
    expect(DB::table('audit_logs')->where('action', 'access.geo_blocked')->count())->toBe(1);
});
