<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    deletion: { type: Object, required: true },
})

const requested = computed(() => Boolean(props.deletion?.requested_at))
const blocked = computed(() => (props.deletion?.blocking_payouts ?? 0) > 0)

const scheduledLabel = computed(() => {
    if (!props.deletion?.scheduled_at) return null
    return new Date(props.deletion.scheduled_at).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })
})

// Confirmação em dois passos na própria tela. O e-mail é a segunda barreira,
// mas ela chega tarde demais se o clique já agendou — aqui o usuário precisa
// dizer sim duas vezes antes de qualquer coisa acontecer.
const confirming = ref(false)
const saving = ref(false)

function submit(routeName) {
    if (saving.value) return
    saving.value = true

    router.post(
        route(routeName),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false
                confirming.value = false
            },
        },
    )
}
</script>

<template>
    <div class="rounded-xl border border-red-900/40 bg-surface p-5 space-y-3">
        <div class="space-y-1">
            <p class="text-cream font-medium">Exclusão de conta</p>
            <p class="text-muted text-sm">
                Seu direito de eliminação dos dados (LGPD, art. 18).
            </p>
        </div>

        <!-- Pedido em aberto -->
        <template v-if="requested">
            <div class="rounded-lg border border-frame bg-surface-2 p-4 space-y-1">
                <p class="text-cream text-sm">
                    Exclusão agendada para <strong>{{ scheduledLabel }}</strong>.
                </p>
                <p class="text-muted text-xs">
                    Até lá nada é apagado e você pode cancelar. Depois dessa data, não há como voltar atrás.
                </p>
                <p v-if="!deletion.confirmed" class="text-muted text-xs">
                    Enviamos um e-mail de confirmação — se não chegou, o pedido segue valendo mesmo assim.
                </p>
            </div>

            <button
                type="button"
                :disabled="saving"
                @click="submit('account.deletion.cancel')"
                class="rounded-lg border border-gold/40 bg-gold/10 px-4 py-2 text-sm text-gold transition-colors hover:bg-gold/20 disabled:opacity-50"
            >
                Cancelar solicitação
            </button>
        </template>

        <!-- Saque em aberto: exclusão indisponível -->
        <template v-else-if="blocked">
            <div class="rounded-lg border border-frame bg-surface-2 p-4">
                <p class="text-muted text-sm">
                    Você tem um saque em andamento. Assim que ele for concluído, a exclusão
                    da conta fica disponível — assim o valor não fica sem destinatário.
                </p>
            </div>
        </template>

        <!-- Nenhum pedido -->
        <template v-else>
            <div class="rounded-lg border border-frame bg-surface-2 p-4 space-y-2">
                <p class="text-muted text-sm">
                    O pedido abre um prazo de <strong class="text-cream">{{ deletion.grace_days }} dias</strong>
                    para você desistir. Passado o prazo, apagamos em definitivo:
                </p>
                <ul class="text-muted text-xs space-y-1 list-disc list-inside">
                    <li>seus documentos de verificação de identidade;</li>
                    <li>seu nome, e-mail, telefone e data de nascimento;</li>
                    <li>seu perfil, suas fotos e quem você segue.</li>
                </ul>
                <p class="text-muted text-xs">
                    Por obrigação legal, permanecem os registros financeiros (notas de compra e
                    saque) e os registros de segurança — sem ligação com a sua identidade.
                </p>
            </div>

            <template v-if="!confirming">
                <button
                    type="button"
                    @click="confirming = true"
                    class="rounded-lg border border-red-900/60 px-4 py-2 text-sm text-red-400 transition-colors hover:bg-red-950/30"
                >
                    Solicitar exclusão da conta
                </button>
            </template>

            <template v-else>
                <div class="space-y-2">
                    <p class="text-cream text-sm">Tem certeza? Vamos enviar um e-mail com o prazo.</p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="saving"
                            @click="submit('account.deletion.request')"
                            class="rounded-lg border border-red-900/60 bg-red-950/40 px-4 py-2 text-sm text-red-300 transition-colors hover:bg-red-950/60 disabled:opacity-50"
                        >
                            Sim, solicitar exclusão
                        </button>
                        <button
                            type="button"
                            :disabled="saving"
                            @click="confirming = false"
                            class="rounded-lg border border-frame px-4 py-2 text-sm text-muted transition-colors hover:text-cream disabled:opacity-50"
                        >
                            Voltar
                        </button>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>
