<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * Detalhe de uma denúncia + ações do moderador.
 *
 * O moderador fecha o caso (revisada / resolvida / descartada) com nota
 * opcional. NÃO há botão de banir/suspender aqui: isso é poder de admin, em
 * /admin/*. O conteúdo denunciado (foto/mensagem/story) não é renderizado —
 * fica para o visualizador de prova retida (próximo item do backlog).
 */
const props = defineProps({
    report: { type: Object, required: true },
})

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
const STATUS_LABELS = {
    pending: 'Pendente',
    reviewed: 'Revisada',
    resolved: 'Resolvida',
    dismissed: 'Descartada',
}

const form = useForm({
    status: props.report.status === 'pending' ? 'reviewed' : props.report.status,
    moderator_notes: props.report.moderator_notes ?? '',
})

function submit() {
    form.patch(route('moderacao.reports.update', props.report.id), {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout title="Moderação · Denúncia">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div>
                <Link
                    :href="route('moderacao.reports.index')"
                    class="text-xs text-muted no-underline hover:text-cream"
                >
                    ← Voltar para a fila
                </Link>
                <h1 class="mt-3 font-serif text-3xl text-cream">Denúncia #{{ report.id }}</h1>
            </div>

            <!-- Dados da denúncia -->
            <dl class="grid grid-cols-3 gap-y-4 rounded-xl border border-frame/60 bg-surface/30 p-6 text-sm">
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
                <dd class="col-span-2 whitespace-pre-line text-cream/80">
                    {{ report.details || '—' }}
                </dd>

                <dt class="text-muted">Status atual</dt>
                <dd class="col-span-2 text-cream/90">{{ STATUS_LABELS[report.status] ?? report.status }}</dd>
            </dl>

            <!-- Ações do moderador -->
            <form class="space-y-5 rounded-xl border border-frame/60 bg-surface/30 p-6" @submit.prevent="submit">
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

                <!-- Fronteira explícita: o moderador NÃO bane. -->
                <p class="text-xs text-muted/70">
                    Ban e suspensão de conta são ações de admin. Para escalar, encaminhe ao admin.
                </p>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-gold px-4 py-2 text-sm text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
