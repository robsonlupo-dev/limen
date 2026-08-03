<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'

// Visualizador fullscreen dos Stories (Sprint 13), tipo Instagram: barra de
// progresso por segmento, timer de 5s com pausa ao segurar, tap esquerdo/direito
// para navegar, swipe lateral entre performers e swipe para baixo para fechar.
//
// O feed já vem FILTRADO pelo que o membro pode ver (StoryVisibilityService::
// feedFor só devolve o que passa por canView), então NÃO há story bloqueado aqui
// — o paywall/upsell vive no strip do perfil, não no feed. O único estado de
// exceção é a corrida: um story que venceu/saiu entre o feed e a abertura devolve
// 404/403 na imagem, e aí a tela mostra "indisponível" e segue em frente.
//
// Ver o story É o que registra a view: carregar `stories.image` grava a linha no
// servidor (com os guards de Ghost Mode). O pai recarrega o feed ao fechar, então
// o anel dourado→cinza reflete o SERVIDOR — para Ghost Mode ele nunca apaga.
const props = defineProps({
    groups: { type: Array, required: true },
    startGroupIndex: { type: Number, default: 0 },
})

const emit = defineEmits(['close'])

const DURATION = 5000
const TICK = 50

const groupIndex = ref(props.startGroupIndex)
const storyIndex = ref(0)
const elapsed = ref(0)
const paused = ref(false)
const imageLoaded = ref(false)
const imageError = ref(false)

const currentGroup = computed(() => props.groups[groupIndex.value] ?? null)
const currentStory = computed(() => currentGroup.value?.stories[storyIndex.value] ?? null)

const LEVEL_BADGE = {
    subscribers: '⭐ Assinantes',
    exclusive: '🔒 Exclusivo',
}
const levelBadge = computed(() => LEVEL_BADGE[currentStory.value?.visibility_level] ?? null)

const imageUrl = computed(() =>
    currentStory.value ? route('stories.image', currentStory.value.id) : null,
)

function firstUnseen(group) {
    const i = group.stories.findIndex((s) => !s.seen)
    return i === -1 ? 0 : i
}

// Começa no primeiro não-visto do grupo aberto (como o Instagram).
onMounted(() => {
    if (currentGroup.value) storyIndex.value = firstUnseen(currentGroup.value)
    document.body.style.overflow = 'hidden'
    timer = setInterval(tick, TICK)
})

onBeforeUnmount(() => {
    document.body.style.overflow = ''
    if (timer) clearInterval(timer)
})

let timer = null
let errorAdvance = null

function tick() {
    if (paused.value || !imageLoaded.value || imageError.value || !currentStory.value) return
    elapsed.value += TICK
    if (elapsed.value >= DURATION) nextStory()
}

// Reset a cada troca de story.
watch(currentStory, () => {
    elapsed.value = 0
    imageLoaded.value = false
    imageError.value = false
    if (errorAdvance) {
        clearTimeout(errorAdvance)
        errorAdvance = null
    }
})

function onImageLoad() {
    imageError.value = false
    imageLoaded.value = true
}

function onImageError() {
    // Corrida: story venceu/saiu entre o feed e a abertura. Mostra o fallback e
    // avança sozinho, sem travar o carrossel.
    imageError.value = true
    imageLoaded.value = true
    errorAdvance = setTimeout(nextStory, 1500)
}

function nextStory() {
    const group = currentGroup.value
    if (group && storyIndex.value < group.stories.length - 1) {
        storyIndex.value++
    } else {
        nextGroup()
    }
}

function prevStory() {
    if (storyIndex.value > 0) {
        storyIndex.value--
    } else if (groupIndex.value > 0) {
        groupIndex.value--
        storyIndex.value = 0
    } else {
        elapsed.value = 0 // já no primeiro: reinicia
    }
}

function nextGroup() {
    if (groupIndex.value < props.groups.length - 1) {
        groupIndex.value++
        storyIndex.value = firstUnseen(props.groups[groupIndex.value])
    } else {
        close()
    }
}

function prevGroup() {
    if (groupIndex.value > 0) {
        groupIndex.value--
        storyIndex.value = 0
    } else {
        elapsed.value = 0
    }
}

function close() {
    emit('close')
}

