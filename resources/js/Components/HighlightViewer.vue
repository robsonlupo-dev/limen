<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

// Visualizador de uma coleção de Destaques (roadmap social, Onda 1a). Tela cheia,
// avança por toque/tecla; barras de progresso simples. Sem autoplay agressivo —
// respeita prefers-reduced-motion (sem transições animadas).
const props = defineProps({
    collection: { type: Object, required: true }, // { title, items:[{id,url}] }
})
const emit = defineEmits(['close'])

const index = ref(0)
const items = computed(() => props.collection.items ?? [])
const current = computed(() => items.value[index.value] ?? null)

function next() {
    if (index.value < items.value.length - 1) index.value++
    else emit('close')
}
function prev() {
    if (index.value > 0) index.value--
}
function onKey(e) {
    if (e.key === 'Escape') emit('close')
    else if (e.key === 'ArrowRight') next()
    else if (e.key === 'ArrowLeft') prev()
}

// Reinicia ao trocar de coleção.
watch(() => props.collection, () => (index.value = 0))

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
    <div class="fixed inset-0 z-50 flex flex-col bg-black/95" role="dialog" aria-modal="true" :aria-label="collection.title">
        <!-- Barras de progresso -->
        <div class="flex gap-1 px-3 pt-3">
            <span
                v-for="(it, i) in items"
                :key="it.id"
                class="h-0.5 flex-1 rounded-full"
                :class="i <= index ? 'bg-white' : 'bg-white/30'"
            />
        </div>

        <div class="flex items-center justify-between px-4 py-2 text-white">
            <span class="truncate text-sm font-medium">{{ collection.title }}</span>
            <button type="button" class="min-h-[44px] px-2 text-2xl leading-none" aria-label="Fechar" @click="emit('close')">✕</button>
        </div>

        <!-- Imagem + zonas de toque -->
        <div class="relative flex-1 overflow-hidden">
            <img v-if="current" :src="current.url" :alt="collection.title" class="h-full w-full object-contain" />
            <button type="button" class="absolute inset-y-0 left-0 w-1/3" aria-label="Anterior" @click="prev" />
            <button type="button" class="absolute inset-y-0 right-0 w-2/3" aria-label="Próximo" @click="next" />
        </div>
    </div>
</template>
