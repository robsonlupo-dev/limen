<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { loadCities, searchCities } from '@/lib/brazilianCities'

// Autocomplete de município (IBGE), client-side. Reusável:
//  - no filtro do catálogo (sem `uf` → busca o Brasil inteiro; emite a UF junto
//    para o pai desambiguar homônimos);
//  - no editor de localização da performer (com `uf` da linha → sugestões só
//    daquela UF).
//
// A base do IBGE é buscada UMA vez por fetch relativo e memoizada (ver
// @/lib/brazilianCities). A cidade é NOME livre por baixo: o autocomplete só
// sugere valores canônicos, não trava o campo.

const props = defineProps({
    modelValue: { type: String, default: '' },
    // Restringe as sugestões a uma UF (editor de localização). null = Brasil todo.
    uf: { type: String, default: null },
    placeholder: { type: String, default: 'Digite a cidade…' },
    ariaLabel: { type: String, default: 'Cidade' },
    inputId: { type: String, default: null },
    minChars: { type: Number, default: 3 },
})

const emit = defineEmits(['update:modelValue', 'select'])

const query = ref(props.modelValue ?? '')
const suggestions = ref([])
const openList = ref(false)
const activeIndex = ref(-1)
const rootEl = ref(null)

// O pai pode repor o valor (busca salva, reset): reflete no input sem reabrir a
// lista.
watch(
    () => props.modelValue,
    (val) => {
        if ((val ?? '') !== query.value) query.value = val ?? ''
    },
)

function refresh() {
    suggestions.value = searchCities(query.value, {
        uf: props.uf,
        minChars: props.minChars,
    })
    activeIndex.value = -1
    openList.value = suggestions.value.length > 0
}

async function onInput() {
    emit('update:modelValue', query.value)
    // Garante a base carregada antes da primeira busca; loadCities é memoizado.
    await loadCities()
    refresh()
}

function choose(city) {
    query.value = city.name
    emit('update:modelValue', city.name)
    emit('select', { name: city.name, uf: city.uf })
    openList.value = false
    activeIndex.value = -1
}

function onKeydown(e) {
    if (!openList.value || suggestions.value.length === 0) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value + 1) % suggestions.value.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value - 1 + suggestions.value.length) % suggestions.value.length
    } else if (e.key === 'Enter') {
        if (activeIndex.value >= 0) {
            e.preventDefault()
            choose(suggestions.value[activeIndex.value])
        }
    } else if (e.key === 'Escape') {
        openList.value = false
    }
}

function onClickOutside(e) {
    if (rootEl.value && !rootEl.value.contains(e.target)) openList.value = false
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', onClickOutside)
    onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))
}
</script>

<template>
    <div ref="rootEl" class="relative">
        <input
            :id="inputId"
            v-model="query"
            type="text"
            maxlength="120"
            autocomplete="off"
            :aria-label="ariaLabel"
            :placeholder="placeholder"
            class="w-full rounded-lg border border-frame bg-surface px-3 py-2 text-sm text-cream focus:outline-none focus:border-gold"
            @input="onInput"
            @focus="query.length >= minChars && refresh()"
            @keydown="onKeydown"
        />
        <ul
            v-if="openList"
            class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-frame bg-surface py-1 shadow-lg"
            role="listbox"
        >
            <li
                v-for="(city, i) in suggestions"
                :key="`${city.name}-${city.uf}`"
                role="option"
                :aria-selected="i === activeIndex"
                class="cursor-pointer px-3 py-1.5 text-sm text-cream"
                :class="i === activeIndex ? 'bg-gold/15' : 'hover:bg-frame/60'"
                @mousedown.prevent="choose(city)"
                @mouseenter="activeIndex = i"
            >
                {{ city.name }}<span class="text-muted">, {{ city.uf }}</span>
            </li>
        </ul>
    </div>
</template>
