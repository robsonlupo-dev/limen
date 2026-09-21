<script setup>
import { Link } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'

/**
 * "Minhas ações" (feat/moderation-queues-hub): histórico read-only das ações do
 * moderador logado, lido do audit no servidor. Sem PII do denunciante — só o
 * tipo de alvo, o id e o motivo que o próprio moderador escreveu.
 */
defineProps({
    actions: { type: Array, default: () => [] },
})

const ACTION_LABELS = {
    'moderation.report_reviewed': 'Fechou denúncia',
    'moderator.warned': 'Advertiu',
    'moderator.suspended': 'Suspendeu',
    'moderator.escalated': 'Escalou ao admin',
    'member_nickname_removed_by_moderator': 'Removeu apelido',
}
function actionBadge(a) {
    return {
        'moderator.suspended': 'border-danger/50 bg-danger/10 text-danger',
        'moderator.escalated': 'border-gold/50 bg-gold/10 text-gold',
    }[a] ?? 'border-frame/70 bg-background/50 text-cream/80'
}

const SUBJECT_LABELS = {
    Report: 'Denúncia',
    User: 'Usuário',
    performer: 'Perfil',
    message: 'Mensagem',
    performer_story: 'Story',
    member_photo: 'Foto do membro',
    performer_content: 'Conteúdo',
}
function subjectLabel(t) {
    return SUBJECT_LABELS[t] ?? t
}
// Ação sobre uma denúncia → dá pra abrir a denúncia; sobre usuário, não há tela.
function reportHref(a) {
    return ['moderation.report_reviewed', 'moderator.escalated'].includes(a.action)
        ? route('moderacao.reports.show', a.subject_id)
        : null
}

function fmtDateTime(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <ModeratorLayout title="Moderação · Minhas ações">
        <div class="mx-auto max-w-2xl space-y-6 px-4 py-8 sm:px-6 sm:py-10">
            <div class="space-y-1">
                <h1 class="font-serif text-2xl text-cream sm:text-3xl">Minhas ações</h1>
                <p class="text-sm text-muted">As suas últimas ações de moderação. Somente leitura.</p>
            </div>

            <div
                v-if="actions.length === 0"
                class="rounded-xl border border-frame/60 bg-surface/30 py-16 text-center text-sm text-muted"
            >
                Você ainda não registrou ações de moderação.
            </div>

            <ul v-else class="overflow-hidden rounded-xl border border-frame/60 divide-y divide-frame/40">
                <li v-for="a in actions" :key="a.id" class="flex items-start justify-between gap-3 bg-surface/30 px-4 py-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border px-2.5 py-0.5 text-xs" :class="actionBadge(a.action)">
                                {{ ACTION_LABELS[a.action] ?? a.action }}
                            </span>
                            <span class="text-sm text-cream/80">
                                {{ subjectLabel(a.subject_type) }}
                                <span class="text-muted/60">#{{ a.subject_id }}</span>
                            </span>
                            <span v-if="a.days" class="text-xs text-muted">· {{ a.days }} dia{{ a.days === 1 ? '' : 's' }}</span>
                        </div>
                        <p v-if="a.reason" class="truncate text-xs text-muted">"{{ a.reason }}"</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-xs text-muted/70">{{ fmtDateTime(a.created_at) }}</p>
                        <Link
                            v-if="reportHref(a)"
                            :href="reportHref(a)"
                            class="text-xs text-gold/80 no-underline hover:text-gold"
                        >abrir →</Link>
                    </div>
                </li>
            </ul>
        </div>
    </ModeratorLayout>
</template>
