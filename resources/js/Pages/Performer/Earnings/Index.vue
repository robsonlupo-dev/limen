<script setup>
import { computed, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    // Paginator: { data, links, current_page, last_page, total }
    entries: { type: Object, required: true },
    // { balance (raw 4dp string), balance_readable, payout_rate_per_token }
    summary: { type: Object, required: true },
    typeGroups: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ from: null, to: null, type: 'all' }) },
})

// Estado local dos filtros (aplicados via visita Inertia preservando o scroll).
const form = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    type: props.filters.type ?? 'all',
})

const chips = computed(() => [{ key: 'all', label: 'Tudo' }, ...props.typeGroups])

// Vírgula decimal pt-BR. Recebe "1.6000" (string 4dp) ou número.
function br(value) {
    return String(value).replace('.', ',')
}

// R$ equivalente do saldo (hint — o valor autoritativo do saque vive na tela de
// Saques). R$0,60/token fixo (M.13.5).
const balanceReais = computed(() => {
    const reais = parseFloat(props.summary.balance) * props.summary.payout_rate_per_token
    return reais.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
})

function applyFilters() {
    router.get(route('performer.earnings.index'), pruned(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

function setType(key) {
    form.type = key
    applyFilters()
}

function clearFilters() {
    form.from = ''
    form.to = ''
    form.type = 'all'
    applyFilters()
}

// Só manda o que está setado (URL limpa; 'all' e datas vazias saem fora).
function pruned() {
    const out = {}
    if (form.from) out.from = form.from
    if (form.to) out.to = form.to
    if (form.type && form.type !== 'all') out.type = form.type
    return out
}

const hasActiveFilter = computed(() => !!(form.from || form.to || (form.type && form.type !== 'all')))
</script>

<template>
    <AppLayout title="Extrato de ganhos">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8 space-y-8">
            <!-- Cabeçalho + saldo em destaque -->
            <header class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.28em] text-limen-gold/70">Ganhos</p>
                <h1 class="font-serif text-3xl text-limen-ink">Extrato</h1>
            </header>

            <!-- HERO: saldo atual com as casas decimais. O gold é o dinheiro dela. -->
            <section class="rounded-2xl border border-limen-line bg-limen-surface p-6">
                <p class="text-[11px] uppercase tracking-[0.2em] text-limen-ink-mute">Saldo atual</p>
                <p class="mt-1 font-serif text-4xl text-limen-gold tabular-nums">{{ br(summary.balance) }}</p>
                <p class="mt-1 text-sm text-limen-ink-soft">
                    ≈ {{ balanceReais }} no saque
                </p>
                <p class="mt-3 text-xs leading-relaxed text-limen-ink-mute">
                    Você recebe 80% dos tokens de cada transação. Cada token vale R$&nbsp;0,60 no saque.
                </p>
            </section>

            <!-- Filtros -->
            <section class="space-y-3">
                <!-- Tipo: pílulas roláveis (mobile primeiro) -->
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    <button
                        v-for="chip in chips"
                        :key="chip.key"
                        type="button"
                        :aria-pressed="form.type === chip.key"
                        class="mi-press inline-flex min-h-[44px] shrink-0 items-center rounded-full border px-4 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60"
                        :class="form.type === chip.key
                            ? 'border-limen-gold bg-limen-gold/15 text-limen-gold'
                            : 'border-limen-line bg-limen-surface text-limen-ink-soft hover:text-limen-ink'"
                        @click="setType(chip.key)"
                    >
                        {{ chip.label }}
                    </button>
                </div>

                <!-- Período -->
                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-xs text-limen-ink-mute">
                        De
                        <input
                            v-model="form.from"
                            type="date"
                            class="min-h-[44px] rounded-lg border border-limen-line bg-limen-surface px-3 text-sm text-limen-ink focus:border-limen-gold focus:outline-none focus:ring-1 focus:ring-limen-gold"
                            @change="applyFilters"
                        />
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-limen-ink-mute">
                        Até
                        <input
                            v-model="form.to"
                            type="date"
                            class="min-h-[44px] rounded-lg border border-limen-line bg-limen-surface px-3 text-sm text-limen-ink focus:border-limen-gold focus:outline-none focus:ring-1 focus:ring-limen-gold"
                            @change="applyFilters"
                        />
                    </label>
                    <button
                        v-if="hasActiveFilter"
                        type="button"
                        class="mi-press ml-auto inline-flex min-h-[44px] items-center rounded-lg border border-limen-line px-3 text-xs text-limen-ink-soft hover:text-limen-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60"
                        @click="clearFilters"
                    >
                        Limpar
                    </button>
                </div>
            </section>

            <!-- Lançamentos -->
            <section>
                <p v-if="entries.data.length === 0" class="rounded-2xl border border-limen-line bg-limen-surface p-8 text-center text-sm text-limen-ink-mute">
                    Nenhum ganho neste período. Assim que alguém abrir um chat, mandar
                    uma gorjeta ou desbloquear conteúdo seu, o crédito aparece aqui.
                </p>

                <ul v-else class="space-y-3">
                    <li
                        v-for="entry in entries.data"
                        :key="entry.id"
                        class="rounded-2xl border border-limen-line bg-limen-surface p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="rounded-full border border-limen-line bg-limen-surface-2 px-2.5 py-1 text-xs text-limen-ink-soft">
                                {{ entry.type_label }}
                            </span>
                            <time class="text-xs text-limen-ink-mute">{{ entry.created_at }}</time>
                        </div>

                        <!-- ASSINATURA: a conta do split, visível. Ela confere que o
                             líquido é a taxa aplicada sobre o que o membro pagou. -->
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <p class="text-sm text-limen-ink-mute tabular-nums">
                                <span v-if="entry.gross !== null">{{ entry.gross }} {{ entry.gross === 1 ? 'token' : 'tokens' }}</span>
                                <span v-if="entry.gross !== null" class="text-limen-ink-mute/70"> · {{ entry.applied_rate }}%</span>
                            </p>
                            <p class="font-serif text-2xl text-limen-gold tabular-nums leading-none">
                                +{{ br(entry.net) }}
                            </p>
                        </div>

                        <p class="mt-2 text-xs text-limen-ink-mute">{{ entry.member_alias }}</p>
                    </li>
                </ul>

                <!-- Paginação (mesmo padrão do extrato do membro) -->
                <nav v-if="entries.links && entries.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-6">
                    <template v-for="(link, i) in entries.links" :key="i">
                        <span
                            v-if="!link.url"
                            class="px-3 py-1.5 text-sm text-limen-ink-mute/50"
                            v-html="link.label"
                        />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            :class="[
                                'mi-press rounded-lg px-3 py-1.5 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60',
                                link.active
                                    ? 'bg-limen-gold text-limen-bg'
                                    : 'border border-limen-line text-limen-ink-soft hover:text-limen-ink',
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
