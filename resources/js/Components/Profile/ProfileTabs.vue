<script setup>
import { ref } from 'vue'

// Abas do perfil (feat/performer-profile-redesign, item 6). Acabam com a rolagem
// quilométrica: Fotos · Sobre · Conteúdo, cada uma com contagem quando faz sentido.
// A dona única do "qual aba" é o pai (v-model); aqui é só a barra acessível
// (role=tablist, setas do teclado, foco visível). Os painéis o pai renderiza.
const props = defineProps({
    modelValue: { type: String, required: true },
    // [{ key, label, count? }] — count omitido não mostra pílula.
    tabs: { type: Array, required: true },
})

const emit = defineEmits(['update:modelValue'])

const tabRefs = ref([])

function select(key) {
    emit('update:modelValue', key)
}

// Setas do teclado percorrem as abas (padrão WAI-ARIA tabs).
function onKey(event, index) {
    const last = props.tabs.length - 1
    let next = null
    if (event.key === 'ArrowRight') next = index === last ? 0 : index + 1
    else if (event.key === 'ArrowLeft') next = index === 0 ? last : index - 1
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = last
    if (next === null) return
    event.preventDefault()
    select(props.tabs[next].key)
    tabRefs.value[next]?.focus()
}
</script>

<template>
    <div role="tablist" aria-label="Seções do perfil" class="flex gap-1 border-b border-limen-line">
        <button
            v-for="(tab, i) in tabs"
            :key="tab.key"
            :ref="(el) => (tabRefs[i] = el)"
            type="button"
            role="tab"
            :aria-selected="modelValue === tab.key"
            :tabindex="modelValue === tab.key ? 0 : -1"
            class="mi-press relative -mb-px inline-flex min-h-[44px] items-center gap-2 border-b-2 px-4 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/50"
            :class="modelValue === tab.key
                ? 'border-limen-gold text-limen-ink'
                : 'border-transparent text-limen-ink-mute hover:text-limen-ink'"
            @click="select(tab.key)"
            @keydown="onKey($event, i)"
        >
            {{ tab.label }}
            <span
                v-if="tab.count != null"
                class="rounded-full bg-limen-surface-2 px-2 py-0.5 text-xs tabular-nums"
                :class="modelValue === tab.key ? 'text-limen-gold' : 'text-limen-ink-mute'"
            >{{ tab.count }}</span>
        </button>
    </div>
</template>
