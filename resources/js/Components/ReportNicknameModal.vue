<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

/**
 * Modal de DENÚNCIA de apelido de membro pela performer (feat/nickname-report,
 * Fase 4b-ui). Reutilizável em qualquer superfície onde ela vê o apelido — o
 * gatilho (botão) é estilizado pela tela hospedeira no tema dela; o modal é
 * único e vive em tokens `limen-*` (componente novo, regra do CLAUDE.md).
 *
 * Só identifica o membro pela STRING do apelido (pública e única) — nunca por id.
 * O endpoint responde SEMPRE "recebida" (anti-oráculo): a performer não descobre
 * se o apelido existe, de quem é, ou se já foi denunciado. A confirmação chega
 * pelo banner global de flash (`flash.success` no AppLayout), então aqui só
 * postamos e fechamos.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // O apelido a denunciar (string pública). Sem apelido, a tela nem abre o modal.
    nickname: { type: String, default: '' },
})

const emit = defineEmits(['close'])

// Motivos aceitos — o MESMO subconjunto do NicknameReportController::REASONS.
const REASONS = [
    { value: 'impersonation', label: 'Fingindo ser outra pessoa', hint: 'Usa nome de alguém real ou de uma performer.' },
    { value: 'spam', label: 'Spam ou divulgação', hint: 'Contato externo, propaganda ou link disfarçado.' },
    { value: 'other', label: 'Outro motivo', hint: 'Descreva abaixo o que há de errado.' },
]

const form = useForm({
    nickname: props.nickname,
    reason: 'impersonation',
    details: '',
})

// O apelido pode mudar entre aberturas (mesma instância, membros diferentes).
watch(() => props.nickname, (v) => { form.nickname = v })

// Reset ao abrir, para não carregar rascunho de uma denúncia anterior.
watch(() => props.show, (open) => {
    if (open) {
        form.clearErrors()
        form.reason = 'impersonation'
        form.details = ''
        form.nickname = props.nickname
    }
})

function close() {
    if (form.processing) return
    emit('close')
}

function submit() {
    form.post(route('performer.members.report-nickname'), {
        preserveScroll: true,
        // Resposta uniforme: em sucesso, fecha; o banner global mostra "recebida".
        onSuccess: () => {
            form.reset('details')
            emit('close')
        },
    })
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
            <!-- Overlay -->
            <div class="absolute inset-0 bg-limen-bg/90 backdrop-blur-sm" @click="close" />

            <!-- Painel: folha no rodapé no mobile, cartão centralizado no desktop -->
            <div
                class="relative z-10 w-full max-w-md rounded-t-2xl border border-limen-line bg-limen-surface p-6 shadow-2xl sm:rounded-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="report-nickname-title"
            >
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-limen-surface-2 text-limen-gold ring-1 ring-limen-line">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 22V4a1 1 0 0 1 1-1h11l-1.5 4L16 11H5" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h2 id="report-nickname-title" class="font-serif text-xl text-limen-ink">Denunciar apelido</h2>
                        <p class="mt-1 text-sm text-limen-ink-soft">
                            Você está denunciando o apelido
                            <span class="font-medium text-limen-ink">“{{ nickname }}”</span>. Nossa equipe analisa em sigilo.
                        </p>
                    </div>
                </div>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <!-- Motivo -->
                    <fieldset class="space-y-2">
                        <legend class="text-xs uppercase tracking-wide text-limen-ink-mute">Motivo</legend>
                        <label
                            v-for="r in REASONS"
                            :key="r.value"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors"
                            :class="form.reason === r.value
                                ? 'border-limen-gold bg-limen-gold/10'
                                : 'border-limen-line bg-limen-surface-2 hover:border-limen-gold/50'"
                        >
                            <input
                                v-model="form.reason"
                                type="radio"
                                name="report-reason"
                                :value="r.value"
                                class="mt-1 h-4 w-4 shrink-0 accent-limen-gold"
                            />
                            <span class="min-w-0">
                                <span class="block text-sm text-limen-ink">{{ r.label }}</span>
                                <span class="block text-xs text-limen-ink-mute">{{ r.hint }}</span>
                            </span>
                        </label>
                        <p v-if="form.errors.reason" class="text-xs text-limen-live">{{ form.errors.reason }}</p>
                    </fieldset>

                    <!-- Detalhes (opcional) -->
                    <div>
                        <label for="report-details" class="text-xs uppercase tracking-wide text-limen-ink-mute">
                            Detalhes <span class="normal-case text-limen-ink-mute/70">(opcional)</span>
                        </label>
                        <textarea
                            id="report-details"
                            v-model="form.details"
                            rows="3"
                            maxlength="500"
                            placeholder="Conte o que aconteceu, se ajudar a análise."
                            class="mt-1 w-full rounded-lg border border-limen-line bg-limen-surface-2 px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none focus:ring-1 focus:ring-limen-gold"
                        />
                        <p v-if="form.errors.details" class="text-xs text-limen-live">{{ form.errors.details }}</p>
                    </div>

                    <!-- Ações: alvos >=44px -->
                    <div class="flex justify-end gap-3 pt-1">
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm text-limen-ink-soft transition-colors hover:text-limen-ink disabled:opacity-50"
                            :disabled="form.processing"
                            @click="close"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-[44px] rounded-lg bg-limen-gold px-4 text-sm font-medium text-limen-bg transition-opacity hover:opacity-90 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Enviando...' : 'Enviar denúncia' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
