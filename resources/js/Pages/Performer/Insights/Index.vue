<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatTokens } from '@/lib/tokens'

// Insights da performer (roadmap social, Onda 3 §3.2). Painel SÓ-LEITURA e AGREGADO.
// PRESENÇA (visitas, seguidores, views de story) vem em FAIXA (banda) do servidor —
// nunca número exato, nunca "quem". Ações PAGAS (chat/compra) e ganhos são da própria
// performer e vêm exatos. Duas janelas (7/30).
const props = defineProps({
    insights: { type: Object, required: true },
    windows: { type: Array, default: () => [7, 30] },
})

const days = computed(() => props.insights.days)
const o = computed(() => props.insights.overview)
const funnel = computed(() => props.insights.funnel)
const earnings = computed(() => props.insights.earnings)
const periods = computed(() => props.insights.periods ?? { available: false, buckets: [] })

function setWindow(w) {
    if (w === days.value) return
    router.get(route('performer.insights'), { window: w }, { preserveScroll: true, replace: true })
}

// Barras de evolução: altura relativa ao máximo da série.
const maxDaily = computed(() => Math.max(1, ...earnings.value.daily.map((d) => d.tokens)))
const maxShare = computed(() => Math.max(1, ...periods.value.buckets.map((b) => b.share)))

function dayLabel(iso) {
    const [, m, d] = iso.split('-')
    return `${d}/${m}`
}
</script>

<template>
    <AppLayout title="Insights">
        <div class="bg-limen-bg min-h-screen">
            <div class="max-w-3xl mx-auto px-6 py-10 space-y-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="space-y-1">
                        <h1 class="font-serif text-4xl text-limen-ink">Insights</h1>
                        <p class="text-limen-ink-mute text-sm">
                            Visão do seu público — sempre em faixas, nunca "quem". Últimos {{ days }} dias.
                        </p>
                    </div>
                    <div class="inline-flex rounded-lg border border-limen-line overflow-hidden self-start">
                        <button
                            v-for="w in windows"
                            :key="w"
                            type="button"
                            class="px-4 py-2 text-sm transition-colors"
                            :class="w === days ? 'bg-limen-gold text-limen-bg' : 'text-limen-ink-mute hover:text-limen-ink'"
                            @click="setWindow(w)"
                        >
                            {{ w }} dias
                        </button>
                    </div>
                </div>

                <!-- Visão geral. Presença em FAIXA (string do servidor). -->
                <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                        <p class="text-2xl font-serif text-limen-ink">{{ o.unique_visitors }}</p>
                        <p class="text-xs text-limen-ink-mute mt-0.5">visitantes únicos</p>
                        <p class="text-[11px] text-limen-ink-mute/70">{{ o.visits }} visitas</p>
                    </div>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                        <p class="text-2xl font-serif text-limen-ink">{{ o.followers_total }}</p>
                        <p class="text-xs text-limen-ink-mute mt-0.5">seguidores</p>
                        <p class="text-[11px] text-limen-gold">+{{ o.followers_new }} no período</p>
                    </div>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                        <p class="text-2xl font-serif text-limen-ink">{{ o.story_views }}</p>
                        <p class="text-xs text-limen-ink-mute mt-0.5">views de stories</p>
                        <p class="text-[11px] text-limen-ink-mute/70">{{ o.story_viewers }} pessoas</p>
                    </div>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                        <p class="text-2xl font-serif text-limen-gold">{{ formatTokens(earnings.total) }}</p>
                        <p class="text-xs text-limen-ink-mute mt-0.5">tokens ganhos</p>
                        <p class="text-[11px] text-limen-ink-mute/70">no período</p>
                    </div>
                </section>

                <!-- Funil visita → chat → compra. Visitantes em faixa; ações pagas exatas;
                     taxas só com base suficiente (senão "—"). -->
                <section class="space-y-3">
                    <h2 class="font-serif text-xl text-limen-ink">Funil de conversão</h2>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-5 space-y-3">
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-limen-ink">Visitaram o perfil</span>
                                <span class="text-limen-ink-mute">{{ funnel.visited }}</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-limen-gold" />
                        </div>
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-limen-ink">Abriram chat</span>
                                <span class="text-limen-ink-mute">{{ funnel.chatted }} <span class="text-limen-ink-mute/60">· {{ funnel.chat_rate === null ? '—' : funnel.chat_rate + '%' }}</span></span>
                            </div>
                            <div class="h-2.5 rounded-full bg-limen-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-limen-gold transition-all" :style="{ width: (funnel.chat_rate ?? 0) + '%' }" />
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-limen-ink">Compraram conteúdo</span>
                                <span class="text-limen-ink-mute">{{ funnel.bought }} <span class="text-limen-ink-mute/60">· {{ funnel.buy_rate === null ? '—' : funnel.buy_rate + '%' }}</span></span>
                            </div>
                            <div class="h-2.5 rounded-full bg-limen-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-limen-gold transition-all" :style="{ width: (funnel.buy_rate ?? 0) + '%' }" />
                            </div>
                        </div>
                        <p v-if="funnel.chat_rate === null" class="text-[11px] text-limen-ink-mute/70 pt-1">
                            As taxas aparecem quando há visitas suficientes para preservar o anonimato.
                        </p>
                    </div>
                </section>

                <!-- Melhores horários: 4 faixas de 6h, só participação (%), só com volume. -->
                <section class="space-y-3">
                    <h2 class="font-serif text-xl text-limen-ink">Melhores horários</h2>
                    <p class="text-xs text-limen-ink-mute">Quando seu público visita o perfil (horário de Brasília).</p>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-5">
                        <div v-if="periods.available" class="space-y-3">
                            <div v-for="b in periods.buckets" :key="b.label">
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="text-limen-ink">{{ b.label }}</span>
                                    <span class="text-limen-ink-mute">{{ b.share }}%</span>
                                </div>
                                <div class="h-2.5 rounded-full bg-limen-surface-2 overflow-hidden">
                                    <div class="h-full rounded-full bg-limen-gold/70" :style="{ width: Math.round((b.share / maxShare) * 100) + '%' }" />
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-limen-ink-mute py-4 text-center">
                            Ainda não há visitas suficientes no período para mostrar a distribuição por horário.
                        </p>
                    </div>
                </section>

                <!-- Evolução de ganhos (diária) — receita da própria performer, exata. -->
                <section class="space-y-3">
                    <h2 class="font-serif text-xl text-limen-ink">Evolução de ganhos</h2>
                    <div class="rounded-2xl border border-limen-line bg-limen-surface p-5">
                        <div v-if="earnings.daily.length" class="flex items-end gap-[3px] h-32">
                            <div
                                v-for="d in earnings.daily"
                                :key="d.date"
                                class="flex-1 rounded-t bg-limen-gold/70 hover:bg-limen-gold transition-colors"
                                :style="{ height: Math.max(2, Math.round((d.tokens / maxDaily) * 100)) + '%' }"
                                :title="`${dayLabel(d.date)} — ${formatTokens(d.tokens)} tokens`"
                            />
                        </div>
                        <p v-else class="text-sm text-limen-ink-mute py-6 text-center">Sem ganhos no período.</p>
                    </div>
                </section>

                <p class="text-[11px] text-limen-ink-mute/70">
                    Números de público saem em faixas e o Limen nunca mostra quem visitou, viu, seguiu ou comprou.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
