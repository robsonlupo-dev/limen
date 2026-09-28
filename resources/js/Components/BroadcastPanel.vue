<script setup>
import { ref, computed } from 'vue'
import Button from '@/Components/Button.vue'
import { postJson, errorMessage } from '@/lib/http'

/**
 * Canal de transmissão — composer da PERFORMER (roadmap social, Onda 2).
 *
 * Publica uma mensagem de texto para os SEGUIDORES. Sem resposta no canal (quem
 * quer falar cai no chat pago). O texto passa pelo filtro anti-contato no servidor
 * (um broadcast é o vetor de fuga de contato grátis); um 422 traz o motivo. Teto
 * diário é do servidor — o composer só reflete o erro.
 */
const props = defineProps({
    maxLength: { type: Number, default: 1000 },
})

const body = ref('')
const sending = ref(false)
const error = ref('')
const sentOk = ref(false)

const remaining = computed(() => props.maxLength - body.value.length)

async function send() {
    const text = body.value.trim()
    if (!text || sending.value) return

    sending.value = true
    error.value = ''
    sentOk.value = false
    try {
        await postJson(route('performer.broadcasts.store'), { body: text })
        body.value = ''
        sentOk.value = true
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível enviar a transmissão.')
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <section class="rounded-2xl border border-frame bg-surface p-6">
        <div class="flex items-baseline justify-between">
            <h2 class="font-serif text-lg text-cream">Canal de transmissão</h2>
        </div>
        <p class="mt-2 text-xs text-muted">
            Uma mensagem para todos os seus seguidores. Eles leem na aba “Canais” — não é
            um chat: quem responder abre a conversa paga com você. Sem telefone, redes ou
            links (fica para o chat).
        </p>

        <div class="mt-4 space-y-2">
            <textarea
                v-model="body"
                :maxlength="maxLength"
                rows="3"
                placeholder="Escreva uma transmissão para seus seguidores…"
                class="block w-full rounded-xl border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                @input="sentOk = false"
            />
            <div class="flex items-center justify-between">
                <span class="text-[11px] text-muted">{{ remaining }} caracteres</span>
                <Button variant="ghost" size="sm" :loading="sending" :disabled="!body.trim()" @click="send">
                    Transmitir
                </Button>
            </div>
            <p v-if="sentOk" class="text-xs text-gold">Transmissão enviada aos seus seguidores.</p>
            <p v-if="error" class="text-xs text-danger">{{ error }}</p>
        </div>
    </section>
</template>
