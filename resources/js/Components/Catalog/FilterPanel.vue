<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import {
    FILTERABLE_TAG_GROUPS,
    LANGUAGES,
    DRINKS,
    SMOKES,
    TIERS,
    HEIGHT_MIN_CM,
    HEIGHT_MAX_CM,
} from '@/lib/performerAttributes'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    // As duas portas do catálogo usam o MESMO painel: o autenticado (/catalogo)
    // e o público (/performers). Só o nome da rota e o parâmetro do mundo
    // mudam — o público usa `mundo`, que é URL já indexada. Duplicar o
    // componente faria a faceta nova nascer numa tela só.
    routeName: { type: String, default: 'catalog' },
    // Parâmetros que não são faceta e precisam sobreviver a cada apply
    // (ex.: { mundo: 'mulheres' } no catálogo público).
    baseParams: { type: Object, default: () => ({}) },
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
    has_photo: !!props.filters.has_photo,
    level: props.filters.level || '',
    tier: props.filters.tier || '',
    sort: props.filters.sort || 'rating_avg',
    tags: [...(props.filters.tags ?? [])],
    languages: [...(props.filters.languages ?? [])],
    drinks: props.filters.drinks || '',
    smokes: props.filters.smokes || '',
    height_min: Number(props.filters.height_min ?? HEIGHT_MIN_CM),
    height_max: Number(props.filters.height_max ?? HEIGHT_MAX_CM),
})

// Seções colapsáveis. Abertas por padrão só as que o membro mexe primeiro —
// com dez facetas abertas de uma vez a gaveta vira um muro de controles.
const sections = reactive({
    disponibilidade: true,
    tags: true,
    tier: false,
    nivel: false,
    habitos: false,
    idiomas: false,
    altura: false,
    ordenar: false,
})

function toggleSection(key) {
    sections[key] = !sections[key]
}

// A faixa de altura só conta como filtro quando foi ESTREITADA — é a mesma
// regra do servidor (PerformerCatalogService::applyFilters). Sem isso o slider
// intocado mandaria 140–190 a cada request e descartaria toda performer que não
// declarou altura.
const heightNarrowed = computed(
    () => form.height_min > HEIGHT_MIN_CM || form.height_max < HEIGHT_MAX_CM,
)

