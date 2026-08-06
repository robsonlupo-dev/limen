<script setup>
import { computed, ref, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import VerificationBadges from '@/Components/VerificationBadges.vue'
import StarRating from '@/Components/StarRating.vue'
import FollowButton from '@/Components/FollowButton.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'

const props = defineProps({
    performer: { type: Object, required: true },
    // Quais props recarregar depois do toggle de favorito. O catálogo recarrega
    // 'performers'; a tela de Favoritos usa o mesmo nome de prop.
    favoriteReloadOnly: { type: Array, default: () => ['performers'] },
})

// O catálogo autenticado não é exclusivo do membro — performer e admin também
// navegam por ele. Só `role:consumer` alcança POST /favoritos/{slug}, então o
// coração só aparece para quem o clique levaria a algum lugar. Mesmo corte do
// `canTip` na página pública do perfil.
const page = usePage()
const canFavorite = computed(() => page.props.auth?.user?.role === 'consumer')

// Preview animado da live (Sprint 15, PR #143). Só faz sentido para membro
// autenticado (a rota é gateada) e performer ao vivo. `previewActive` é o estado
// de hover/tap; `previewBroken` esconde a <img> se o frame 404 (live sem quadro)
// mas mantém o card interativo — cai no badge sozinho.
// Feature flag (Sprint 15): com `live_enabled` off ninguém pode estar ao vivo —
// nada de badge "AO VIVO" nem hover preview no card, mesmo que `is_live` venha
// true por dado velho.
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
}

function stopPreview() {
    previewActive.value = false
    if (previewTimer) { clearInterval(previewTimer); previewTimer = null }
}

// Clique sobre a IMAGEM de uma performer ao vivo entra na LIVE, não no perfil
// (o perfil segue no nome/info abaixo). Desktop: o hover já mostrou o preview, o
// clique entra. Mobile (sem hover): 1º tap mostra o preview, 2º tap entra —
// exatamente o pedido da spec.
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

const categoryLabels = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}
</script>

