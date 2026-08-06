<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Exceptions\FeatureDisabledException;
use App\Support\FanAlias;

/**
 * Dona ÚNICA de rooms e tokens LiveKit (Sprint 15). Lê tudo de config/livekit.php
 * e config/features.php. Nenhuma outra classe fala com o LiveKit.
 *
 * ── Identity: NUNCA o id cru do membro (achado 🔴 da revisão de segurança) ──
 * O JWT leva a identity em `sub`, e participantes de uma sala VEEM a identity uns
 * dos outros (participant list) + ela vai para o terceiro (LiveKit). Pôr
 * `member:{id}` ali seria um id ESTÁVEL e GLOBAL, correlacionável entre as lives
 * de todas as performers — exatamente o que o FanAlias existe para impedir. Por
 * isso a identity do membro é derivada pelos helpers, nunca do userId cru:
 *   - Live pública 1:N → OPACO por live (liveParticipantIdentity), derivado por
 *     HMAC(APP_KEY, live_session_id + member_id): estável nas renovações de token
 *     da MESMA live (o refresh não vira um participante novo), mas some entre
 *     lives (o session_id muda a cada transmissão) — apaga até a frequência de
 *     retorno do "fã de sempre". Não é reversível ao member_id pela performer, e
 *     por ser DERIVÁVEL dispensa uma tabela de participantes (que seria a
 *     watch-list "quem assistiu" que §2.7 recusa). Ver PR #139.
 *   - Chamada 1:1 → FanAlias handle por par (callMemberIdentity): a performer JÁ
 *     vê o "Fã #" dela (superfície legítima membro→performer, como no chat).
 *   - Performer → identidade pública (performerIdentity): é a identidade
 *     verificada, não há assimetria a proteger.
 *
 * O segredo (api_secret) assina o JWT LOCALMENTE (HS256) e nunca sai daqui —
 * nunca logar/serializar o config em exceção.
 */
class LiveKitService
{
    public const ROLE_PERFORMER = 'performer';

    public const ROLE_MEMBER = 'member';

    public const ROOM_PREFIX_LIVE = 'live';

    public const ROOM_PREFIX_CALL = 'call';

    public const ROOM_PREFIX_GROUP = 'group';

    private ?RoomServiceClient $roomClient = null;

    // ── Nomes de sala (imprevisíveis, CSPRNG) ────────────────────────────────
    // O nome nunca é o único gate: a autorização mora na emissão do token (o PR
    // de serving só emite para quem tem tier/pagamento/não-ban) e o token confina
    // à sala. O hex aleatório é defesa-em-profundidade contra ENUMERAÇÃO, não
    // autorização — por isso o roomName nunca pode aparecer em log/URL/resposta.

    public function liveRoomName(int $performerId): string
    {
        return self::ROOM_PREFIX_LIVE."-{$performerId}-".bin2hex(random_bytes(4));
    }

    public function callRoomName(): string
    {
        return self::ROOM_PREFIX_CALL.'-'.bin2hex(random_bytes(6));
    }

    public function groupRoomName(int $performerId): string
    {
        return self::ROOM_PREFIX_GROUP."-{$performerId}-".bin2hex(random_bytes(4));
    }

    // ── Identity (nunca o id cru) ────────────────────────────────────────────

    /**
     * Live pública: identity OPACA por live, derivada por HMAC(APP_KEY, session +
     * member). Estável dentro da mesma live (refresh não cria participante novo),
     * distinta entre lives (session_id muda) e não reversível ao member_id — sem
     * tabela de participantes (ver docblock da classe). Nunca o id cru.
     */
    public function liveParticipantIdentity(int $liveSessionId, int $memberId): string
    {
        $digest = hash_hmac(
            'sha256',
            "live_viewer:{$liveSessionId}:{$memberId}",
            (string) config('app.key'),
        );

        return 'lv_'.substr($digest, 0, 16);
    }

    /** Chamada 1:1: FanAlias handle por par (a performer vê o "Fã #" dela). */
    public function callMemberIdentity(int $performerProfileId, int $memberId): string
    {
        return self::ROLE_MEMBER.':'.FanAlias::handle($performerProfileId, $memberId);
    }

    /** Performer: identidade pública verificada (sem assimetria a proteger). */
    public function performerIdentity(int $performerProfileId): string
    {
        return self::ROLE_PERFORMER.':'.$performerProfileId;
    }

    // ── Token ────────────────────────────────────────────────────────────────

    /**
     * Emite o JWT de acesso a UMA sala. `$identity` já vem DERIVADO pelos helpers
     * acima (nunca o id cru — ver docblock da classe). `$grants` =
     * ['canPublish'=>bool, 'canSubscribe'=>bool, 'canPublishData'=>bool].
     *
     * O VideoGrant é amarrado a `$roomName` (roomJoin + room): um token de uma
     * sala NÃO entra em outra. TTL = config('livekit.token_ttl').
     */
    public function generateToken(string $identity, string $role, string $roomName, array $grants): string
    {
        // Backstop de dark launch: a fonte de credencial não emite com a feature
        // desligada, mesmo fora de rota gateada (defesa em profundidade).
        $this->assertFeatureEnabledForRoom($roomName);

        $options = (new AccessTokenOptions)
            ->setIdentity($identity)
            ->setName($role)
            ->setTtl($this->tokenTtl());

        $token = new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret'),
            $options,
        );