function apply() {
    router.get(
        route(props.routeName),
        {
            ...props.baseParams,
            // category/mundo ficam de fora: o mundo é escolhido noutro controle.
            search: form.search || undefined,
            is_live: form.is_live ? 1 : undefined,
            has_photo: form.has_photo ? 1 : undefined,
            level: form.level || undefined,
            tier: form.tier || undefined,
            sort: form.sort !== 'rating_avg' ? form.sort : undefined,
            tags: form.tags.length ? form.tags : undefined,
            languages: form.languages.length ? form.languages : undefined,
            drinks: form.drinks || undefined,
            smokes: form.smokes || undefined,
            height_min: heightNarrowed.value ? form.height_min : undefined,
            height_max: heightNarrowed.value ? form.height_max : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

let searchTimeout = null

function onSearchInput() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(apply, 400)
}

function toggleIn(list, value) {
    const i = form[list].indexOf(value)
    if (i === -1) form[list].push(value)
    else form[list].splice(i, 1)
    apply()
}

// Radio que aceita desmarcar: clicar na opção já ativa limpa o campo. Sem isso
// não haveria como voltar a "tanto faz" depois do primeiro clique — e "tanto
// faz" é o estado padrão de toda faceta.
function toggleChoice(field, value) {
    form[field] = form[field] === value ? '' : value
    apply()
}

// Os dois extremos não podem se cruzar: arrastar o mínimo acima do máximo
// devolveria uma faixa vazia e zero resultados sem explicação na tela.
function onHeightMin() {
    if (form.height_min > form.height_max) form.height_max = form.height_min
    apply()
}

function onHeightMax() {
    if (form.height_max < form.height_min) form.height_min = form.height_max
    apply()
}

function clearFilters() {
    Object.assign(form, {
        search: '',
        is_live: false,
        has_photo: false,
        level: '',
        tier: '',
        sort: 'rating_avg',
        tags: [],
        languages: [],
        drinks: '',
        smokes: '',
        height_min: HEIGHT_MIN_CM,
        height_max: HEIGHT_MAX_CM,
    })
    apply()
}

const activeCount = computed(() => {
    let n = 0
    if (form.search) n++
    if (form.is_live) n++
    if (form.has_photo) n++
    if (form.level) n++
    if (form.tier) n++
    if (form.sort !== 'rating_avg') n++
    if (form.drinks) n++
    if (form.smokes) n++
    if (heightNarrowed.value) n++
    n += form.tags.length
    n += form.languages.length
    return n
})
</script>

<template>
    <div class="flex items-center gap-3">
        <!-- Search stays visible -->
        <input
            v-model="form.search"
            type="search"
            placeholder="Buscar por nome ou bio..."
            class="w-full sm:w-64 rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream placeholder:text-muted focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
            @input="onSearchInput"
        />

        <!-- Filters toggle -->
        <div class="relative ml-auto">
            <button
                type="button"
                :aria-expanded="open"
                class="inline-flex items-center gap-2 rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream hover:border-gold/50 transition-colors"
                @click="open = !open"
            >
                ⚙ Filtros
                <span v-if="activeCount" class="rounded-full bg-gold text-background text-xs px-1.5 py-0.5">
                    {{ activeCount }}
                </span>
            </button>

            <!-- Backdrop: só no mobile, onde o painel vira bottom sheet e
                 precisa de uma área de toque para fechar. -->
            <div v-if="open" class="fixed inset-0 z-20 bg-black/50 sm:hidden" @click="open = false" />

            <!-- Painel. Bottom sheet no mobile (a gaveta tem dez seções e não
                 cabe num dropdown de 288px numa tela de telefone), dropdown
                 ancorado no desktop. max-h + scroll nos dois. -->
            <div
                v-if="open"
                class="fixed inset-x-0 bottom-0 z-30 max-h-[80vh] overflow-y-auto rounded-t-2xl border-t border-frame bg-surface-2 p-4 shadow-xl
                       sm:absolute sm:inset-auto sm:right-0 sm:bottom-auto sm:mt-2 sm:w-96 sm:max-h-[70vh] sm:rounded-xl sm:border"
            >
                <!-- Disponibilidade -->
                <section class="border-b border-frame pb-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('disponibilidade')">
                        <span class="text-xs uppercase tracking-wide text-muted">Disponibilidade</span>
                        <span class="text-muted text-xs">{{ sections.disponibilidade ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.disponibilidade" class="space-y-2 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-cream">
                            <input v-model="form.is_live" type="checkbox" class="h-4 w-4 rounded border-frame bg-surface accent-gold" @change="apply" />
                            ☉ Ao vivo agora
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-cream">
                            <input v-model="form.has_photo" type="checkbox" class="h-4 w-4 rounded border-frame bg-surface accent-gold" @change="apply" />
                            ▣ Com foto de perfil
                        </label>
                    </div>
                </section>

                <!-- Tags -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('tags')">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            Interesses
                            <span v-if="form.tags.length" class="text-gold">({{ form.tags.length }})</span>
                        </span>
                        <span class="text-muted text-xs">{{ sections.tags ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.tags" class="space-y-3 pt-2">
                        <!-- A copy diz a semântica: marcar mais tags ESTREITA o
                             resultado. Sem isso o membro marca cinco, recebe
                             zero e conclui que o catálogo está vazio. -->
                        <p class="text-xs text-muted">Mostra quem tem todas as marcadas.</p>
                        <div v-for="group in FILTERABLE_TAG_GROUPS" :key="group.key" class="space-y-1.5">
                            <p class="text-[11px] uppercase tracking-wide text-muted/70">{{ group.label }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="tag in group.tags"
                                    :key="tag.value"
                                    type="button"
                                    :aria-pressed="form.tags.includes(tag.value)"
                                    class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                                    :class="form.tags.includes(tag.value)
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface text-cream hover:border-gold/50'"
                                    @click="toggleIn('tags', tag.value)"
                                >
                                    {{ tag.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Curadoria -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('tier')">
                        <span class="text-xs uppercase tracking-wide text-muted">Curadoria</span>
                        <span class="text-muted text-xs">{{ sections.tier ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.tier" class="flex flex-wrap gap-1.5 pt-2">
                        <button
                            v-for="t in TIERS"
                            :key="t.value"
                            type="button"
                            :aria-pressed="form.tier === t.value"
                            class="rounded-full border px-3 py-1 text-xs transition-colors"
                            :class="form.tier === t.value
                                ? 'border-gold bg-gold/10 text-gold'
                                : 'border-frame bg-surface text-cream hover:border-gold/50'"
                            @click="toggleChoice('tier', t.value)"
                        >
                            {{ t.label }}
                        </button>
                    </div>
                </section>

                <!-- Nível -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('nivel')">
                        <span class="text-xs uppercase tracking-wide text-muted">Nível</span>
                        <span class="text-muted text-xs">{{ sections.nivel ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.nivel" class="pt-2">
                        <select
                            v-model="form.level"
                            class="w-full rounded-lg border border-frame bg-surface px-3 py-2 text-sm text-cream focus:outline-none focus:border-gold"
                            @change="apply"
                        >
                            <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option>
                        </select>
                    </div>
                </section>

                <!-- Hábitos -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('habitos')">
                        <span class="text-xs uppercase tracking-wide text-muted">Hábitos</span>
                        <span class="text-muted text-xs">{{ sections.habitos ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.habitos" class="space-y-3 pt-2">
                        <div class="space-y-1.5">
                            <p class="text-[11px] uppercase tracking-wide text-muted/70">Bebida</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="d in DRINKS"
                                    :key="d.value"
                                    type="button"
                                    :aria-pressed="form.drinks === d.value"
                                    class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                                    :class="form.drinks === d.value
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface text-cream hover:border-gold/50'"
                                    @click="toggleChoice('drinks', d.value)"
                                >
                                    {{ d.label }}
                                </button>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <p class="text-[11px] uppercase tracking-wide text-muted/70">Fumo</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="s in SMOKES"
                                    :key="s.value"
                                    type="button"
                                    :aria-pressed="form.smokes === s.value"
                                    class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                                    :class="form.smokes === s.value
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface text-cream hover:border-gold/50'"
                                    @click="toggleChoice('smokes', s.value)"
                                >
                                    {{ s.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Idiomas -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('idiomas')">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            Idiomas
                            <span v-if="form.languages.length" class="text-gold">({{ form.languages.length }})</span>
                        </span>
                        <span class="text-muted text-xs">{{ sections.idiomas ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.idiomas" class="space-y-2 pt-2">
                        <!-- OR, ao contrário das tags: idioma é canal de
                             conversa, então marcar mais AMPLIA o resultado. -->
                        <p class="text-xs text-muted">Mostra quem fala pelo menos um dos marcados.</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="l in LANGUAGES"
                                :key="l.value"
                                type="button"
                                :aria-pressed="form.languages.includes(l.value)"
                                class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                                :class="form.languages.includes(l.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface text-cream hover:border-gold/50'"
                                @click="toggleIn('languages', l.value)"
                            >
                                {{ l.label }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Altura -->
                <section class="border-b border-frame py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('altura')">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            Altura
                            <span v-if="heightNarrowed" class="text-gold">({{ form.height_min }}–{{ form.height_max }} cm)</span>
                        </span>
                        <span class="text-muted text-xs">{{ sections.altura ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.altura" class="space-y-3 pt-3">
                        <div class="flex items-center justify-between text-xs text-muted tabular-nums">
                            <span>{{ form.height_min }} cm</span>
                            <span>{{ form.height_max }} cm</span>
                        </div>
                        <label class="block space-y-1">
                            <span class="text-[11px] text-muted/70">Mínima</span>
                            <input
                                v-model.number="form.height_min"
                                type="range"
                                :min="HEIGHT_MIN_CM"
                                :max="HEIGHT_MAX_CM"
                                class="w-full accent-gold"
                                @change="onHeightMin"
                            />
                        </label>
                        <label class="block space-y-1">
                            <span class="text-[11px] text-muted/70">Máxima</span>
                            <input
                                v-model.number="form.height_max"
                                type="range"
                                :min="HEIGHT_MIN_CM"
                                :max="HEIGHT_MAX_CM"
                                class="w-full accent-gold"
                                @change="onHeightMax"
                            />
                        </label>
                        <!-- A ressalva é do produto, não da implementação: o
                             campo é opcional e a maioria não preencheu. -->
                        <p v-if="heightNarrowed" class="text-xs text-muted">
                            Quem não informou a altura fica de fora deste filtro.
                        </p>
                    </div>
                </section>

                <!-- Ordenar -->
                <section class="py-3">
                    <button type="button" class="flex w-full items-center justify-between py-1" @click="toggleSection('ordenar')">
                        <span class="text-xs uppercase tracking-wide text-muted">Ordenar por</span>
                        <span class="text-muted text-xs">{{ sections.ordenar ? '−' : '+' }}</span>
                    </button>
                    <div v-if="sections.ordenar" class="pt-2">
                        <select
                            v-model="form.sort"
                            class="w-full rounded-lg border border-frame bg-surface px-3 py-2 text-sm text-cream focus:outline-none focus:border-gold"
                            @change="apply"
                        >
                            <option v-for="s in sorts" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                </section>

                <div class="sticky bottom-0 -mx-4 -mb-4 flex justify-between border-t border-frame bg-surface-2 px-4 py-3">
                    <button type="button" class="text-xs text-muted hover:text-gold transition-colors" @click="clearFilters">
                        Limpar {{ activeCount ? `(${activeCount})` : '' }}
                    </button>
                    <button type="button" class="text-xs text-gold hover:text-gold-light transition-colors" @click="open = false">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
