<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import { patchJson, postJson } from '@/lib/http'

const props = defineProps({
    unlockCost: { type: Number, required: true },
    balance: { type: Number, required: true },
    optOut: { type: Boolean, required: true },
    interests: { type: Object, required: true },
})

const currentBalance = ref(props.balance)
const optOutLocal = ref(props.optOut)
const optOutSaving = ref(false)
const optOutError = ref('')

// Interesses revelados nesta sessão, por id — o backend só reenvia a página
// no reload, então guardamos a revelação localmente.
const revealed = ref({})
const unlockingId = ref(null)
const unlockError = ref('')
const confirmTarget = ref(null)
const toastMessage = ref('')

const lockedCount = computed(
    () => props.interests.data.filter((i) => i.status === 'sent' && !revealed.value[i.id]).length,
)

function performerOf(interest) {
    return revealed.value[interest.id] ?? interest.performer
}

function isLocked(interest) {
    return !performerOf(interest)
}

function formatDate(value) {
    if (!value) return ''
    return new Date(value).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function askUnlock(interest) {
    unlockError.value = ''
    confirmTarget.value = interest
}

async function confirmUnlock() {
    const interest = confirmTarget.value
    if (!interest) return

    unlockError.value = ''
    unlockingId.value = interest.id

    try {
        const data = await postJson(route('interests.unlock', interest.id))

        revealed.value[interest.id] = data.performer
        currentBalance.value = data.new_balance
        confirmTarget.value = null
        toastMessage.value = `Interesse de ${data.performer.stage_name} revelado`
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        if (error.status === 422 && error.data?.reason === 'insufficient_balance') {
            router.visit(route('wallet.index'))
            return
        }
        if (error.status === 429) {
            unlockError.value = 'Muitas tentativas em pouco tempo. Aguarde um instante.'
        } else {
            unlockError.value = error.data?.message ?? 'Não foi possível desbloquear. Tente novamente.'
        }
    } finally {
        unlockingId.value = null
    }
}

async function toggleOptOut() {
    const next = !optOutLocal.value

    optOutError.value = ''
    optOutSaving.value = true

    try {
        await patchJson(route('interests.opt-out'), { opt_out: next })
        optOutLocal.value = next
    } catch {
        optOutError.value = 'Não foi possível salvar sua preferência. Tente novamente.'
    } finally {
        optOutSaving.value = false
    }
}
</script>

<template>
    <AppLayout title="Interesses">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Interesses</h1>
                    <p class="text-muted text-sm">
                        Performers demonstraram interesse em você. Desbloqueie para descobrir quem.
                    </p>
                </div>
                <div class="text-sm text-muted shrink-0">
                    Saldo: <span class="text-gold font-medium">{{ currentBalance }}</span> tokens
                    <Link :href="route('wallet.index')" class="ml-2 text-gold hover:text-gold-light transition-colors">
                        Comprar
                    </Link>
                </div>
            </div>

            <!-- Locked summary -->
            <div
                v-if="lockedCount > 0"
                class="rounded-xl border border-gold/30 bg-gold/10 p-5 text-sm text-gold"
            >
                {{ lockedCount === 1 ? 'Uma performer demonstrou' : `${lockedCount} performers demonstraram` }}
                interesse em você. Custa
                <span class="font-medium">{{ unlockCost }} tokens</span> para revelar cada uma.
            </div>

            <!-- List -->
            <div class="space-y-3">
                <div
                    v-if="interests.data.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Nenhum interesse ainda</p>
                    <p class="text-muted text-sm">
                        Quando uma performer demonstrar interesse em você, ele aparece aqui.
                    </p>
                    <Link :href="route('catalog')" class="inline-block pt-2 text-sm text-gold hover:text-gold-light transition-colors">
                        Explorar o catálogo
                    </Link>
                </div>

                <div
                    v-for="interest in interests.data"
                    :key="interest.id"
                    class="rounded-xl border border-frame bg-surface p-5 flex items-center gap-4"
                >
                    <!-- Avatar / locked placeholder -->
                    <div class="h-14 w-14 rounded-full bg-surface-2 overflow-hidden flex items-center justify-center shrink-0 border border-frame">
                        <template v-if="isLocked(interest)">
                            <span class="text-2xl text-gold/50" aria-hidden="true">?</span>
                        </template>
                        <template v-else>
                            <img
                                v-if="performerOf(interest).avatar_url"
                                :src="performerOf(interest).avatar_url"
                                :alt="performerOf(interest).stage_name"
                                class="h-full w-full object-cover"
                            />
                            <span v-else class="font-serif text-xl text-gold">
                                {{ performerOf(interest).stage_name?.charAt(0) }}
                            </span>
                        </template>
                    </div>

                    <!-- Identity -->
                    <div class="flex-1 min-w-0 space-y-0.5">
                        <p v-if="isLocked(interest)" class="text-cream">Alguém demonstrou interesse</p>
                        <Link
                            v-else
                            :href="route('catalog.show', performerOf(interest).slug)"
                            class="text-cream hover:text-gold transition-colors no-underline font-medium"
                        >
                            {{ performerOf(interest).stage_name }}
                        </Link>
                        <p class="text-xs text-muted">{{ formatDate(interest.sent_at) }}</p>
                    </div>

                    <!-- Action -->
                    <div class="shrink-0">
                        <Button
                            v-if="isLocked(interest)"
                            variant="primary"
                            size="sm"
                            :loading="unlockingId === interest.id"
                            @click="askUnlock(interest)"
                        >
                            Desbloquear · {{ unlockCost }}
                        </Button>
                        <span
                            v-else
                            class="inline-flex items-center rounded-full border border-success/30 bg-success/10 px-3 py-1 text-xs text-success"
                        >
                            Revelado
                        </span>
                    </div>
                </div>

                <p v-if="unlockError" class="text-sm text-danger">{{ unlockError }}</p>
            </div>

            <!-- Pagination -->
            <div v-if="interests.last_page > 1" class="flex justify-center gap-2 pt-2">
                <Link
                    v-for="link in interests.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    class="rounded-lg border px-3 py-1.5 text-sm transition-colors no-underline"
                    :class="[
                        link.active ? 'border-gold bg-gold/10 text-gold' : 'border-frame text-muted hover:border-gold/40',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    v-html="link.label"
                />
            </div>

            <!-- Opt-out -->
            <div class="rounded-xl border border-frame bg-surface p-5 flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <p class="text-cream text-sm font-medium">Receber interesses</p>
                    <p class="text-muted text-xs max-w-md">
                        Desligando, performers deixam de conseguir demonstrar interesse em você. Você pode religar quando quiser.
                    </p>
                    <p v-if="optOutError" class="text-xs text-danger pt-1">{{ optOutError }}</p>
                </div>
                <Button
                    :variant="optOutLocal ? 'primary' : 'ghost'"
                    size="sm"
                    :loading="optOutSaving"
                    @click="toggleOptOut"
                >
                    {{ optOutLocal ? 'Religar' : 'Desligar' }}
                </Button>
            </div>
        </div>

        <!-- Unlock confirmation -->
        <Modal :show="!!confirmTarget" max-width="sm" @close="confirmTarget = null">
            <div class="space-y-5">
                <h3 class="font-serif text-2xl text-cream">Desbloquear interesse</h3>
                <p class="text-sm text-muted">
                    Serão debitados <span class="text-gold font-medium">{{ unlockCost }} tokens</span> do seu saldo
                    para revelar quem demonstrou interesse. Você paga uma única vez por performer.
                </p>

                <p v-if="unlockError" class="text-sm text-danger">{{ unlockError }}</p>

                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="ghost" :disabled="!!unlockingId" @click="confirmTarget = null">Cancelar</Button>
                    <Button variant="primary" :loading="!!unlockingId" @click="confirmUnlock">
                        Desbloquear
                    </Button>
                </div>
            </div>
        </Modal>

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