        $video = (new VideoGrant)
            ->setRoomJoin(true)
            ->setRoomName($roomName)
            ->setCanPublish((bool) ($grants['canPublish'] ?? false))
            ->setCanSubscribe((bool) ($grants['canSubscribe'] ?? false))
            ->setCanPublishData((bool) ($grants['canPublishData'] ?? false));

        $token->setGrant($video);

        return $token->toJwt();
    }

    public function tokenTtl(): int
    {
        return (int) config('livekit.token_ttl');
    }

    // ── Rooms (CRUD contra o LiveKit) ────────────────────────────────────────

    /**
     * Cria a sala. Gateada pela feature (mesma disciplina do generateToken). Os
     * tetos vêm de config/livekit.php via o chamador (o PR de serving passa o
     * max do tipo da sala).
     */
    public function createRoom(string $name, int $maxParticipants): void
    {
        $this->assertFeatureEnabledForRoom($name);

        $this->rooms()->createRoom(
            (new RoomCreateOptions)
                ->setName($name)
                ->setMaxParticipants($maxParticipants)
                // Sala abandonada (performer fecha o navegador sem /stop) morre
                // sozinha após este ocioso — o LiveKit a remove, e o join passa a
                // dar 404 na reconciliação-na-leitura (LiveSessionService::activeFor).
                ->setEmptyTimeout((int) config('livekit.empty_timeout')),
        );
    }

    /**
     * Apaga a sala. NÃO checa a flag de propósito: é teardown/kill-switch — tem
     * que rodar DEPOIS de desligar a feature para esvaziar salas vivas.
     */
    public function deleteRoom(string $name): void
    {
        $this->rooms()->deleteRoom($name);
    }

    /**
     * Expulsa um participante (revogação ATIVA). NÃO checa a flag: é a resposta a
     * ban/perda-de-tier e não pode depender da expiração passiva do token (o
     * token vale até token_ttl mesmo com o direito já perdido).
     */
    public function revokeParticipant(string $roomName, string $identity): void
    {
        $this->rooms()->removeParticipant($roomName, $identity);
    }

    public function listParticipants(string $roomName): array
    {
        return iterator_to_array($this->rooms()->listParticipants($roomName)->getParticipants());
    }

    public function roomExists(string $roomName): bool
    {
        return count($this->rooms()->listRooms([$roomName])->getRooms()) > 0;
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /** Cliente HTTP do LiveKit, lazy: token/identity não tocam a rede. */
    private function rooms(): RoomServiceClient
    {
        return $this->roomClient ??= new RoomServiceClient(
            $this->roomServiceUrl(),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );
    }

    /**
     * URL do RoomServiceClient (API de salas, server-side). O LIVEKIT_URL vem no
     * scheme `wss://` — o que o CLIENTE WebRTC no browser precisa —, mas o
     * RoomServiceClient fala Twirp sobre HTTP (Guzzle) e RECUSA wss/ws:
     * "The scheme 'wss' is not supported" (era o 500 do /performer/live/start).
     * Mesmo host, scheme HTTP: wss:// → https://, ws:// → http://. Uma URL já em
     * https/http passa intacta. A conversão mora aqui, não no config, porque o
     * .env guarda a URL do cliente (wss) — princípio nº 5, e o LiveKitService é a
     * dona única de como se fala com o LiveKit.
     */
    private function roomServiceUrl(): string
    {
        $url = (string) config('livekit.url');

        if (str_starts_with($url, 'wss://')) {
            return 'https://'.substr($url, 6);
        }

        if (str_starts_with($url, 'ws://')) {
            return 'http://'.substr($url, 5);
        }

        return $url;
    }

    /**
     * Deriva a feature do PREFIXO do nome da sala e barra se desligada. `live-` →
     * live; `call-` E `group-` → `call` (DECISÃO do PR #141: o group show COMPARTILHA
     * o kill-switch da chamada 1:1 — desligar `features.call_enabled` derruba a
     * emissão de token/sala do group também, defesa em profundidade do §2.5). O
     * branch `group-` é EXPLÍCITO de propósito: não deixar o dark-launch do group
     * depender do fallthrough.
     */
    private function assertFeatureEnabledForRoom(string $roomName): void
    {
        $feature = match (true) {
            str_starts_with($roomName, self::ROOM_PREFIX_LIVE.'-') => 'live',
            str_starts_with($roomName, self::ROOM_PREFIX_GROUP.'-') => 'call',
            default => 'call',
        };

        if (! config("features.{$feature}_enabled", false)) {
            throw new FeatureDisabledException($feature);
        }
    }
}
