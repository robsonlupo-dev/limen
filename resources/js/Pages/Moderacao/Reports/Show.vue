<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * Detalhe de uma denúncia + ações do moderador + VISUALIZADOR DA PROVA RETIDA.
 *
 * O moderador fecha o caso (revisada / resolvida / descartada) com nota
 * opcional. NÃO há botão de banir/suspender aqui: isso é poder de admin, em
 * /admin/*.
 *
 * A seção "Evidência" renderiza o conteúdo denunciado inline, servido pelos
 * endpoints de `moderacao/evidencia/*`:
 *  - foto efêmera / story → <img> inline (com zoom em tela cheia), SEM download;
 *  - mensagem → o corpo, revelado sob clique (o fetch dispara o audit de quem viu);
 *  - prova expirada (bytes recolhidos pelo GC) → hash + "expirado".
 *
 * ⚠️ A foto efêmera mostra o ROSTO do membro — é PII sensível. O acesso é
 * auditado no servidor; não há botão de baixar em lugar nenhum.
 */
const props = defineProps({
    report: { type: Object, required: true },
    evidence: { type: Object, required: true },
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

// ── Prova retida ─────────────────────────────────────────────────────────────
// Zoom em tela cheia para as imagens.
const zoomed = ref(false)

// Corpo da mensagem: revelado sob clique. Cada fetch dispara o
// `moderation.evidence_viewed` no servidor — ver o corpo é uma ação deliberada,
// não um efeito colateral de abrir a página.
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

            <!-- Prova retida -->
            <section class="space-y-4 rounded-xl border border-frame/60 bg-surface/30 p-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-serif text-lg text-cream">Evidência</h2>
                    <span class="text-xs text-muted/60">sem download · acesso auditado</span>
                </div>

                <!-- Prova de imagem: foto efêmera ou story -->
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

                <!-- Prova de texto: corpo da mensagem, revelado sob clique -->
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
                    <p v-else class="text-sm text-muted">
                        Evidência não disponível — mensagem removida.
                    </p>
                </template>

                <!-- Sem prova retida a servir (ex.: perfil público) -->
                <p v-else class="text-sm text-muted">
                    Sem prova retida para este tipo de denúncia.
                </p>
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
