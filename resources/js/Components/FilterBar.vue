<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
})

const categories = [
    { value: '', label: 'Todas' },
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
    { value: 'gls', label: 'GLS' },
    { value: 'swing', label: 'Swing' },
]

const sorts = [
    { value: 'rating_avg', label: 'Mais bem avaliados' },
    { value: 'followers_count', label: 'Mais seguidos' },
    { value: 'newest', label: 'Mais recentes' },
]

const form = reactive({
    category: props.filters.category || '',
    is_live: !!props.filters.is_live,
    search: props.filters.search || '',
    sort: props.filters.sort || 'rating_avg',
})

let searchTimeout = null

function applyFilters() {
    router.get(
        route('catalog'),
        {
            category: form.category || undefined,
            is_live: form.is_live ? 1 : undefined,
            search: form.search || undefined,
            sort: form.sort !== 'rating_avg' ? form.sort : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function selectCategory(value) {
    form.category = value
    applyFilters()
}

function toggleLive() {
    form.is_live = !form.is_live
    applyFilters()
}

function onSearchInput() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 400)
}

function onSortChange() {
    applyFilters()
}

function clearFilters() {
    form.category = ''
    form.is_live = false
    form.search = ''
    form.sort = 'rating_avg'
    applyFilters()
}

const hasActiveFilters = computed(() => form.category || form.is_live || form.search || form.sort !== 'rating_avg')
</script>

<template>
    <div class="bg-surface-2 border border-frame rounded-xl p-4 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
            <!-- Search -->
            <input
                v-model="form.search"
                type="search"
                placeholder="Buscar por nome..."
                class="w-full lg:w-64 rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream placeholder:text-muted focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
                @input="onSearchInput"
            />

            <!-- Category pills -->
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="cat in categories"
                    :key="cat.value"
                    type="button"
                    :class="[
                        'rounded-full px-3.5 py-1.5 text-xs font-medium tracking-wide transition-colors',
                        form.category === cat.value
                            ? 'bg-gold text-background'
                            : 'bg-surface border border-frame text-muted hover:text-cream hover:border-gold/40',
                    ]"
                    @click="selectCategory(cat.value)"
                >
                    {{ cat.label }}
                </button>
            </div>

            <!-- Live toggle -->
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-medium tracking-wide transition-colors',
                    form.is_live
                        ? 'bg-gold text-background'
                        : 'bg-surface border border-frame text-muted hover:text-cream hover:border-gold/40',
                ]"
                @click="toggleLive"
            >
                Ao vivo
            </button>

            <!-- Sort -->
            <select
                v-model="form.sort"
                class="ml-auto rounded-lg border border-frame bg-surface px-3 py-2.5 text-sm text-cream focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
                @change="onSortChange"
            >
                <option v-for="s in sorts" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>

        <div v-if="hasActiveFilters" class="flex justify-end">
            <button type="button" class="text-xs text-muted hover:text-gold transition-colors" @click="clearFilters">
                Limpar filtros
            </button>
        </div>
    </div>
</template>
