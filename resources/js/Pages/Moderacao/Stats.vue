<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'

/**
 * Estatísticas de moderação (Fase 5). Read-only. Tudo derivado do que já é
 * registrado (denúncias, flags, audit) — sem coluna nova. A taxa de reversão é
 * ESTIMADA (decisão do PO): não há ação de "reverter" de primeira classe, então
 * é inferida dos rastros; a tela deixa isso explícito com o rótulo "estimada".
 */
const props = defineProps({
    dias: { type: Number, default: 30 },
    metrics: { type: Object, required: true },
})

const PERIODS = [7, 30, 90]
const m = computed(() => props.metrics)

// "2,5 h" / "18 min" / "—". Separador pt-BR.
function fmtHours(h) {
    if (h === null || h === undefined) return '—'
    if (h < 1) return `${Math.round(h * 60)} min`
    return `${h.toFixed(1).replace('.', ',')} h`
}
function fmtPct(v) {
    return v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}%`
}

// Barras de "ações por moderador": largura relativa ao maior.
const maxActions = computed(() => Math.max(1, ...m.value.by_moderator.map((x) => x.count)))
</script>

<template>
    <ModeratorLayout title="Moderação · Estatísticas">
        <div class="mx-auto max-w-4xl space-y-6 px-6 py-10">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="font-serif text-3xl text-cream">Estatísticas</h1>
                    <p class="text-sm text-muted">Desempenho da moderação nos últimos {{ dias }} dias.</p>
                </div>
                <!-- Seletor de período. -->
                <div class="flex items-center gap-1 rounded-lg border border-frame/70 bg-background/40 p-1">
                    <Link
                        v-for="p in PERIODS"
                        :key="p"
                        :href="route('moderacao.estatisticas', { dias: p })"
                        preserve-scroll
                        :class="[
                            'rounded-md px-3 py-1.5 text-sm no-underline transition-colors',
                            p === dias ? 'bg-gold text-background' : 'text-muted hover:text-cream',
                        ]"
                    >{{ p }} dias</Link>
                </div>
            </div>

            <!-- KPIs principais. -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border border-frame/60 bg-surface p-4">
                    <p class="text-2xl font-semibold text-cream">{{ fmtHours(m.avg_resolution_hours) }}</p>
                    <p class="mt-1 text-xs text-muted">Tempo médio de resolução<span class="block text-muted/70">{{ m.resolved }} denúncia(s) fechada(s)</span></p>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface p-4">
                    <p class="text-2xl font-semibold text-cream">{{ fmtPct(m.sla_pct) }}</p>
                    <p class="mt-1 text-xs text-muted">Dentro do SLA<span class="block text-muted/70">fechadas no prazo da prioridade</span></p>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface p-4">
                    <p class="text-2xl font-semibold text-cream">{{ m.received }}<span class="text-base text-muted"> / {{ m.resolved }}</span></p>
                    <p class="mt-1 text-xs text-muted">Recebidas / resolvidas</p>
                </div>
                <div class="rounded-xl border border-gold/40 bg-gold/5 p-4">
                    <div class="flex items-center gap-1.5">
                        <p class="text-2xl font-semibold text-cream">{{ fmtPct(m.reversal.exact_pct) }}</p>
                        <span class="rounded-full border border-gold/50 px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-gold">exata</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">Taxa de reversão<span class="block text-muted/70">{{ m.reversal.reopened }} reaberta(s) de {{ m.reversal.decisions }} decisão(ões)</span></p>
                </div>
            </div>

            <!-- Volume por prioridade + flags. -->
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-frame/60 bg-surface p-5">
                    <h2 class="font-serif text-lg text-cream">Denúncias recebidas por prioridade</h2>
                    <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-danger">Urgente</dt>
                            <dd class="text-xl font-semibold text-cream">{{ m.received_by_priority.urgent }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-gold">Alta</dt>
                            <dd class="text-xl font-semibold text-cream">{{ m.received_by_priority.high }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-muted">Normal</dt>
                            <dd class="text-xl font-semibold text-cream">{{ m.received_by_priority.normal }}</dd>
                        </div>
                    </dl>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface p-5">
                    <h2 class="font-serif text-lg text-cream">Conteúdo sinalizado</h2>
                    <p class="mt-3 text-2xl font-semibold text-cream">{{ fmtHours(m.flag_avg_resolution_hours) }}</p>
                    <p class="mt-1 text-xs text-muted">Tempo médio até dispensar um flag automático.</p>
                </div>
            </div>

            <!-- Ações por moderador. -->
            <div class="rounded-xl border border-frame/60 bg-surface p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-serif text-lg text-cream">Ações por moderador</h2>
                    <span class="text-xs text-muted">{{ m.total_actions }} no período</span>
                </div>
                <ul v-if="m.by_moderator.length" class="mt-4 space-y-2.5">
                    <li v-for="row in m.by_moderator" :key="row.name" class="flex items-center gap-3">
                        <span class="w-40 shrink-0 truncate text-sm text-cream/90">{{ row.name }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-background/60">
                            <span class="block h-full rounded-full bg-gold" :style="{ width: (row.count / maxActions * 100) + '%' }"></span>
                        </span>
                        <span class="w-8 shrink-0 text-right text-sm tabular-nums text-muted">{{ row.count }}</span>
                    </li>
                </ul>
                <p v-else class="mt-4 text-sm text-muted">Nenhuma ação de moderação no período.</p>
            </div>

            <!-- Como a reversão é medida (transparência). -->
            <div class="rounded-xl border border-frame/60 bg-background/30 p-5 text-sm text-muted">
                <h2 class="font-serif text-base text-cream">Sobre a taxa de reversão</h2>
                <p class="mt-2">
                    A taxa principal agora é <strong class="text-cream/80">exata</strong>: vem da ação de
                    <strong class="text-cream/80">reabrir denúncia</strong> — uma reversão explícita e auditada, que amarra quem reabriu à decisão desfeita.
                </p>
                <ul class="mt-2 space-y-1">
                    <li>• Reaberturas registradas no período: <strong class="text-cream/80">{{ m.reversal.reopened }}</strong> sobre <strong class="text-cream/80">{{ m.reversal.decisions }}</strong> decisão(ões) fechada(s).</li>
                </ul>
                <p class="mt-3 border-t border-frame/40 pt-3 text-muted/80">
                    <strong class="text-cream/70">Estimativa (histórico):</strong>
                    {{ fmtPct(m.reversal.pct) }} — usada antes da ação de reabrir existir, inferida de rastros:
                    {{ m.reversal.redecided_reports }} de {{ m.reversal.reviewed_reports }} denúncias re-decididas e
                    {{ m.reversal.reactivated }} de {{ m.reversal.suspensions }} suspensões reativadas. Não amarra quem reverteu à ação original; fica como referência para o período anterior.
                </p>
            </div>
        </div>
    </ModeratorLayout>
</template>
