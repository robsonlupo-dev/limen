<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import NotificationSoundSettings from '@/Components/NotificationSoundSettings.vue'
import { formatTokens } from '@/lib/tokens'
import { postJson } from '@/lib/http'

// Painel › Ajustes (reorg do Painel, UAT Fase 21): o que se configura uma vez e
// esquece — fora do caminho do dia a dia. Sons, destaque no catálogo (movido do
// Resumo, mesma lógica) e atalhos de perfil/segurança/agendamentos.
const props = defineProps({
    wallet: { type: Number, required: true },
    // Boost pago (Sprint 11): booleano + faixa, nunca o carimbo `boosted_until`.
    boost: {
        type: Object,
        default: () => ({
            is_boosted: false,
            remaining_label: null,
            cost: 0,
            duration_hours: 0,
            available_slots: 0,
            max_slots: 0,
            eligible: false,
        }),
    },
})

const page = usePage()
const callEnabled = computed(() => !!page.props.features?.call_enabled)

const toastMessage = ref('')

// Boost: estado local otimista sobre a resposta — o servidor devolve o booleano
// derivado + faixa + saldo e vagas atualizados.
const boostState = ref({ ...props.boost })
const walletBalance = ref(props.wallet)
const boosting = ref(false)
const canAffordBoost = computed(() => walletBalance.value >= boostState.value.cost)

async function activateBoost() {
    if (boosting.value || boostState.value.is_boosted) return
    boosting.value = true
    try {
        const data = await postJson(route('performer.boost'))
        boostState.value = {
            ...boostState.value,
            is_boosted: data.is_boosted,
            remaining_label: data.remaining_label,
            available_slots: data.available_slots,
        }
        walletBalance.value = data.wallet
        toastMessage.value = 'Perfil em destaque!'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        toastMessage.value =
            error.status === 429
                ? 'Muitas tentativas em pouco tempo. Aguarde um instante.'
                : (error.data?.message ?? 'Não foi possível destacar. Tente novamente.')
        setTimeout(() => (toastMessage.value = ''), 5000)
    } finally {
        boosting.value = false
    }
}
</script>

<template>
    <AppLayout title="Ajustes">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-1">
                <h1 class="font-serif text-4xl text-cream">Ajustes</h1>
                <p class="text-muted text-sm">O que você configura uma vez e esquece — fora do caminho do dia a dia.</p>
            </div>

            <NotificationSoundSettings />

            <!-- Destaque no catálogo (Sprint 11), movido do Resumo. Três estados:
                 boostada (faixa), pode boostar (botão + custo + vagas), ou sem
                 tokens (aviso + comprar). Só para performer elegível. -->
            <section class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Destaque no catálogo</h2>

                <div
                    v-if="boost.eligible"
                    class="rounded-xl border p-5 space-y-3"
                    :class="boostState.is_boosted ? 'border-gold/40 bg-gold/5' : 'border-frame bg-surface'"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-cream flex items-center gap-2">
                            <svg class="h-4 w-4 text-gold" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13z" /></svg>
                            Apareça no topo do catálogo por {{ boostState.duration_hours }} horas
                        </p>
                        <span v-if="!boostState.is_boosted" class="text-xs text-muted">
                            {{ boostState.available_slots }} de {{ boostState.max_slots }} vagas
                        </span>
                    </div>

                    <template v-if="boostState.is_boosted">
                        <p class="text-xs text-gold">
                            Seu perfil está no topo do catálogo{{ boostState.remaining_label ? ` — ${boostState.remaining_label}` : '' }}.
                        </p>
                    </template>

                    <template v-else-if="canAffordBoost">
                        <p class="text-xs text-muted">
                            Custa <span class="text-gold">{{ boostState.cost }} tokens</span>.
                        </p>
                        <Button
                            variant="primary"
                            size="sm"
                            :loading="boosting"
                            :disabled="boostState.available_slots <= 0"
                            :title="boostState.available_slots <= 0 ? 'Todas as vagas de destaque estão ocupadas agora' : undefined"
                            @click="activateBoost"
                        >
                            Destacar meu perfil
                        </Button>
                    </template>

                    <template v-else>
                        <p class="text-xs text-muted">
                            Você precisa de <span class="text-gold">{{ boostState.cost }} tokens</span> para destacar seu perfil.
                            Você tem {{ formatTokens(walletBalance) }}.
                        </p>
                        <Link :href="route('wallet.index')" class="text-sm text-gold hover:text-gold-light transition-colors no-underline">
                            Comprar tokens &rarr;
                        </Link>
                    </template>
                </div>

                <p v-else class="rounded-xl border border-frame bg-surface p-5 text-sm text-muted">
                    O destaque fica disponível depois da verificação aprovada — é quando seu perfil entra no catálogo.
                </p>

                <p v-if="toastMessage" class="text-xs text-success">{{ toastMessage }}</p>
            </section>

            <!-- Atalhos de conta: os mesmos destinos do menu do avatar, reunidos. -->
            <section class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Perfil e conta</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <Link :href="route('performer.profile.edit')" class="rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors">
                        <p class="text-sm text-cream">Perfil público</p>
                        <p class="text-xs text-muted mt-1">Capa, avatar, bio, valores por minuto</p>
                    </Link>
                    <Link :href="route('performer.2fa.show')" class="rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors">
                        <p class="text-sm text-cream">Segurança</p>
                        <p class="text-xs text-muted mt-1">Senha, 2FA, sessões</p>
                    </Link>
                    <Link v-if="callEnabled" :href="route('performer.reservations.index')" class="rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors">
                        <p class="text-sm text-cream">Agendamentos</p>
                        <p class="text-xs text-muted mt-1">Chamadas marcadas e a confirmar</p>
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
