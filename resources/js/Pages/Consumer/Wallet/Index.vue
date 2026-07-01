<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'
import PixModal from '@/Components/PixModal.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    balance: { type: Number, required: true },
    packages: { type: Array, required: true },
    recent: { type: Array, required: true },
    needsCpf: { type: Boolean, required: true },
})

const currentBalance = ref(props.balance)
const needsCpfLocal = ref(props.needsCpf)
const cpf = ref('')
const cpfError = ref('')
const generalError = ref('')
const loadingPackageId = ref(null)
const activePayment = ref(null)
const modalOpen = ref(false)
const toastMessage = ref('')

const entryTypeLabels = {
    purchase: 'Compra',
    spend_tip: 'Gorjeta enviada',
    tip_credit: 'Gorjeta recebida',
    spend_private: 'Sessão privada',
    spend_camera: 'Câmera',
    payout_reserve: 'Reserva de repasse',
    refund: 'Reembolso',
    bonus: 'Bônus',
    adjustment: 'Ajuste',
}

function entryLabel(type) {
    return entryTypeLabels[type] ?? type
}

async function buyPackage(pkg) {
    generalError.value = ''
    cpfError.value = ''

    if (needsCpfLocal.value && !cpf.value.trim()) {
        cpfError.value = 'Informe seu CPF para continuar.'
        return
    }

    loadingPackageId.value = pkg.id

    try {
        const data = await postJson(route('wallet.purchase', { package: pkg.id }), {
            cpf: needsCpfLocal.value ? cpf.value : undefined,
        })

        activePayment.value = data
        modalOpen.value = true
        needsCpfLocal.value = false
    } catch (error) {
        if (error.status === 422 && error.data?.errors?.cpf) {
            cpfError.value = error.data.errors.cpf[0]
        } else {
            generalError.value = 'Não foi possível iniciar o pagamento. Tente novamente.'
        }
    } finally {
        loadingPackageId.value = null
    }
}

function handlePaid(newBalance) {
    currentBalance.value = newBalance
    toastMessage.value = 'Tokens creditados! 🎉'
    setTimeout(() => (toastMessage.value = ''), 4000)
}

function closeModal() {
    modalOpen.value = false
    activePayment.value = null
}
</script>

<template>
    <AppLayout title="Carteira">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-10">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Carteira</h1>
                    <p class="text-muted text-sm">
                        Seu saldo: <span class="text-gold font-medium">{{ currentBalance }}</span> tokens
                    </p>
                </div>
                <Link :href="route('wallet.history')" class="text-sm text-gold hover:text-gold-light transition-colors">
                    Ver histórico
                </Link>
            </div>

            <div v-if="needsCpfLocal" class="max-w-sm">
                <Input
                    id="cpf"
                    v-model="cpf"
                    label="CPF"
                    placeholder="000.000.000-00"
                    required
                    :error="cpfError"
                />
                <p class="text-xs text-muted mt-1">Necessário na primeira compra para gerar o PIX.</p>
            </div>
            <p v-if="generalError" class="text-sm text-danger">{{ generalError }}</p>

            <!-- Packages grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div
                    v-for="pkg in packages"
                    :key="pkg.id"
                    :class="[
                        'relative rounded-xl border bg-surface p-6 space-y-4 flex flex-col',
                        pkg.slug === 'ouro' ? 'border-2 border-gold' : 'border-frame',
                    ]"
                >
                    <span
                        v-if="pkg.slug === 'ouro'"
                        class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-gold px-3 py-1 text-[11px] font-medium uppercase tracking-wide text-background"
                    >
                        Popular
                    </span>

                    <div class="text-center space-y-1">
                        <h2 class="font-serif text-2xl text-gold">{{ pkg.name }}</h2>
                        <p class="font-serif text-4xl text-cream">{{ pkg.tokens }}</p>
                        <p class="text-xs text-muted">tokens</p>
                    </div>

                    <div v-if="pkg.bonus > 0" class="flex justify-center">
                        <span class="rounded-full bg-success/10 border border-success/30 px-2.5 py-1 text-xs text-success">
                            +{{ pkg.bonus }} tokens bônus
                        </span>
                    </div>

                    <p class="text-center text-lg text-cream font-medium mt-auto">{{ pkg.price_formatted }}</p>

                    <Button
                        variant="primary"
                        class="w-full"
                        :loading="loadingPackageId === pkg.id"
                        :disabled="loadingPackageId !== null"
                        @click="buyPackage(pkg)"
                    >
                        Comprar com PIX
                    </Button>
                </div>
            </div>

            <!-- Recent history -->
            <div class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Histórico recente</h2>

                <div v-if="recent.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Nenhuma movimentação ainda.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Tipo</th>
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(entry, i) in recent" :key="i" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">{{ entryLabel(entry.entry_type) }}</td>
                                <td class="px-5 py-3" :class="entry.amount >= 0 ? 'text-success' : 'text-danger'">
                                    {{ entry.amount >= 0 ? '+' : '' }}{{ entry.amount }}
                                </td>
                                <td class="px-5 py-3 text-muted">{{ entry.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PixModal :show="modalOpen" :payment="activePayment" @close="closeModal" @paid="handlePaid" />

        <!-- Toast -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="toastMessage"
                class="fixed bottom-6 left-1/2 -translate-x-1/2 rounded-lg border border-gold/30 bg-surface px-5 py-3 text-sm text-cream shadow-2xl z-50"
            >
                {{ toastMessage }}
            </div>
        </Transition>
    </AppLayout>
</template>
