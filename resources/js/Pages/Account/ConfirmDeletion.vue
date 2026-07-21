<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const props = defineProps({
    token: { type: String, default: null },
    valid: { type: Boolean, default: false },
    scheduledAt: { type: String, default: null },
})

const saving = ref(false)

const scheduledLabel = computed(() => {
    if (!props.scheduledAt) return null
    return new Date(props.scheduledAt).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })
})

// O POST é que consome o token. O GET desta página não muda nada — caixas de
// e-mail fazem prefetch dos links e queimariam o token de uso único sozinhas.
function confirm() {
    if (saving.value) return
    saving.value = true

    router.post(
        route('account.deletion.confirm.store'),
        { token: props.token },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    )
}
</script>

<template>
    <AppLayout title="Confirmar exclusão de conta">
        <div class="min-h-[70vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-md text-center space-y-6">
                <PortalLogo :size="56" />

                <!-- Um único estado para inválido, expirado e já usado: separar
                     os três diria a quem tem a URL se ela um dia foi válida. -->
                <template v-if="!valid">
                    <div class="space-y-2">
                        <h1 class="font-serif text-2xl text-cream">Link inválido ou expirado</h1>
                        <p class="text-muted text-sm leading-relaxed">
                            Se você já confirmou por este link, está tudo certo — o pedido segue
                            valendo e o link só pode ser usado uma vez. Você pode conferir a data
                            e cancelar a qualquer momento em Configurações.
                        </p>
                    </div>
                </template>

                <template v-else>
                    <div class="space-y-2">
                        <h1 class="font-serif text-2xl text-cream">Confirmar exclusão da conta</h1>
                        <p class="text-muted text-sm leading-relaxed">
                            Sua conta está agendada para exclusão em
                            <strong class="text-cream">{{ scheduledLabel }}</strong>.
                            Confirmar não antecipa a data — só nos diz que o pedido é seu mesmo.
                        </p>
                    </div>

                    <div class="bg-surface border border-frame rounded-xl p-6 text-sm text-muted">
                        <p>
                            <strong class="text-cream">Não foi você?</strong>
                            Não confirme. Entre na sua conta, cancele o pedido em Configurações e
                            troque sua senha.
                        </p>
                    </div>

                    <button
                        type="button"
                        :disabled="saving"
                        @click="confirm"
                        class="rounded-lg border border-red-900/60 bg-red-950/40 px-5 py-2.5 text-sm text-red-300 transition-colors hover:bg-red-950/60 disabled:opacity-50"
                    >
                        Confirmar pedido de exclusão
                    </button>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
