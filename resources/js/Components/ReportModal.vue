<script setup>
import { ref, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    show: { type: Boolean, default: false },
    // Apelido do alvo — 'performer' | 'message'. Espelha Report::REPORTABLE_TYPES;
    // o servidor rejeita qualquer outro valor.
    reportableType: { type: String, required: true },
    reportableId: { type: [Number, String], required: true },
})

const emit = defineEmits(['close'])

// Rótulos em PT-BR; os valores são exatamente o enum da coluna `reason`.
const reasons = [
    { value: 'underage_content', label: 'Suspeita de menor de idade' },
    { value: 'non_consensual', label: 'Conteúdo não-consensual' },
    { value: 'coercion', label: 'Coerção ou exploração' },
    { value: 'impersonation', label: 'Perfil falso / se passa por outra pessoa' },
    { value: 'spam', label: 'Spam ou golpe' },
    { value: 'other', label: 'Outro motivo' },
]

const reason = ref('')
const details = ref('')
const sending = ref(false)
const error = ref('')
const sent = ref(false)

// Reabrir o modal começa do zero — inclusive limpando a confirmação anterior,
// senão a segunda denúncia abriria já mostrando "recebida".
watch(() => props.show, (open) => {
    if (open) {
        reason.value = ''
        details.value = ''
        error.value = ''
        sent.value = false
    }
})

async function submit() {
    if (sending.value || !reason.value) return
    sending.value = true
    error.value = ''
    try {
        await postJson(route('report.store'), {
            reportable_type: props.reportableType,
            reportable_id: props.reportableId,
            reason: reason.value,
            details: details.value || null,
        })
        sent.value = true
    } catch (e) {
        error.value = e.data?.message
            ?? 'Não foi possível enviar a denúncia. Tente novamente.'
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <!-- Confirmação discreta: sem celebração, sem eco do que foi denunciado. -->
        <div v-if="sent" class="space-y-4">
            <h2 class="font-serif text-xl text-cream">Denúncia recebida</h2>
            <p class="text-muted text-sm">
                Nossa equipe vai analisar. Se houver risco imediato a alguém, acione também as
                autoridades — <span class="text-cream">Disque 100</span> ou a polícia local.
            </p>
            <div class="flex justify-end">
                <Button variant="ghost" size="sm" @click="emit('close')">Fechar</Button>
            </div>
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div>
                <h2 class="font-serif text-xl text-cream">Denunciar</h2>
                <p class="text-muted text-sm mt-1">
                    Sua denúncia é confidencial — a pessoa denunciada não é notificada.
                </p>
            </div>

            <div class="space-y-1.5">
                <label for="report-reason" class="block text-xs uppercase tracking-wide text-muted">
                    Motivo
                </label>
                <select
                    id="report-reason"
                    v-model="reason"
                    required
                    class="w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream focus:border-gold/50 focus:outline-none"
                >
                    <option value="" disabled>Selecione um motivo</option>
                    <option v-for="option in reasons" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="space-y-1.5">
                <label for="report-details" class="block text-xs uppercase tracking-wide text-muted">
                    Detalhes <span class="normal-case tracking-normal">(opcional)</span>
                </label>
                <textarea
                    id="report-details"
                    v-model="details"
                    rows="4"
                    maxlength="2000"
                    placeholder="O que você viu? Quanto mais específico, mais rápido conseguimos agir."
                    class="w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted/60 focus:border-gold/50 focus:outline-none"
                />
            </div>

            <p v-if="error" class="text-xs text-danger">{{ error }}</p>

            <div class="flex gap-3 justify-end">
                <Button variant="ghost" size="sm" @click="emit('close')">Cancelar</Button>
                <Button type="submit" variant="danger" size="sm" :loading="sending" :disabled="!reason">
                    Enviar denúncia
                </Button>
            </div>
        </form>
    </Modal>
</template>
