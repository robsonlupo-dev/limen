<script setup>
import { computed, ref, watch } from 'vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import { postJson } from '@/lib/http'

/**
 * Confirmação de compartilhamento de foto efêmera com UMA performer.
 *
 * ── O aviso é o produto, não decoração (§ 1.1) ──────────────────────────────
 * A decisão registrada é explícita: isto **não é uma feature de privacidade**,
 * é des-anonimização consentida, e o aviso vive AQUI — no momento do envio —
 * e não nos Termos. O rosto é uma chave de join global: duas performers que
 * receberam foto do mesmo membro comparam as imagens fora da plataforma e
 * desfazem o isolamento cross-perfil que o FanAlias existe para dar.
 *
 * Por isso a copy não promete o que o TTL não entrega. "Isso não expira" é
 * sobre a MEMÓRIA e o print — o prazo governa o arquivo no nosso servidor, e
 * nada além dele. Mesma disciplina de linguagem do painel de visitantes e do
 * geobloqueio: não escrever que "a performer não guarda sua foto".
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    photos: { type: Array, required: true }, // [{ id, expires_slot, shared_with }]
    performerName: { type: String, required: true },
    performerProfileId: { type: Number, required: true },
})

const emit = defineEmits(['close', 'shared'])

const selected = ref(props.photos[0]?.id ?? null)
const acknowledged = ref(false)
const sending = ref(false)
const error = ref('')

// Reabrir o modal não pode herdar o "li e concordo" da vez anterior: o aviso
// tem de ser reconhecido a cada envio, que é o ponto de ele existir.
watch(() => props.show, (open) => {
    if (open) {
        selected.value = props.photos[0]?.id ?? null
        acknowledged.value = false
        error.value = ''
    }
})

const canSend = computed(() => selected.value !== null && acknowledged.value && !sending.value)

async function share() {
    if (!canSend.value) return

    sending.value = true
    error.value = ''
    try {
        await postJson(route('member.photos.share', selected.value), {
            performer_profile_id: props.performerProfileId,
        })
        emit('shared')
        emit('close')
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível compartilhar a foto.'
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <h2 class="font-serif text-xl text-cream">Compartilhar foto</h2>
        <p class="mt-1 text-sm text-muted">com {{ performerName }}</p>

        <div v-if="photos.length" class="mt-5 space-y-4">
            <label class="block text-xs text-muted">
                Qual foto
                <select
                    v-model.number="selected"
                    class="mt-2 block w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                >
                    <option v-for="photo in photos" :key="photo.id" :value="photo.id">
                        Foto #{{ photo.id }} — {{ photo.expires_slot }}
                    </option>
                </select>
            </label>

            <!-- § 1.1: o aviso de des-anonimização, no momento do envio. -->
            <div class="rounded-xl border border-danger/40 bg-danger/5 p-4">
                <p class="text-sm text-cream">
                    A performer verá seu rosto ligado ao seu pseudônimo. <strong>Isso não expira.</strong>
                </p>
                <p class="mt-2 text-xs text-muted">
                    O prazo apaga o arquivo do nosso servidor. Ele não apaga o que ela já viu, nem
                    impede uma captura de tela.
                </p>
            </div>

            <label class="flex items-start gap-3 text-xs text-muted">
                <input v-model="acknowledged" type="checkbox" class="mt-0.5 accent-gold" />
                <span>Entendi e quero compartilhar mesmo assim.</span>
            </label>

            <p v-if="error" class="text-xs text-danger">{{ error }}</p>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button class="text-sm text-muted hover:text-cream" @click="$emit('close')">Cancelar</button>
                <Button variant="primary" size="sm" :loading="sending" :disabled="!canSend" @click="share">
                    Compartilhar
                </Button>
            </div>
        </div>

        <div v-else class="mt-5 space-y-4">
            <p class="text-sm text-muted">
                Você não tem fotos ativas. Envie uma no seu painel para poder compartilhar aqui.
            </p>
            <div class="flex justify-end">
                <button class="text-sm text-muted hover:text-cream" @click="$emit('close')">Fechar</button>
            </div>
        </div>
    </Modal>
</template>
