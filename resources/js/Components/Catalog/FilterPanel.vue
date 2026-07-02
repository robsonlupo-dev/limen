<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
})

const open = ref(false)

const levels = [
    { value: '', label: 'Todos os níveis' },
    { value: 'iniciante', label: '🥉 Iniciante' },
    { value: 'estrela', label: '🥈 Estrela' },
    { value: 'premium', label: '🥇 Premium' },
    { value: 'vip', label: '💎 VIP' },
]

const sorts = [
    { value: 'rating_avg', label: 'Mais bem avaliados' },
    { value: 'followers_count', label: 'Mais seguidos' },
    { value: 'newest', label: 'Mais recentes' },
]

const form = reactive({
    search: props.filters.search || '',
    is_live: !!props.filters.is_live,
    level: props.filters.level || '',
    sort: props.filters.sort || 'rating_avg',
})

let searchTimeout = null

function apply() {
    router.get(
        route('catalog'),
        {
            // category is intentionally omitted — the backend keeps the member
            // within their preferred world.
            search: form.search || undefined,
            is_live: form.is_live ? 1 : undefined,
            level: form.level || undefined,
            sort: form.sort !== 'rating_avg' ? form.sort : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function onSearchInput() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(apply, 400)
}

function clearFilters() {
    form.search = ''
    form.is_live = false
    form.level = ''
    form.sort = 'rating_avg'
    apply()
}

const activeCount = computed(() => {
    let n = 0
    if (form.search) n++
    if (form.is_live) n++
    if (form.level) n++
    if (form.sort !== 'rating_avg') n++
    return n
})
</script>

<template>
    <div class="flex items-center gap-3">
        <!-- Search stays visible -->
        <input
            v-model="form.search"
            type="search"
            placeholder="Buscar por nome..."
            class="w-full sm:w-64 rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream placeholder:text-muted focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
            @input="onSearchInput"
        />

        <!-- Filters toggle -->
        <div class="relative ml-auto">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream hover:border-gold/50 transition-colors"
                @click="open = !open"
            >
                ⚙ Filtros
                <span v-if="activeCount" class="rounded-full bg-gold text-background text-xs px-1.5 py-0.5">
                    {{ activeCount }}
                </span>
            </button>

            <!-- Dropdown -->
            <div
                v-if="open"
                class="absolute right-0 z-20 mt-2 w-72 rounded-xl border border-frame bg-surface-2 p-4 shadow-xl space-y-5"
            >
                <div>
                    <p class="text-xs uppercase tracking-wide text-muted mb-2">Disponibilidade</p>
                    <label class="flex items-center gap-2 cursor-pointer text-sm text-cream">
                        <input v-model="form.is_live" type="checkbox" class="h-4 w-4 rounded border-frame bg-surface accent-gold" @change="apply" />
                        ☉ Ao vivo agora
                    </label>
                </div>

                <div>
                    <p class="text-xs uppercase tracking-wide text-muted mb-2">Nível</p>
                    <select
                        v-model="form.level"
                        class="w-full rounded-lg border border-frame bg-surface px-3 py-2 text-sm text-cream focus:outline-none focus:border-gold"
                        @change="apply"
                    >
                        <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option>
                    </select>
                </div>

                <div>
                    <p class="text-xs uppercase tracking-wide text-muted mb-2">Ordenar por</p>
                    <select
                        v-model="form.sort"
                        class="w-full rounded-lg border border-frame bg-surface px-3 py-2 text-sm text-cream focus:outline-none focus:border-gold"
                        @change="apply"
                    >
                        <option v-for="s in sorts" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>

                <div class="flex justify-between pt-1 border-t border-frame">
                    <button type="button" class="text-xs text-muted hover:text-gold transition-colors pt-3" @click="clearFilters">
                        Limpar
                    </button>
                    <button type="button" class="text-xs text-gold hover:text-gold-light transition-colors pt-3" @click="open = false">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