// ── Toque unificado: hold pausa, tap navega, swipe lateral troca de performer,
// swipe para baixo fecha. Pointer events cobrem mouse e toque. ────────────────
let pressStart = 0
let startX = 0
let startY = 0

function onPointerDown(e) {
    pressStart = e.timeStamp
    startX = e.clientX
    startY = e.clientY
    paused.value = true
}

function onPointerUp(e) {
    paused.value = false
    const dt = e.timeStamp - pressStart
    const dx = e.clientX - startX
    const dy = e.clientY - startY

    if (dy > 80 && Math.abs(dy) > Math.abs(dx)) {
        close()
        return
    }
    if (Math.abs(dx) > 60) {
        dx < 0 ? nextGroup() : prevGroup()
        return
    }
    if (dt < 250) {
        const rect = e.currentTarget.getBoundingClientRect()
        e.clientX - rect.left < rect.width * 0.33 ? prevStory() : nextStory()
    }
}

function segmentWidth(i) {
    if (i < storyIndex.value) return '100%'
    if (i > storyIndex.value) return '0%'
    return Math.min(elapsed.value / DURATION, 1) * 100 + '%'
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="currentGroup && currentStory"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black select-none touch-none"
            @pointerdown="onPointerDown"
            @pointerup="onPointerUp"
        >
            <div class="relative flex h-full w-full max-w-md flex-col">
                <!-- Barra de progresso: um segmento por story do grupo. -->
                <div class="absolute inset-x-0 top-0 z-20 flex gap-1 p-3">
                    <div
                        v-for="(s, i) in currentGroup.stories"
                        :key="s.id"
                        class="h-0.5 flex-1 overflow-hidden rounded-full bg-white/30"
                    >
                        <div class="h-full rounded-full bg-white" :style="{ width: segmentWidth(i) }" />
                    </div>
                </div>

                <!-- Cabeçalho: performer + nível + fechar. `pointerdown.stop` para
                     o clique no X / link não virar tap de navegação. -->
                <div class="absolute inset-x-0 top-0 z-20 flex items-center gap-3 px-4 pt-6" @pointerdown.stop @pointerup.stop>
                    <p class="flex-1 truncate text-sm font-medium text-white drop-shadow">
                        {{ currentGroup.performer.stage_name }}
                    </p>
                    <span v-if="levelBadge" class="rounded-full bg-black/40 px-2 py-0.5 text-[11px] text-white">
                        {{ levelBadge }}
                    </span>
                    <button
                        type="button"
                        aria-label="Fechar"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-2xl leading-none text-white/90 hover:text-white"
                        @click="close"
                    >
                        &times;
                    </button>
                </div>

                <!-- Imagem em tela cheia. Carregá-la registra a view no servidor. -->
                <div class="flex h-full w-full items-center justify-center">
                    <img
                        v-show="imageLoaded && !imageError"
                        :src="imageUrl"
                        :alt="`Story de ${currentGroup.performer.stage_name}`"
                        class="max-h-full max-w-full object-contain"
                        draggable="false"
                        @load="onImageLoad"
                        @error="onImageError"
                    />

                    <div v-if="!imageLoaded && !imageError" class="h-10 w-10 animate-spin rounded-full border-2 border-white/30 border-t-white" />

                    <div v-if="imageError" class="px-8 text-center text-sm text-white/70">
                        Este story não está mais disponível.
                    </div>
                </div>

                <!-- Convite (Sprint 12): quando o story é convite para este membro,
                     um CTA discreto para o funil pago. `pointerdown.stop` para o
                     link não virar navegação. -->
                <div
                    v-if="currentStory.is_invite"
                    class="absolute inset-x-0 bottom-0 z-20 flex flex-col items-center gap-2 p-6"
                    @pointerdown.stop
                    @pointerup.stop
                >
                    <span class="rounded-full bg-gold/90 px-3 py-1 text-xs font-medium text-background">💌 Convite</span>
                    <Link
                        :href="route('subscribe.index')"
                        class="rounded-lg bg-white px-5 py-2 text-sm font-medium text-background no-underline transition-colors hover:bg-white/90"
                    >
                        Assine para conversar
                    </Link>
                </div>
            </div>
        </div>
    </Teleport>
</template>
