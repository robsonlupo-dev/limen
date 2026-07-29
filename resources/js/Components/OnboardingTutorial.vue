<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { setCookie } from '@/lib/cookies'
import PortalLogo from '@/Components/PortalLogo.vue'

// First-run product tutorial for members landing on the catalogue.
//
// Cookie note: this is deliberately NOT `limen_intro_seen`. That flag belongs to
// the guest brand splash (IntroAnimation, mounted by GuestLayout), which every
// visitor trips on the landing/login/signup pages *before* they ever reach the
// catalogue — reusing it would mean this tutorial never showed to a real member.
// See the commit message for the full trace.
const emit = defineEmits(['close'])

const COOKIE = 'limen_tutorial_seen'
const COOKIE_DAYS = 365

const slides = [
    {
        key: 'verified',
        icon: 'shield',
        title: 'Explore criadores verificados',
        body: 'Todos os perfis são verificados com documento e selfie. Você sabe que está conversando com quem diz ser.',
    },
    {
        key: 'tokens',
        icon: 'coin',
        title: 'Tokens são sua moeda',
        body: 'Compre tokens via PIX e use para desbloquear conversas, enviar gorjetas e acessar conteúdo exclusivo.',
    },
    {
        key: 'tips',
        icon: 'gift',
        title: 'Gorjetas abrem portas',
        body: 'Uma gorjeta é a forma mais rápida de chamar atenção. Performers notam quem valoriza o trabalho delas.',
    },
    {
        key: 'cta',
        icon: 'arch',
        title: 'Cruze o limiar',
        body: null,
    },
]

const index = ref(0)
const current = computed(() => slides[index.value])
const isLast = computed(() => index.value === slides.length - 1)

function advance() {
    if (!isLast.value) index.value += 1
}

function goTo(i) {
    index.value = i
}

// Both exits are the same action: persist the flag so the tutorial never
// replays, then let the parent drop the overlay.
function dismiss() {
    setCookie(COOKIE, '1', COOKIE_DAYS)
    emit('close')
}

// Touch swipe. Horizontal only, with a threshold so a vertical scroll gesture
// or a jittery tap does not skip a slide.
const SWIPE_MIN = 48
let touchStartX = null
let touchStartY = null

function onTouchStart(e) {
    touchStartX = e.changedTouches[0].clientX
    touchStartY = e.changedTouches[0].clientY
}

function onTouchEnd(e) {
    if (touchStartX === null) return
    const dx = e.changedTouches[0].clientX - touchStartX
    const dy = e.changedTouches[0].clientY - touchStartY
    touchStartX = null
    touchStartY = null

    if (Math.abs(dx) < SWIPE_MIN || Math.abs(dx) < Math.abs(dy)) return
    if (dx < 0) advance()
    else if (index.value > 0) index.value -= 1
}

// Keyboard equivalents of the pointer affordances above — the overlay covers the
// whole page, so it must be dismissable without a mouse.
function onKeydown(e) {
    if (e.key === 'Escape') dismiss()
    else if (e.key === 'ArrowRight') advance()
    else if (e.key === 'ArrowLeft' && index.value > 0) index.value -= 1
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-[9000] flex items-center justify-center bg-background/80 backdrop-blur-md px-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="onboarding-title"
            @touchstart.passive="onTouchStart"
            @touchend.passive="onTouchEnd"
        >
            <!-- Skip: present on every slide, deliberately low-contrast so it
                 never competes with the primary action. -->
            <button
                type="button"
                class="absolute top-5 right-6 text-xs tracking-wide text-muted/70 hover:text-cream transition-colors"
                @click.stop="dismiss"
            >
                Pular
            </button>

            <!-- Click anywhere on the card advances; the last slide waits for the
                 explicit CTA instead. -->
            <div
                class="w-full max-w-md rounded-2xl border border-frame bg-surface/90 px-8 py-12 text-center select-none"
                :class="isLast ? '' : 'cursor-pointer'"
                @click="isLast ? null : advance()"
            >
                <Transition name="slide-fade" mode="out-in">
                    <div :key="current.key" class="space-y-5">
                        <div class="flex justify-center" aria-hidden="true">
                            <PortalLogo v-if="current.icon === 'arch'" :size="72" :show-text="false" />

                            <svg
                                v-else
                                viewBox="0 0 48 48"
                                class="w-14 h-14"
                                fill="none"
                                stroke="#C9A24B"
                                stroke-width="1.6"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <template v-if="current.icon === 'shield'">
                                    <path d="M24 5 L40 11 V24 C40 33 33 40 24 43 C15 40 8 33 8 24 V11 Z" />
                                    <path d="M17 24 L22 29 L31 19" />
                                </template>

                                <template v-else-if="current.icon === 'coin'">
                                    <circle cx="24" cy="24" r="16" />
                                    <circle cx="24" cy="24" r="10" />
                                    <path d="M24 18 V30 M21 21 H27 M21 27 H27" />
                                </template>

                                <template v-else-if="current.icon === 'gift'">
                                    <rect x="8" y="20" width="32" height="20" rx="2" />
                                    <path d="M6 14 H42 V20 H6 Z M24 14 V40" />
                                    <path d="M24 14 C24 14 20 6 15 8 C11 10 14 14 24 14 Z" />
                                    <path d="M24 14 C24 14 28 6 33 8 C37 10 34 14 24 14 Z" />
                                </template>
                            </svg>
                        </div>

                        <h2 id="onboarding-title" class="font-serif text-2xl text-cream">
                            {{ current.title }}
                        </h2>

                        <p v-if="current.body" class="text-sm leading-relaxed text-muted">
                            {{ current.body }}
                        </p>

                        <div v-if="isLast" class="pt-2">
                            <button
                                type="button"
                                class="rounded-full bg-gold px-8 py-3 text-sm tracking-wide text-background hover:bg-gold-light transition-colors"
                                @click.stop="dismiss"
                            >
                                Entrar no Portal →
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Dots -->
                <div class="flex justify-center gap-2 pt-10">
                    <button
                        v-for="(slide, i) in slides"
                        :key="slide.key"
                        type="button"
                        class="h-1.5 rounded-full transition-all"
                        :class="i === index ? 'w-6 bg-gold' : 'w-1.5 bg-frame hover:bg-muted/50'"
                        :aria-label="`Ir para o slide ${i + 1}`"
                        :aria-current="i === index"
                        @click.stop="goTo(i)"
                    />
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.slide-fade-enter-active,
.slide-fade-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.slide-fade-enter-from {
    opacity: 0;
    transform: translateX(12px);
}
.slide-fade-leave-to {
    opacity: 0;
    transform: translateX(-12px);
}
</style>
