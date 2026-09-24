<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'

/**
 * Detalhe de uma denúncia — a TELA DE TRABALHO do moderador (Fase 2).
 *
 * Reúne num só lugar: os dados da denúncia, o VISUALIZADOR DA PROVA RETIDA
 * (inline, sem download, acesso auditado), as AÇÕES sobre o alvo
 * (feat/moderator-actions: advertir / suspender temporário / escalar ao admin —
 * o ban permanente segue exclusivo do admin), o encaminhamento da denúncia
 * (revisada/resolvida/descartada + nota) e o contexto da fila (próximos +
 * rodapé de stats).
 *
 * A seção "Evidência":
 *  - foto efêmera / story / conteúdo → <img> inline (zoom em tela cheia), SEM download;
 *  - mensagem → o corpo, revelado sob clique (o fetch dispara o audit de quem viu);
 *  - prova expirada (bytes recolhidos pelo GC) → hash + "expirado".
 *
 * ⚠️ A foto do membro mostra o ROSTO — é PII sensível. O acesso é auditado no
 * servidor; não há botão de baixar em lugar nenhum.
 */
const props = defineProps({
    report: { type: Object, required: true },
    evidence: { type: Object, required: true },
    queue: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
})

const TYPE_LABELS = {
    performer: 'Perfil',
    message: 'Mensagem',
    performer_story: 'Story',
    member_photo: 'Foto do membro',
    performer_content: 'Conteúdo',
    member_nickname: 'Apelido',
}
const REASON_LABELS = {
    underage_content: 'Conteúdo com menor',
    non_consensual: 'Não consensual',
    coercion: 'Coerção',
    impersonation: 'Falsidade ideológica',
    spam: 'Spam',
    other: 'Outro',
}
const STATUS_LABELS = {
    pending: 'Pendente',
    reviewed: 'Revisada',
    resolved: 'Resolvida',
    dismissed: 'Descartada',
}
const PRIORITY_LABELS = {
    urgent: 'Urgente',
    high: 'Alta',
    normal: 'Normal',
}
function priorityBadge(p) {
    return {
        urgent: 'border-danger/50 bg-danger/10 text-danger',
        high: 'border-gold/50 bg-gold/10 text-gold',
        normal: 'border-frame/70 bg-background/50 text-cream/70',
    }[p] ?? 'border-frame/70 bg-background/50 text-cream/70'
}

// Texto do SLA a partir do alvo (sla_due_at): "vence em Xh" ou "atrasada há Y".
function slaText(r) {
    if (!r.sla_due_at) return null
    const diffMs = new Date(r.sla_due_at).getTime() - Date.now()
    const abs = Math.abs(diffMs)
    const h = Math.floor(abs / 3_600_000)
    const m = Math.floor((abs % 3_600_000) / 60_000)
    const label = h >= 24 ? `${Math.floor(h / 24)}d ${h % 24}h` : (h >= 1 ? `${h}h ${m}min` : `${m}min`)
    return diffMs >= 0 ? `vence em ${label}` : `atrasada há ${label}`
}

