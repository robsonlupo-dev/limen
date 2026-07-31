<script setup>
import { computed, ref } from 'vue'

// Carrossel da galeria de fotos do perfil público (Sprint 10).
//
// Conteúdo PÚBLICO — cada foto já vem com a URL do serving público direto
// (route('performer.gallery.image', id)), sem paywall e sem depender de login,
// ao contrário do StoryStrip (que é gated). Avatar e capa NÃO entram aqui: são
// superfícies separadas, exibidas fora deste componente.
const props = defineProps({
    photos: { type: Array, default: () => [] },
    performerName: { type: String, default: '' },
})

const index = ref(0)

const hasPhotos = computed(() => props.photos.length > 0)
const current = computed(() => props.photos[index.value] ?? null)

function prev() {
    if (!hasPhotos.value) return
    index.value = (index.value - 1 + props.photos.length) % props.photos.length
}

function next() {
    if (!hasPhotos.value) return
    index.value = (index.value + 1) % props.photos.length
}

function go(i) {
    index.value = i
}

// Swipe em telas de toque. Sem dependência: mede o deslocamento horizontal no
// touchend e decide a direção acima de um limiar pequeno.
const touchStartX = ref(null)

function onTouchStart(event) {
    touchStartX.value = event.changedTouches[0].clientX
}

function onTouchEnd(event) {
    if (touchStartX.value === null) return
    const delta = event.changedTouches[0].clientX - touchStartX.value
    touchStartX.value = null
    if (Math.abs(delta) < 40) return
    delta < 0 ? next() : prev()
}
</script>

<template>
    <div v-if="hasPhotos" class="mt-8 space-y-3">
        <h2 class="font-serif text-xl text-cream">Fotos</h2>

        <div
            class="relative aspect-[4/3] overflow-hidden rounded-xl border border-frame bg-surface-2"
            @touchstart.passive="onTouchStart"
            @touchend.passive="onTouchEnd"
        >
            <img
                v-if="current"
                :src="current.url"
                :alt="`Foto de ${performerName}`"
                class="h-full w-full object-cover"
            />

            <!-- Setas de navegação, só com mais de uma foto. -->
            <template v-if="photos.length > 1">
                <button
                    type="button"
                    aria-label="Foto anterior"
                    class="absolute top-1/2 left-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-cream transition-colors hover:bg-black/70"
                    @click="prev"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button
                    type="button"
                    aria-label="Próxima foto"
                    class="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-cream transition-colors hover:bg-black/70"
                    @click="next"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                </button>

                <!-- Posição atual, discreta. -->
                <div class="absolute bottom-2 right-2 rounded-full bg-black/55 px-2 py-0.5 text-xs text-cream tabular-nums">
                    {{ index + 1 }}/{{ photos.length }}
                </div>
            </template>
        </div>

        <!-- Miniaturas: navegação direta e prévia da galeria inteira. -->
        <div v-if="photos.length > 1" class="flex flex-wrap gap-2">
            <button
                v-for="(photo, i) in photos"
                :key="photo.id"
                type="button"
                :aria-label="`Ver foto ${i + 1}`"
                :aria-current="i === index"
                class="h-14 w-14 overflow-hidden rounded-lg border transition-colors"
                :class="i === index ? 'border-gold' : 'border-frame opacity-70 hover:opacity-100'"
                @click="go(i)"
            >
                <img :src="photo.url" alt="" class="h-full w-full object-cover" />
            </button>
        </div>
    </div>
</template>
