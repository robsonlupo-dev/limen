<script setup>
/**
 * Lightbox de fotos em tela cheia (feat/member-profile-v2). Serve a variante
 * COMPLETA (sem corte) da foto — object-contain sobre fundo escuro, para a foto
 * inteira aparecer sem cabeça/pé cortado (a miniatura enquadrada é o outro
 * caminho). Navegação ←/→ entre as fotos, fechar com X ou Esc.
 *
 * Contrato: o pai controla o ciclo por `index` (v-model) — um inteiro abre no
 * item, `null` fecha. As URLs já vêm assinadas e chaveadas em token opaco do
 * servidor (o componente não sabe de token/id).
 */
import { computed, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
    // [{ id, url, full_url }] — full_url é o que o lightbox mostra (cai em url).
    photos: { type: Array, default: () => [] },
    // Índice aberto, ou null quando fechado.
    index: { type: Number, default: null },
})

const emit = defineEmits(['update:index'])

const isOpen = computed(() => props.index !== null && props.photos.length > 0)
const current = computed(() =>
    isOpen.value ? props.photos[props.index] : null,
)
const currentSrc = computed(() => current.value?.full_url ?? current.value?.url ?? null)

function close() {
    emit('update:index', null)
}

function go(delta) {
    if (!isOpen.value) return
    const n = props.photos.length
    emit('update:index', (props.index + delta + n) % n)
}

function onKey(e) {
    if (!isOpen.value) return
    if (e.key === 'Escape') close()
    else if (e.key === 'ArrowRight') go(1)
    else if (e.key === 'ArrowLeft') go(-1)
}

// Trava o scroll do fundo enquanto aberto; escuta o teclado só quando aberto.
watch(
    isOpen,
    (open) => {
        if (typeof document === 'undefined') return
        if (open) {
            document.addEventListener('keydown', onKey)
            document.body.style.overflow = 'hidden'
        } else {
            document.removeEventListener('keydown', onKey)
            document.body.style.overflow = ''
        }
    },
)

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return
    document.removeEventListener('keydown', onKey)
    document.body.style.overflow = ''
})
</script>

<template>
    <Teleport to="body">
        <transition name="lb-fade">
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[9600] flex items-center justify-center bg-black/95"
                role="dialog"
                aria-modal="true"
                aria-label="Foto ampliada"
                @click.self="close"
            >
                <!-- Fechar (X) -->
                <button
                    type="button"
                    aria-label="Fechar"
                    class="absolute top-3 right-3 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20"
                    @click="close"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>

                <!-- Anterior -->
                <button
                    v-if="photos.length > 1"
                    type="button"
                    aria-label="Foto anterior"
                    class="absolute left-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:left-4"
                    @click.stop="go(-1)"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 18l-6-6 6-6" />
                    </svg>
                </button>

                <!-- Foto COMPLETA — object-contain, nunca cortada. -->
                <img
                    v-if="currentSrc"
                    :key="current.id"
                    :src="currentSrc"
                    alt="Foto do membro"
                    class="max-h-[90vh] max-w-[92vw] object-contain select-none"
                    @click.stop
                />

                <!-- Próxima -->
                <button
                    v-if="photos.length > 1"
                    type="button"
                    aria-label="Próxima foto"
                    class="absolute right-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:right-4"
                    @click.stop="go(1)"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 18l6-6-6-6" />
                    </svg>
                </button>

                <!-- Contador discreto. -->
                <span
                    v-if="photos.length > 1"
                    class="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-white/10 px-3 py-1 text-xs text-white/80"
                >{{ index + 1 }} / {{ photos.length }}</span>
            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.lb-fade-enter-active,
.lb-fade-leave-active {
    transition: opacity 0.2s ease;
}
.lb-fade-enter-from,
.lb-fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .lb-fade-enter-active,
    .lb-fade-leave-active {
        transition: none;
    }
}
</style>