function fmtDateTime(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
function fmtDay(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleDateString('pt-BR', {
        timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric',
    })
}

// ── Encaminhamento (fecha a denúncia) ────────────────────────────────────────
const form = useForm({
    status: props.report.status === 'pending' ? 'reviewed' : props.report.status,
    moderator_notes: props.report.moderator_notes ?? '',
})
function submit() {
    form.patch(route('moderacao.reports.update', props.report.id), { preserveScroll: true })
}

// ── Ações sobre o alvo (feat/moderator-actions) ──────────────────────────────
// Um painel aberto por vez (acordeão). O alvo é resolvido no servidor a partir
// do conteúdo denunciado — a tela nunca manda id de usuário. O flash de sucesso
// aparece na faixa do ModeratorLayout.
const openAction = ref(null)
function toggle(name) {
    openAction.value = openAction.value === name ? null : name
}

const warnForm = useForm({ reason: '' })
function submitWarn() {
    warnForm.post(route('moderacao.reports.warn', props.report.id), {
        preserveScroll: true,
        onSuccess: () => { warnForm.reset(); openAction.value = null },
    })
}

const suspendForm = useForm({ reason: '', days: 7 })
function submitSuspend() {
    suspendForm.post(route('moderacao.reports.suspend', props.report.id), {
        preserveScroll: true,
        onSuccess: () => { suspendForm.reset(); openAction.value = null },
    })
}

const escalateForm = useForm({ reason: '' })
function submitEscalate() {
    escalateForm.post(route('moderacao.reports.escalate', props.report.id), {
        preserveScroll: true,
        onSuccess: () => { escalateForm.reset(); openAction.value = null },
    })
}

// ── Reverter decisão (feat/moderation-reversal-action) ───────────────────────
// Reabre uma denúncia JÁ decidida e a devolve à fila como pendente. É a reversão
// de primeira classe — auditada, e a estatística de reversão exata deriva dela.
// O motivo é obrigatório; o servidor barra reabrir o que não está fechado.
const reopenForm = useForm({ reason: '' })
function submitReopen() {
    reopenForm.post(route('moderacao.reports.reopen', props.report.id), {
        preserveScroll: true,
        onSuccess: () => reopenForm.reset(),
    })
}

// ── Prova retida ─────────────────────────────────────────────────────────────
const zoomed = ref(false)
const messageBody = ref(null)
const messageError = ref(false)
const messageLoading = ref(false)

async function revealMessage() {
    messageLoading.value = true
    messageError.value = false
    try {
        const res = await fetch(props.evidence.url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
        if (!res.ok) throw new Error('failed')
        messageBody.value = (await res.json()).body
    } catch {
        messageError.value = true
    } finally {
        messageLoading.value = false
    }
}
</script>

<template>
    <ModeratorLayout title="Moderação · Denúncia">
        <div class="mx-auto max-w-2xl space-y-6 px-4 py-8 sm:px-6 sm:py-10">
            <!-- Cabeçalho -->
            <div>
                <Link
                    :href="route('moderacao.reports.index')"
                    class="text-xs text-muted no-underline hover:text-cream"
                >
                    ← Voltar para a fila
                </Link>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <h1 class="font-serif text-2xl text-cream sm:text-3xl">Denúncia #{{ report.id }}</h1>
                    <span class="rounded-full border border-frame/70 bg-background/50 px-2.5 py-0.5 text-xs text-cream/80">
                        {{ STATUS_LABELS[report.status] ?? report.status }}
                    </span>
                    <span class="rounded-full border px-2.5 py-0.5 text-xs" :class="priorityBadge(report.priority)">
                        {{ PRIORITY_LABELS[report.priority] ?? report.priority }}
                    </span>
                    <span
                        v-if="slaText(report)"
                        class="rounded-full border px-2.5 py-0.5 text-xs"
                        :class="report.overdue ? 'border-danger/50 bg-danger/10 text-danger' : 'border-frame/70 bg-background/50 text-muted'"
                    >{{ slaText(report) }}</span>
                    <span
                        v-if="report.escalated_at"
                        class="rounded-full border border-gold/50 bg-gold/10 px-2.5 py-0.5 text-xs text-gold"
                    >Escalada ao admin</span>
                </div>
            </div>

            <!-- Dados da denúncia -->
            <dl class="grid grid-cols-3 gap-y-4 rounded-xl border border-frame/60 bg-surface/30 p-5 text-sm sm:p-6">
                <dt class="text-muted">Denunciante</dt>
                <dd class="col-span-2 font-mono text-xs text-cream/90">{{ report.reporter }}</dd>

                <dt class="text-muted">Alvo</dt>
                <dd class="col-span-2 text-cream/90">
                    {{ TYPE_LABELS[report.target_type] ?? report.target_type }}
                    <span class="text-muted/60">#{{ report.target_id }}</span>
                </dd>

                <dt class="text-muted">Motivo</dt>
                <dd class="col-span-2 text-cream/90">{{ REASON_LABELS[report.reason] ?? report.reason }}</dd>

                <dt class="text-muted">Detalhes</dt>
                <dd class="col-span-2 whitespace-pre-line text-cream/80">{{ report.details || '—' }}</dd>

                <dt class="text-muted">Aberta em</dt>
                <dd class="col-span-2 text-cream/80">{{ fmtDateTime(report.created_at) }}</dd>
            </dl>

            <!-- Prova retida -->
            <section class="space-y-4 rounded-xl border border-frame/60 bg-surface/30 p-5 sm:p-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-serif text-lg text-cream">Evidência</h2>
                    <span class="text-xs text-muted/60">sem download · acesso auditado</span>
                </div>

                <template v-if="evidence.kind === 'image'">
                    <template v-if="evidence.available">
                        <p v-if="report.target_type === 'member_photo'" class="text-xs text-danger/90">
                            ⚠️ Foto do membro — mostra o rosto. Conteúdo sensível; sua visualização fica registrada.
                        </p>
                        <button
                            type="button"
                            class="block w-full overflow-hidden rounded-lg border border-frame bg-background p-0"
                            @click="zoomed = true"
                        >
                            <img
                                :src="evidence.url"
                                alt="Conteúdo denunciado"
                                class="mx-auto max-h-96 w-auto cursor-zoom-in object-contain"
                            />
                        </button>
                        <p class="text-xs text-muted/70">Clique na imagem para ampliar.</p>
                    </template>
                    <div v-else class="space-y-2 rounded-lg border border-frame/60 bg-background/40 p-4">
                        <p class="text-sm text-muted">Evidência não disponível — conteúdo expirado.</p>
                        <p v-if="evidence.content_hash" class="break-all font-mono text-xs text-muted/70">
                            SHA-256: {{ evidence.content_hash }}
                        </p>
                    </div>
                </template>

                <template v-else-if="evidence.kind === 'text'">
                    <template v-if="evidence.available">
                        <button
                            v-if="messageBody === null && !messageError"
                            type="button"
                            :disabled="messageLoading"
                            class="rounded-lg border border-frame bg-background px-4 py-2 text-sm text-cream transition-opacity hover:opacity-90 disabled:opacity-50"
                            @click="revealMessage"
                        >
                            {{ messageLoading ? 'Carregando…' : 'Ver conteúdo da mensagem' }}
                        </button>
                        <p
                            v-else-if="messageBody !== null"
                            class="whitespace-pre-line rounded-lg border border-frame/60 bg-background/40 p-4 text-sm text-cream/90"
                        >{{ messageBody }}</p>
                        <p v-if="messageError" class="text-sm text-danger">
                            Não foi possível carregar a mensagem — pode ter expirado.
                        </p>
                    </template>
                    <p v-else class="text-sm text-muted">Evidência não disponível — mensagem removida.</p>
                </template>

                <p v-else class="text-sm text-muted">Sem prova retida para este tipo de denúncia.</p>
            </section>

            <!-- Zoom em tela cheia -->
            <div
                v-if="zoomed"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-6"
                @click="zoomed = false"
            >
                <img :src="evidence.url" alt="Conteúdo denunciado" class="max-h-full max-w-full object-contain" />
                <button
                    type="button"
                    class="absolute right-6 top-6 rounded-full bg-surface/80 px-3 py-1 text-sm text-cream"
                    @click="zoomed = false"
                >
                    Fechar ✕
                </button>
            </div>

            <!-- Ações sobre o alvo -->
            <section class="space-y-3 rounded-xl border border-frame/60 bg-surface/30 p-5 sm:p-6">
                <div>
                    <h2 class="font-serif text-lg text-cream">Ações sobre o alvo</h2>
                    <p class="mt-1 text-xs text-muted/70">
                        O ban permanente é do admin — para isso, escale. O alvo é resolvido a partir do conteúdo denunciado.
                    </p>
                </div>

                <!-- Advertir -->
                <div class="rounded-lg border border-frame/60 bg-background/30">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm text-cream"
                        @click="toggle('warn')"
                    >
                        <span class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-cream/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Advertir
                        </span>
                        <span class="text-muted/60">{{ openAction === 'warn' ? '−' : '+' }}</span>
                    </button>
                    <form v-if="openAction === 'warn'" class="space-y-3 border-t border-frame/50 px-4 py-4" @submit.prevent="submitWarn">
                        <label class="block text-xs text-muted">Motivo da advertência</label>
                        <textarea
                            v-model="warnForm.reason"
                            rows="3"
                            maxlength="500"
                            placeholder="O que o alvo fez de errado?"
                            class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                        />
                        <p v-if="warnForm.errors.reason" class="text-xs text-danger">{{ warnForm.errors.reason }}</p>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="warnForm.processing"
                                class="rounded-lg border border-frame bg-background px-4 py-2 text-sm text-cream transition-opacity hover:opacity-90 disabled:opacity-50"
                            >Registrar advertência</button>
                        </div>
                    </form>
                </div>

                <!-- Suspender -->
                <div class="rounded-lg border border-frame/60 bg-background/30">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm text-cream"
                        @click="toggle('suspend')"
                    >
                        <span class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-danger/80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" /><path d="M9 9v6m6-6v6" stroke-linecap="round" />
                            </svg>
                            Suspender temporário
                        </span>
                        <span class="text-muted/60">{{ openAction === 'suspend' ? '−' : '+' }}</span>
                    </button>
                    <form v-if="openAction === 'suspend'" class="space-y-3 border-t border-frame/50 px-4 py-4" @submit.prevent="submitSuspend">
                        <div class="space-y-2">
                            <label class="block text-xs text-muted">Dias de suspensão (1–90)</label>
                            <input
                                v-model.number="suspendForm.days"
                                type="number"
                                min="1"
                                max="90"
                                class="w-24 rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                            />
                            <p v-if="suspendForm.errors.days" class="text-xs text-danger">{{ suspendForm.errors.days }}</p>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs text-muted">Motivo da suspensão</label>
                            <textarea
                                v-model="suspendForm.reason"
                                rows="3"
                                maxlength="500"
                                placeholder="Por que suspender, e por quanto tempo?"
                                class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                            />
                            <p v-if="suspendForm.errors.reason" class="text-xs text-danger">{{ suspendForm.errors.reason }}</p>
                        </div>
                        <p class="text-xs text-muted/70">
                            A conta cai da sessão viva na hora e reativa sozinha quando o prazo passa.
                        </p>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="suspendForm.processing"
                                class="rounded-lg bg-danger/90 px-4 py-2 text-sm text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                            >Suspender</button>
                        </div>
                    </form>
                </div>

                <!-- Escalar -->
                <div class="rounded-lg border border-frame/60 bg-background/30">
                    <button
                        type="button"
                        :disabled="!!report.escalated_at"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm text-cream disabled:opacity-60"
                        @click="toggle('escalate')"
                    >
                        <span class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="M12 19V5m0 0-6 6m6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ report.escalated_at ? 'Escalada ao admin' : 'Escalar ao admin (ban)' }}
                        </span>
                        <span v-if="!report.escalated_at" class="text-muted/60">{{ openAction === 'escalate' ? '−' : '+' }}</span>
                    </button>
                    <form v-if="openAction === 'escalate' && !report.escalated_at" class="space-y-3 border-t border-frame/50 px-4 py-4" @submit.prevent="submitEscalate">
                        <label class="block text-xs text-muted">Por que isto merece o admin?</label>
                        <textarea
                            v-model="escalateForm.reason"
                            rows="3"
                            maxlength="500"
                            placeholder="Recomendação ao admin (ex.: ban permanente por conteúdo ilegal)."
                            class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                        />
                        <p v-if="escalateForm.errors.reason" class="text-xs text-danger">{{ escalateForm.errors.reason }}</p>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="escalateForm.processing"
                                class="rounded-lg bg-gold px-4 py-2 text-sm text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                            >Escalar ao admin</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Encaminhamento (fecha a denúncia) -->
            <form class="space-y-5 rounded-xl border border-frame/60 bg-surface/30 p-5 sm:p-6" @submit.prevent="submit">
                <h2 class="font-serif text-lg text-cream">Encaminhamento</h2>

                <div class="space-y-2">
                    <label class="block text-sm text-muted">Marcar como</label>
                    <select
                        v-model="form.status"
                        class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                    >
                        <option value="reviewed">Revisada</option>
                        <option value="resolved">Resolvida (ação tomada)</option>
                        <option value="dismissed">Descartada</option>
                    </select>
                    <p v-if="form.errors.status" class="text-xs text-danger">{{ form.errors.status }}</p>
                </div>

                <div class="space-y-2">
                    <label class="block text-sm text-muted">Nota do moderador (opcional)</label>
                    <textarea
                        v-model="form.moderator_notes"
                        rows="4"
                        maxlength="2000"
                        placeholder="Por que este caso foi fechado assim?"
                        class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                    />
                    <p v-if="form.errors.moderator_notes" class="text-xs text-danger">{{ form.errors.moderator_notes }}</p>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-gold px-4 py-2 text-sm text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                    >Salvar</button>
                </div>
            </form>

            <!-- Reverter decisão (feat/moderation-reversal-action) -->
            <section v-if="report.can_reopen" class="space-y-3 rounded-xl border border-gold/40 bg-gold/5 p-5 sm:p-6">
                <div>
                    <h2 class="flex items-center gap-2 font-serif text-lg text-cream">
                        <svg class="h-5 w-5 text-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="M3 3v6h6" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M3.5 9a9 9 0 1 1-1.2 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Reverter decisão
                    </h2>
                    <p class="mt-1 text-xs text-muted/70">
                        Reabre esta denúncia decidida e a devolve à fila como pendente. Fica registrado como reversão (auditado); o motivo é obrigatório.
                    </p>
                </div>
                <form class="space-y-3" @submit.prevent="submitReopen">
                    <textarea
                        v-model="reopenForm.reason"
                        rows="3"
                        maxlength="500"
                        required
                        placeholder="Por que a decisão está sendo revertida?"
                        class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                    />
                    <p v-if="reopenForm.errors.reason" class="text-xs text-danger">{{ reopenForm.errors.reason }}</p>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="reopenForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg border border-gold/50 bg-gold/10 px-4 py-2 text-sm text-gold transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="M3 3v6h6" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M3.5 9a9 9 0 1 1-1.2 6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Reabrir denúncia
                        </button>
                    </div>
                </form>
            </section>

            <!-- Próximos na fila -->
            <section v-if="queue.length" class="space-y-3 rounded-xl border border-frame/60 bg-surface/30 p-5 sm:p-6">
                <h2 class="font-serif text-lg text-cream">Próximos na fila</h2>
                <ul class="divide-y divide-frame/40">
                    <li v-for="item in queue" :key="item.id">
                        <Link
                            :href="route('moderacao.reports.show', item.id)"
                            class="flex items-center justify-between gap-3 py-3 no-underline transition-opacity hover:opacity-80"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] uppercase tracking-wide"
                                    :class="priorityBadge(item.priority)"
                                >{{ PRIORITY_LABELS[item.priority] ?? item.priority }}</span>
                                <span class="min-w-0">
                                    <span class="text-sm text-cream/90">#{{ item.id }}</span>
                                    <span class="text-muted/60"> · </span>
                                    <span class="text-sm text-cream/80">{{ TYPE_LABELS[item.target_type] ?? item.target_type }}</span>
                                    <span class="block truncate text-xs text-muted">{{ REASON_LABELS[item.reason] ?? item.reason }}</span>
                                </span>
                            </span>
                            <span class="shrink-0 text-right text-xs">
                                <span v-if="item.overdue" class="block text-danger">atrasada</span>
                                <span class="text-muted/70">{{ fmtDay(item.created_at) }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Rodapé de stats -->
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded-xl border border-frame/60 bg-surface/30 p-4 text-center">
                    <p class="font-serif text-2xl text-cream">{{ stats.pending }}</p>
                    <p class="mt-1 text-xs text-muted">Pendentes</p>
                </div>
                <div
                    class="rounded-xl border bg-surface/30 p-4 text-center"
                    :class="stats.overdue > 0 ? 'border-danger/50' : 'border-frame/60'"
                >
                    <p class="font-serif text-2xl" :class="stats.overdue > 0 ? 'text-danger' : 'text-cream'">{{ stats.overdue }}</p>
                    <p class="mt-1 text-xs text-muted">Atrasadas</p>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface/30 p-4 text-center">
                    <p class="font-serif text-2xl text-cream">{{ stats.resolved_today }}</p>
                    <p class="mt-1 text-xs text-muted">Resolvidas hoje</p>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface/30 p-4 text-center">
                    <p class="font-serif text-2xl text-cream">{{ stats.escalated }}</p>
                    <p class="mt-1 text-xs text-muted">Escaladas</p>
                </div>
                <div class="rounded-xl border border-frame/60 bg-surface/30 p-4 text-center">
                    <p class="font-serif text-sm text-cream">{{ fmtDay(stats.oldest_pending_at) }}</p>
                    <p class="mt-1 text-xs text-muted">Mais antiga</p>
                </div>
            </section>
        </div>
    </ModeratorLayout>
</template>
