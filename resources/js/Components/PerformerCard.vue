<script setup>
import { computed, ref, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { postJson } from '@/lib/http'
import FavoriteButton from '@/Components/FavoriteButton.vue'

// Card v2 — "maison de joalheria": foto retrato preenche o card, barra inferior
// sobreposta com o mínimo (nome + selo dourado + "Ativa hoje"). O que saiu do
// card (rating, seguidores, chips de verificação/email/categoria, UF) vive na
// página de perfil, não some do produto. Regra de estado vazio: métrica zerada
// NUNCA aparece — por isso nada de "0.0" nem contagem aqui.
//
// Privacidade: o card só usa dados JÁ públicos do PerformerPublicResource.
// `idade` e `cidade` NÃO existem lá (cidade é interna por decisão de produto;
// idade nunca foi publicada) — por isso a segunda linha é a atividade em faixa
// ("Ativa hoje"), que já era pública, e não localização ao minuto.

const props = defineProps({
    performer: { type: Object, required: true },
    // Quais props recarregar depois do toggle de favorito. O catálogo recarrega
    // 'performers'; a tela de Favoritos usa o mesmo nome de prop.
    favoriteReloadOnly: { type: Array, default: () => ['performers'] },
    // Slot de Destaque (Boost) no topo do grid: mesmo card, proporção mais larga
    // (ocupa 2 colunas) e nome maior. A largura de 2 colunas é do PAI (grid);
    // aqui só a proporção e a tipografia mudam.
    featured: { type: Boolean, default: false },
})

// O catálogo autenticado não é exclusivo do membro — performer e admin também
// navegam por ele. Só `role:consumer` alcança o toggle de favorito e o funil de
// chat, então os ícones de ação só aparecem para quem o clique levaria a algum
// lugar. Mesmo corte do `canTip` na página pública do perfil.
const page = usePage()
const canFavorite = computed(() => page.props.auth?.user?.role === 'consumer')

const profileHref = computed(() => route('catalog.show', props.performer.slug))

// Foto de perfil preenche o card. Sem avatar cai na capa; sem nenhuma, no
// placeholder. object-cover recorta para o retrato 3:4.
const photoUrl = computed(() => props.performer.avatar_url || props.performer.cover_url || null)

// Curadoria: Maison ganha aro dourado no card + chip; Select, variação sutil do
// selo; Verificada, só o selo. Tier do MEMBRO nunca aparece — isto é da performer.
const isMaison = computed(() => props.performer.tier === 'maison')
const isSelect = computed(() => props.performer.tier === 'select')

// Preview animado da live (Sprint 15, PR #143). Só para membro autenticado (a
// rota é gateada) e performer ao vivo. `previewBroken` esconde a <img> se o
// frame 404 (live sem quadro) mas mantém o card interativo — cai no badge.
// Feature flag: com `live_enabled` off ninguém está ao vivo — nada de badge
// nem preview, mesmo que `is_live` venha true por dado velho.
const showLive = computed(() => props.performer.is_live && !!page.props.features?.live_enabled)
const canPreview = computed(() => canFavorite.value && showLive.value)
const previewActive = ref(false)
const previewBroken = ref(false)
const previewSrc = ref(null)
let previewTimer = null

function loadPreview() {
    // Cache-bust: enquanto o cursor está sobre o card, repuxa o último quadro
    // (o backend sobrescreve a cada ~10s) — o preview "anima".
    previewSrc.value = route('live.preview', props.performer.slug) + '?t=' + Date.now()
}

function startPreview() {
    if (!canPreview.value) return
    previewActive.value = true
    previewBroken.value = false
    loadPreview()
    if (!previewTimer) previewTimer = setInterval(loadPreview, 10000)
    // Em paralelo, tenta o preview REAL por WebRTC (v2). O snapshot fica de
    // fundo/fallback e o vídeo entra por cima quando (e se) conectar.
    startWebrtcPreview()
}

function stopPreview() {
    previewActive.value = false
    if (previewTimer) { clearInterval(previewTimer); previewTimer = null }
    stopWebrtcPreview()
}

// ─── Preview WebRTC real (Sprint 16, v2 do PR #143) ──────────────────────────
//
// No hover de um card AO VIVO, abre um subscriber LiveKit e mostra o vídeo REAL
// em baixa resolução (adaptiveStream escolhe a camada pela caixa do elemento).
// Ao sair do hover, desconecta na hora — conexão nenhuma fica aberta. Timeout de
// 5s para conectar: se não vier, fica o snapshot JPEG (v1) de fallback, que já
// está desenhado. Assistir a live pública é GRÁTIS (LiveSessionService), então o
// preview NÃO cobra o membro — reusa o token de viewer (rota live.refresh).
//
// SÓ desktop com hover real: em toque não há hover, e o tap segue como antes
// (mostra o snapshot; 2º tap entra na live). livekit-client é importado sob
// demanda (dynamic import) — não entra no bundle do catálogo, só carrega no 1º
// hover de uma live.
const webrtcVideoEl = ref(null)
const webrtcActive = ref(false)
const WEBRTC_CONNECT_TIMEOUT_MS = 5000
const HOVER_DEBOUNCE_MS = 350
let room = null
let hoverTimer = null
let connectTimer = null
// Gera um "token de tentativa": cada stop incrementa, e todo passo assíncrono
// confere se ainda é a tentativa corrente — um connect que resolve DEPOIS do
// mouse-leave é descartado (e a sala, desconectada).
let previewGen = 0

function webrtcSupported() {
    return typeof window !== 'undefined'
        && typeof window.RTCPeerConnection !== 'undefined'
        // Só onde há hover de verdade (desktop): em toque o mouseenter é ruído.
        && window.matchMedia?.('(hover: hover) and (pointer: fine)')?.matches === true
}

function startWebrtcPreview() {
    if (!canPreview.value || !webrtcSupported() || room || hoverTimer) return
    // Debounce: só conecta se o cursor ficar sobre o card — evita abrir/fechar
    // subscriber a cada passagem rápida (custo de minuto no LiveKit).
    hoverTimer = setTimeout(connectWebrtc, HOVER_DEBOUNCE_MS)
}

async function connectWebrtc() {
    hoverTimer = null
    const gen = previewGen
    connectTimer = setTimeout(stopWebrtcPreview, WEBRTC_CONNECT_TIMEOUT_MS)

    try {
        // Import sob demanda: mantém o livekit-client fora do bundle do catálogo.
        const { Room, RoomEvent } = await import('livekit-client')
        if (gen !== previewGen) return

        const { token, wsUrl } = await postJson(route('live.refresh', props.performer.slug))
        if (gen !== previewGen) return

        room = new Room({ adaptiveStream: true, dynacast: true })
        room.on(RoomEvent.TrackSubscribed, (track) => {
            // Só o vídeo — preview é mudo (áudio nem é anexado; autoplay exige mudo).
            if (track.kind === 'video' && webrtcVideoEl.value && gen === previewGen) {
                track.attach(webrtcVideoEl.value)
                webrtcActive.value = true
                if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
            }
        })

        await room.connect(wsUrl, token)
        if (gen !== previewGen) { stopWebrtcPreview(); return }

        // Tracks já publicados pela performer no momento do connect.
        room.remoteParticipants.forEach((p) =>
            p.trackPublications.forEach((pub) => {
                if (pub.track?.kind === 'video' && webrtcVideoEl.value) {
                    pub.track.attach(webrtcVideoEl.value)
                    webrtcActive.value = true
                    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
                }
            }),
        )
    } catch {
        // Token 403, live encerrada, WebRTC bloqueado: silencioso — fica o
        // snapshot JPEG que já está na tela.
        stopWebrtcPreview()
    }
}

function stopWebrtcPreview() {
    previewGen += 1 // invalida qualquer tentativa em voo
    if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    webrtcActive.value = false
    if (room) {
        // disconnect() é idempotente; o preview não retém nada.
        room.disconnect()
        room = null
    }
}

// Clique sobre a foto de uma performer ao vivo entra na LIVE, não no perfil.
// Desktop: o hover já mostrou o preview, o clique entra. Mobile (sem hover):
// 1º tap mostra o preview, 2º tap entra — o pedido da spec do PR #143.
function onImageClick(e) {
    if (!canPreview.value) return // card normal: deixa o <Link> abrir o perfil
    e.preventDefault()
    e.stopPropagation()
    if (previewActive.value) {
        router.visit(route('live.show', props.performer.slug))
    } else {
        startPreview()
    }
}

onBeforeUnmount(stopPreview)
</script>

<template>
    <div
        class="mi-card group relative overflow-hidden rounded-xl bg-limen-surface-2 transition-all duration-200"
        :class="[
            featured ? 'aspect-[16/10]' : 'aspect-[3/4]',
            isMaison
                ? 'ring-1 ring-limen-gold/80 shadow-[0_0_22px_-10px_rgba(214,184,114,0.5)]'
                : 'ring-1 ring-limen-line hover:ring-limen-gold/40',
        ]"
    >
        <!-- Ações (favorito, mensagem): IRMÃS do <Link> de navegação, nunca
             aninhadas (um <button>/<a> dentro de <a> é HTML inválido e o clique
             disputaria a navegação). z acima do link, cada uma trata o próprio
             clique. Bookmark é PRIVADO — nada daqui chega à performer, e por isso
             não há contador (ver FavoriteService). -->
        <div v-if="canFavorite" class="absolute bottom-3 right-3 z-20 flex items-center gap-2">
            <!-- Mensagem: leva ao PERFIL, onde vive o CTA de chat interest-gated
                 (não há chat frio; o catálogo não carrega estado de conversa por
                 performer). O front NÃO pula o ChatController — o gate de tokens/
                 interesse continua na tela de destino. -->
            <Link
                :href="profileHref"
                aria-label="Conversar"
                class="grid h-9 w-9 place-items-center rounded-full bg-black/45 text-limen-gold ring-1 ring-limen-gold/40 backdrop-blur-sm transition-colors hover:bg-black/65 hover:ring-limen-gold/70"
            >
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.5A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" />
                </svg>
            </Link>
            <FavoriteButton
                :slug="performer.slug"
                :saved="!!performer.is_favorited"
                :reload-only="favoriteReloadOnly"
            />
        </div>

        <!-- Canto superior esquerdo: AO VIVO tem precedência; senão, story não
             visto vira mini-avatar com aro dourado. Os dois são IRMÃOS do link
             (o mini-avatar navega por conta própria). O selo "Nova" empilha ABAIXO
             deles (feat/activity-badges) — sinal de entrada recente, discreto e
             dourado (nunca limen-live, que é exclusivo do "ao vivo"). -->
        <div class="absolute top-3 left-3 z-20 flex flex-col items-start gap-1.5">
            <span
                v-if="showLive"
                role="img"
                aria-label="Ao vivo"
                class="inline-flex items-center gap-1.5 rounded-full bg-limen-live px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-white shadow-lg"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />
                Ao vivo
            </span>
            <!-- Story não visto (Sprint 9C): `has_unseen_stories` é dado do
                 MEMBRO — a performer não recebe nada daqui (ver
                 StoryVisibilityService). Leva ao perfil, onde o StoryStrip abre
                 o viewer. Sem pulsar: o pulso é do "ao vivo" (tempo real); story
                 é conteúdo parado esperando. -->
            <Link
                v-else-if="performer.has_unseen_stories"
                :href="profileHref"
                aria-label="Ver stories"
                class="block rounded-full p-0.5 ring-2 ring-limen-gold"
            >
                <span class="grid h-[34px] w-[34px] place-items-center overflow-hidden rounded-full bg-limen-surface-2">
                    <img v-if="performer.avatar_url" :src="performer.avatar_url" :alt="performer.stage_name" class="h-full w-full object-cover" />
                    <span v-else class="font-serif text-sm text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
                </span>
            </Link>
            <!-- Selo "Nova": entrada recente (≤7 dias), BOOLEANO derivado do
                 servidor (is_new), nunca a data. Dourado e discreto, empilhado
                 abaixo do sinal de tempo real. -->
            <span
                v-if="performer.is_new"
                class="rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50 backdrop-blur-sm"
            >
                Nova
            </span>
            <!-- "Tem áudio" (feat/voice-intro): ícone discreto para quem tem intro
                 de voz APROVADA (has_voice_intro, BOOLEANO derivado). Dourado como
                 os demais selos, nunca limen-live. Empilha abaixo do "Nova". -->
            <span
                v-if="performer.has_voice_intro"
                role="img"
                aria-label="Tem apresentação de voz"
                class="inline-flex items-center rounded-full bg-black/45 px-1.5 py-1 text-limen-gold ring-1 ring-limen-gold/50 backdrop-blur-sm"
            >
                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3z" />
                    <path d="M17 11a1 1 0 1 1 2 0 7 7 0 0 1-6 6.93V20h2a1 1 0 1 1 0 2H9a1 1 0 1 1 0-2h2v-2.07A7 7 0 0 1 5 11a1 1 0 1 1 2 0 5 5 0 0 0 10 0z" />
                </svg>
            </span>
        </div>

        <!-- Chip MAISON: discreto, canto superior direito. Irmão do link. -->
        <span
            v-if="isMaison"
            class="absolute top-3 right-3 z-20 rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50 backdrop-blur-sm"
        >
            Maison
        </span>

        <!-- Link de navegação: cobre o card inteiro (foto + barra de texto). As
             ações ficam por cima (z-20). Para performer ao vivo, onImageClick
             intercepta e entra na live. -->
        <Link :href="profileHref" class="block h-full no-underline" :aria-label="performer.stage_name">
            <div
                class="relative h-full w-full"
                @mouseenter="startPreview"
                @mouseleave="stopPreview"
                @click="onImageClick"
            >
                <img
                    v-if="photoUrl"
                    :src="photoUrl"
                    :alt="performer.stage_name"
                    loading="lazy"
                    class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                />
                <!-- Sem foto: placeholder neutro (silhueta), nunca uma métrica. -->
                <div v-else class="flex h-full w-full items-center justify-center bg-limen-surface-2">
                    <svg class="h-16 w-16 text-limen-ink-mute/40" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
                    </svg>
                </div>

                <!-- Preview animado da live (PR #143): sobrepõe a foto no
                     hover/tap. pointer-events-none para o clique cair no
                     container (onImageClick → entra na live). Se o frame falha,
                     some e fica só o badge. -->
                <transition name="live-preview">
                    <img
                        v-if="previewActive && !previewBroken && showLive"
                        :src="previewSrc"
                        alt=""
                        class="pointer-events-none absolute inset-0 h-full w-full object-cover"
                        @error="previewBroken = true"
                    />
                </transition>

                <!-- Preview WebRTC real (Sprint 16): entra POR CIMA do snapshot
                     quando conecta, e some (voltando ao snapshot) ao sair do
                     hover ou se falhar. Mudo + playsinline para autoplay; o
                     elemento existe sempre (o track precisa de destino ao
                     anexar), mas só fica visível quando há vídeo. -->
                <video
                    v-show="webrtcActive && showLive"
                    ref="webrtcVideoEl"
                    muted
                    autoplay
                    playsinline
                    class="pointer-events-none absolute inset-0 h-full w-full object-cover"
                />

                <!-- Scrim para legibilidade da barra. pointer-events-none: o
                     clique atravessa para a foto/link. -->
                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-[#100d0a] via-[#100d0a]/70 to-transparent" />

                <!-- Barra inferior: nome + selo, e "Ativa hoje" abaixo. pr-24
                     reserva o canto para o cluster de ações. -->
                <div class="absolute inset-x-0 bottom-0 p-3 pr-24">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <h3 class="font-serif leading-tight text-limen-ink truncate" :class="featured ? 'text-2xl' : 'text-base'">{{ performer.stage_name }}</h3>
                        <!-- UM selo dourado. Maison/Select ganham variação sutil
                             (preenchido vs. contorno); Verificada é o selo base.
                             Só quando is_verified — selo é fato conferido. -->
                        <svg
                            v-if="performer.is_verified"
                            width="14" height="14" viewBox="0 0 20 20"
                            :fill="isMaison || isSelect ? 'currentColor' : 'none'"
                            stroke="currentColor" stroke-width="1.5"
                            class="shrink-0 text-limen-gold"
                            :title="isMaison ? 'Maison' : isSelect ? 'Select' : 'Verificada'"
                            aria-hidden="true"
                        >
                            <path d="M10 1.6l2 1.2 2.3-.2 1 2 2 1-.2 2.3 1.2 2-1.2 2 .2 2.3-2 1-1 2-2.3-.2-2 1.2-2-1.2-2.3.2-1-2-2-1 .2-2.3L1.6 10l1.2-2-.2-2.3 2-1 1-2 2.3.2 2-1.2z" />
                            <path v-if="!(isMaison || isSelect)" d="M6.7 10.2l2.1 2.1 4.5-4.7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                    <!-- Segunda linha: atividade em faixa ("Ativa hoje"). Some
                         quando is_live (a badge AO VIVO já diz "agora"). Sem
                         cidade (não é público) nem horário (é faixa, não relógio). -->
                    <p v-if="performer.activity_label && !showLive" class="mt-0.5 text-[11px] text-limen-ink-mute">
                        {{ performer.activity_label }}
                    </p>
                </div>
            </div>
        </Link>
    </div>
</template>

<style scoped>
/* Preview da live: fade-in suave ao entrar/sair (PR #143). */
.live-preview-enter-active,
.live-preview-leave-active {
    transition: opacity 0.35s ease;
}
.live-preview-enter-from,
.live-preview-leave-to {
    opacity: 0;
}
</style>
