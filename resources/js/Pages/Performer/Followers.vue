<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    followers: { type: Object, required: true },
    remainingToday: { type: Number, required: true },
    dailyLimit: { type: Number, required: true },
    cooldownDays: { type: Number, required: true },
    below_floor: { type: Boolean, default: false },
    total_followers: { type: Number, default: 0 },
    floor_message: { type: String, default: null },
})

const remaining = ref(props.remainingToday)
// Envios feitos nesta sessão, por member_id — evita recarregar a página só
// para o botão refletir o estado.
const justSent = ref({})
const sendingId = ref(null)
const errorFor = ref({})
const toastMessage = ref('')

function alreadySent(follower) {
    return follower.interest_sent || justSent.value[follower.member_id]
}

async function sendInterest(follower) {
    if (alreadySent(follower) || remaining.value <= 0) return

    errorFor.value = { ...errorFor.value, [follower.member_id]: '' }
    sendingId.value = follower.member_id

    try {
        await postJson(route('performer.interests.send'), { member_id: follower.member_id })

        justSent.value[follower.member_id] = true
        remaining.value = Math.max(0, remaining.value - 1)
        toastMessage.value = 'Interesse enviado'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        const message =
            error.status === 429
                ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                : (error.data?.message ?? 'Não foi possível enviar. Tente novamente.')

        errorFor.value = { ...errorFor.value, [follower.member_id]: message }
    } finally {
        sendingId.value = null
    }
}
</script>

<template>
    <AppLayout title="Seguidores">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Seguidores</h1>
                    <p class="text-muted text-sm">
                        Demonstre interesse em quem já segue você. O sinal não leva texto — ela decide se quer revelar quem é você.
                    </p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <div class="rounded-xl border border-frame bg-surface p-5 flex items-center justify-between gap-4">
                <div class="space-y-1">
                    <p class="text-cream text-sm font-medium">
                        Restam <span class="text-gold">{{ remaining }}</span> de {{ dailyLimit }} envios hoje
                    </p>
                    <p class="text-muted text-xs">
                        Você só pode demonstrar interesse na mesma pessoa uma vez a cada {{ cooldownDays }} dias.
                    </p>
                </div>
                <Link
                    :href="route('performer.interests.index')"
                    class="text-sm text-gold hover:text-gold-light transition-colors shrink-0 no-underline"
                >
                    Interesses enviados &rarr;
                </Link>
            </div>

            <div class="space-y-3">
                <!-- Piso de Anonimato: a lista existe, mas ainda não é mostrável.
                     Sem esta mensagem a performer leria a tela como "ninguém me
                     segue", o que é falso quando total_followers > 0. -->
                <div
                    v-if="below_floor"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Lista ainda não disponível</p>
                    <p class="text-muted text-sm">{{ floor_message }}</p>
                    <p class="text-muted text-xs">
                        Você tem {{ total_followers }}
                        {{ total_followers === 1 ? 'seguidor' : 'seguidores' }} até agora.
                    </p>
                </div>

                <div
                    v-else-if="followers.data.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Ninguém te segue ainda</p>
                    <p class="text-muted text-sm">Quando alguém seguir seu perfil, aparece aqui.</p>
                </div>

                <div
                    v-for="follower in followers.data"
                    :key="follower.member_id"
                    class="rounded-xl border border-frame bg-surface p-5 flex items-center gap-4"
                >
                    <div class="h-12 w-12 rounded-full bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                        <span class="font-serif text-lg text-gold/60" aria-hidden="true">M</span>
                    </div>

                    <div class="flex-1 min-w-0 space-y-0.5">
                        <p class="text-cream">{{ follower.label }}</p>
                        <p class="text-xs text-muted">Segue desde {{ follower.following_since }}</p>
                        <p v-if="errorFor[follower.member_id]" class="text-xs text-danger pt-1">
                            {{ errorFor[follower.member_id] }}
                        </p>
                    </div>

                    <div class="shrink-0">
                        <span
                            v-if="alreadySent(follower)"
                            class="inline-flex items-center rounded-full border border-frame bg-muted/10 px-3 py-1 text-xs text-muted"
                        >
                            Interesse enviado
                        </span>
                        <Button
                            v-else
                            variant="primary"
                            size="sm"
                            :loading="sendingId === follower.member_id"
                            :disabled="remaining <= 0"
                            :title="remaining <= 0 ? 'Você atingiu o limite de envios de hoje' : undefined"
                            @click="sendInterest(follower)"
                        >
                            Demonstrar interesse
                        </Button>
                    </div>
                </div>
            </div>

            <div v-if="followers.last_page > 1" class="flex justify-center gap-2 pt-2">
                <Link
                    v-for="link in followers.links"
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
        </div>

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
