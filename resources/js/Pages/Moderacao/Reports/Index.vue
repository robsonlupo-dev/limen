<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * Fila de moderação de denúncias (Sprint 13).
 *
 * Tela do moderador (ou admin) — SEM os poderes de admin. Mostra a denúncia
 * pseudonimizada (o denunciante nunca aparece cru) e não renderiza o conteúdo
 * denunciado em si: o visualizador de prova retida é o próximo passo do backlog.
 */
const props = defineProps({
    reports: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    types: { type: Array, required: true },
    pendingCount: { type: Number, required: true },
})

const STATUS_LABELS = {
    pending: 'Pendentes',
    reviewed: 'Revisadas',
    resolved: 'Resolvidas',
    dismissed: 'Descartadas',
    all: 'Todas',
}

const TYPE_LABELS = {
    performer: 'Perfil',
    message: 'Mensagem',
    performer_story: 'Story',
    member_photo: 'Foto do membro',
}

const REASON_LABELS = {
    underage_content: 'Conteúdo com menor',
    non_consensual: 'Não consensual',
    coercion: 'Coerção',
    impersonation: 'Falsidade ideológica',
    spam: 'Spam',
    other: 'Outro',
}

const STATUS_BADGE = {
    pending: 'border-gold/60 text-gold',
    reviewed: 'border-sky-500/50 text-sky-300',
    resolved: 'border-success/50 text-success',
    dismissed: 'border-frame text-muted',
}

function reasonLabel(reason) {
    return REASON_LABELS[reason] ?? reason
}
function typeLabel(type) {
    return TYPE_LABELS[type] ?? type
}

const activeStatus = computed(() => props.filters.status)
const activeType = computed(() => props.filters.type)

function statusHref(status) {
    return route('moderacao.reports.index', {
        status,
        ...(activeType.value ? { type: activeType.value } : {}),
    })
}
function typeHref(type) {
    return route('moderacao.reports.index', {
        status: activeStatus.value,
        ...(type ? { type } : {}),
    })
}
</script>

<template>
    <AppLayout title="Moderação · Denúncias">
        <div class="max-w-5xl mx-auto px-6 py-10 space-y-6">
            <div class="space-y-1">
                <h1 class="font-serif text-3xl text-cream">Denúncias</h1>
                <p class="text-sm text-muted">
                    Fila de moderação · {{ pendingCount }} pendente{{ pendingCount === 1 ? '' : 's' }}.
                    O denunciante aparece pseudonimizado — o id real fica na tabela.
                </p>
            </div>

            <!-- Filtro por status -->
            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="status in statuses"
                    :key="status"
                    :href="statusHref(status)"
                    :class="[
                        'rounded-lg border px-3 py-1.5 text-sm no-underline transition-colors',
                        activeStatus === status
                            ? 'border-gold text-gold'
                            : 'border-frame text-muted hover:text-cream',
                    ]"
                >
                    {{ STATUS_LABELS[status] ?? status }}
                </Link>
            </div>

            <!-- Filtro por tipo de alvo -->
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-muted/70">Tipo:</span>
                <Link
                    :href="typeHref(null)"
                    :class="[
                        'rounded-full border px-3 py-1 no-underline transition-colors',
                        !activeType ? 'border-gold text-gold' : 'border-frame text-muted hover:text-cream',
                    ]"
                >
                    Todos
                </Link>
                <Link
                    v-for="type in types"
                    :key="type"
                    :href="typeHref(type)"
                    :class="[
                        'rounded-full border px-3 py-1 no-underline transition-colors',
                        activeType === type ? 'border-gold text-gold' : 'border-frame text-muted hover:text-cream',
                    ]"
                >
                    {{ typeLabel(type) }}
                </Link>
            </div>

            <!-- Empty -->
            <div
                v-if="reports.data.length === 0"
                class="rounded-xl border border-frame/60 bg-surface/30 py-16 text-center text-sm text-muted"
            >
                Nenhuma denúncia neste filtro.
            </div>

            <!-- Lista -->
            <div v-else class="overflow-hidden rounded-xl border border-frame/60">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface/40 text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-4 py-3 font-medium">#</th>
                            <th class="px-4 py-3 font-medium">Denunciante</th>
                            <th class="px-4 py-3 font-medium">Alvo</th>
                            <th class="px-4 py-3 font-medium">Motivo</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="report in reports.data"
                            :key="report.id"
                            class="border-t border-frame/40 transition-colors hover:bg-surface/20"
                        >
                            <td class="px-4 py-3 text-muted">{{ report.id }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-cream/90">{{ report.reporter }}</td>
                            <td class="px-4 py-3 text-cream/90">
                                {{ typeLabel(report.target_type) }}
                                <span class="text-muted/60">#{{ report.target_id }}</span>
                            </td>
                            <td class="px-4 py-3 text-cream/90">{{ reasonLabel(report.reason) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    :class="[
                                        'inline-block rounded-full border px-2.5 py-0.5 text-xs',
                                        STATUS_BADGE[report.status] ?? 'border-frame text-muted',
                                    ]"
                                >
                                    {{ STATUS_LABELS[report.status] ?? report.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('moderacao.reports.show', report.id)"
                                    class="text-xs text-gold/80 no-underline hover:text-gold"
                                >
                                    Abrir →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <div v-if="reports.links && reports.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-2">
                <template v-for="(link, i) in reports.links" :key="i">
                    <span
                        v-if="!link.url"
                        class="px-3 py-1.5 text-sm text-muted/50"
                        v-html="link.label"
                    />
                    <Link
                        v-else
                        :href="link.url"
                        preserve-scroll
                        :class="[
                            'px-3 py-1.5 rounded-lg text-sm transition-colors',
                            link.active ? 'bg-gold text-background' : 'text-muted hover:text-cream border border-frame',
                        ]"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