<template>
    <!-- Boost pago (Sprint 11): borda dourada sutil quando em destaque. O
         is_boosted é o booleano derivado do resource — a POSIÇÃO no topo já vem
         da ordenação do servidor; a borda só sinaliza. -->
    <div
        class="group relative rounded-xl border bg-surface overflow-hidden transition-all duration-200 hover:shadow-[0_0_24px_-8px_rgba(201,162,75,0.35)]"
        :class="performer.is_boosted
            ? 'border-gold/60 shadow-[0_0_20px_-8px_rgba(201,162,75,0.45)] hover:border-gold'
            : 'border-frame hover:border-gold/40'"
    >
        <!-- Coração de "salvar para ver depois" (Sprint 10).
             Fica FORA do <Link> — um <button> dentro de um <a> é HTML inválido e
             o clique disputaria com a navegação. Como irmão posicionado, o
             toggle não abre o perfil e o card inteiro continua clicável.
             Bookmark PRIVADO: nada daqui chega à performer, e por isso não há
             contador ao lado (ver FavoriteService). -->
        <div v-if="canFavorite" class="absolute top-2 right-2 z-10">
            <FavoriteButton
                :slug="performer.slug"
                :saved="!!performer.is_favorited"
                :reload-only="favoriteReloadOnly"
            />
        </div>

        <Link :href="route('catalog.show', performer.slug)" class="block no-underline">
            <!-- Wrapper de posicionamento SEM overflow-hidden: o avatar transborda
                 a capa (-bottom-6) e, se ficasse DENTRO da capa — que precisa de
                 overflow-hidden para o zoom no hover e para o preview animado —,
                 teria a base cortada (achado do UAT R3). O avatar passa a ser
                 IRMÃO da capa, posicionado em relação a este wrapper. -->
            <div class="relative">
            <div
                class="relative aspect-[4/3] bg-surface-2 overflow-hidden"
                @mouseenter="startPreview"
                @mouseleave="stopPreview"
                @click="onImageClick"
            >
                <img
                    v-if="performer.cover_url"
                    :src="performer.cover_url"
                    :alt="performer.stage_name"
                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                />
                <div v-else class="h-full w-full bg-gradient-to-br from-gold/25 via-surface-2 to-background" />

                <!-- Preview animado da live (PR #143): sobrepõe a capa no hover/tap.
                     Borda dourada PULSANTE = "ao vivo". pointer-events-none para o
                     clique cair no container (onImageClick → entra na live). Se o
                     frame falha (404/erro), some e fica só o badge. -->
                <transition name="live-preview">
                    <img
                        v-if="previewActive && !previewBroken && showLive"
                        :src="previewSrc"
                        alt=""
                        class="pointer-events-none absolute inset-0 h-full w-full object-cover rounded-[inherit] ring-2 ring-gold animate-pulse"
                        @error="previewBroken = true"
                    />
                </transition>

                <div
                    v-if="showLive"
                    role="img"
                    aria-label="Ao vivo"
                    class="absolute bottom-2 left-2 flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white backdrop-blur-sm"
                >
                    <span class="h-2 w-2 rounded-full bg-green-500 ring-2 ring-white animate-pulse" />
                    Ao vivo
                </div>

                <!-- Contador da galeria (Sprint 10). No canto SUPERIOR ESQUERDO,
                     porque o direito é do coração de favorito. Discreto e só com
                     foto além do avatar. Número exato: é conteúdo público da
                     performer (ver photos_count no PerformerPublicResource). -->
                <div
                    v-if="performer.photos_count > 0"
                    :aria-label="`${performer.photos_count} fotos`"
                    class="absolute top-2 left-2 flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-xs text-cream backdrop-blur-sm"
                >
                    <span aria-hidden="true">📷</span>{{ performer.photos_count }}
                </div>
            </div>

                <!-- Avatar: irmão da capa (fora do overflow-hidden dela), para o
                     transbordo -bottom-6 não ser cortado. object-cover + rounded-full
                     + dimensão fixa mantêm o recorte circular do UAT R3. -->
                <div class="absolute -bottom-6 left-4">
                    <div class="h-14 w-14 rounded-full border-2 border-gold bg-surface-2 overflow-hidden flex items-center justify-center shadow-lg">
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-xl text-gold">{{ performer.stage_name?.charAt(0) }}</span>
                    </div>

                    <!-- Story não visto (Sprint 9C): ponto dourado, sem texto e
                         SEM pulsar — o pulso é do "ao vivo", que é estado em
                         tempo real; story é conteúdo parado esperando você.
                         Dois indicadores pulsando no mesmo card competiriam.
                         Fica FORA do wrapper com overflow-hidden, senão o
                         arredondamento do avatar corta o ponto.
                         `has_unseen_stories` é dado do MEMBRO — a performer não
                         recebe nada daqui (ver StoryVisibilityService). -->
                    <span
                        v-if="performer.has_unseen_stories"
                        role="img"
                        aria-label="Stories não vistos"
                        class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-gold ring-2 ring-white"
                    />
                </div>
            </div>

            <div class="px-4 pt-9 pb-3 space-y-1.5">
                <div class="flex items-center gap-1.5 min-w-0">
                    <h3 class="font-serif text-lg text-cream truncate">{{ performer.stage_name }}</h3>
                    <VerifiedBadge :category="performer.category" />
                </div>
                <VerificationBadges
                    :is-verified="performer.is_verified"
                    :email-verified="performer.email_verified"
                    :category="performer.category"
                    :tier="performer.tier"
                />
                <!-- Boost pago (Sprint 11): badge discreto "⚡ Destaque" em
                     dourado, só quando boostado. Sinal de vitrine da própria
                     performer — nunca um timestamp. -->
                <p v-if="performer.is_boosted" class="text-xs text-gold flex items-center gap-1">
                    <span aria-hidden="true">⚡</span> Destaque
                </p>
                <!-- "Disponível para conversa" (Sprint 11): badge discreto em
                     dourado, na linha dos outros badges. Some quando is_live — a
                     bolinha verde já diz "quer contato agora", e os dois juntos
                     seriam redundantes (decisão do PO). Ver is_available no
                     PerformerPublicResource. -->
                <p v-if="performer.is_available && !performer.is_live" class="text-xs text-gold flex items-center gap-1">
                    <span aria-hidden="true">💬</span> Quer conversar
                </p>
                <p class="text-xs text-muted uppercase tracking-wide">
                    {{ categoryLabels[performer.category] ?? performer.category }}
                </p>
                <!-- Última atividade em faixa (Sprint 10): texto discreto abaixo
                     do nome. Vem pronto do servidor (ActivitySlot) — nunca um
                     horário. Suprimido quando is_live: aí a bolinha verde na foto
                     já diz "agora", e repetir em texto seria redundante (decisão
                     do PO). Ausente quando null (nunca ativa ou perk ligado). -->
                <p v-if="performer.activity_label && !performer.is_live" class="text-xs text-muted">
                    {{ performer.activity_label }}
                </p>
                <!-- Estado: SÓ a UF, e some quando a performer está ao vivo.
                     São duas regras distintas do PO no mesmo elemento:
                     1. Fica na área de info, NUNCA sobre a foto — a bolinha
                        verde de "ao vivo" mora lá, e o pedido é separá-los.
                     2. Nunca no mesmo card que o indicador ao vivo: "está
                        transmitindo AGORA" + "está em SP" é uma correlação em
                        tempo real que o estado sozinho não dá. Por isso o
                        `!performer.is_live`, e não só o afastamento visual.
                     A cidade não existe nesta prop (ver PerformerPublicResource).

                     Some TAMBÉM quando is_available: "quer conversar AGORA" +
                     "está em SP" é a mesma correlação presença-em-tempo-real +
                     localização do R2 que o `!is_live` fecha. O badge de
                     disponibilidade é presença como o "ao vivo", então o estado
                     cede a ele igual — nunca os dois no mesmo card. -->
                <!-- Múltiplas localizações (Sprint 13): uma chip por UF, "SP · RJ".
                     Só a UF — `city` não existe nesta prop (ver
                     PerformerPublicResource). Mesma supressão de sempre:
                     `!is_live && !is_available`, a correlação presença+localização
                     do R2. `states` cai em [state] quando há uma só, então o card
                     de quem tem uma localização fica idêntico. -->
                <p
                    v-if="performer.states && performer.states.length && !performer.is_live && !performer.is_available"
                    class="flex flex-wrap items-center gap-1 text-xs text-muted"
                >
                    <span
                        v-for="uf in performer.states"
                        :key="uf"
                        class="rounded border border-frame px-1.5 py-0.5 tracking-wide"
                    >{{ uf }}</span>
                </p>
                <div class="flex items-center justify-between pt-1">
                    <StarRating :rating="performer.rating_avg" />
                    <span class="text-xs text-muted">{{ performer.followers_label }} seguidores</span>
                </div>
            </div>
        </Link>

        <div class="px-4 pb-4">
            <FollowButton
                :slug="performer.slug"
                :following="performer.is_following"
                :reload-only="['performers']"
                size="sm"
                class="w-full"
            />
        </div>
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
