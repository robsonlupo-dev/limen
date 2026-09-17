<script setup>
import { ref, onMounted, nextTick } from 'vue'

// Texto longo do perfil com corte e "ler mais" (feat/performer-profile-redesign,
// item 9). Corta em ~4 linhas e só mostra o toggle quando o texto REALMENTE
// transborda (mede scrollHeight vs clientHeight) — texto curto não ganha um botão
// inútil. Preserva quebras de linha do original (whitespace-pre-line).
const props = defineProps({
    title: { type: String, required: true },
    text: { type: String, required: true },
})

const expanded = ref(false)
const clampable = ref(false)
const body = ref(null)

onMounted(async () => {
    await nextTick()
    const el = body.value
    // Com o clamp aplicado, transborda? Então vale oferecer "ler mais".
    if (el) clampable.value = el.scrollHeight - el.clientHeight > 4
})
</script>

<template>
    <section class="space-y-2">
        <h3 class="font-serif text-xl text-limen-ink">{{ title }}</h3>
        <p
            ref="body"
            class="whitespace-pre-line leading-relaxed text-limen-ink-soft"
            :class="{ 'line-clamp-4': !expanded }"
        >{{ text }}</p>
        <button
            v-if="clampable"
            type="button"
            class="mi-press text-sm font-medium text-limen-gold transition-colors hover:text-limen-gold/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/50"
            :aria-expanded="expanded"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Ler menos' : 'Ler mais' }}
        </button>
    </section>
</template>
