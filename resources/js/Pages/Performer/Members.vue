<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

/**
 * Catálogo de MEMBROS (Sprint 16) — lado da PERFORMER. Vitrine invertida: a
 * performer navega membros que optaram por aparecer e sinaliza interesse
 * (Interesse Controlado invertido — ela sinaliza, o membro decide se paga para
 * abrir o chat).
 *
 * Cada item já chega MASCARADO do backend (MemberCatalogService): FanAlias label
 * + handle, faixa de atividade e nada mais. Nunca nome, e-mail, tier ou saldo do
 * membro — o payload não os carrega, então não há o que vazar no DevTools.
 */
const props = defineProps({
    // Paginator do Laravel: { data, links, last_page, ... }. Cada item de data:
    // { fan_alias_label, member_handle, avatar_url, activity_label, interest_sent }.
    members: { type: Object, required: true },
})

const justSent = ref({})
const sendingHandle = ref(null)
const errorFor = ref({})
const toastMessage = ref('')

function alreadySent(member) {
    return member.interest_sent || justSent.value[member.member_handle]
}

function initial(label) {
    return (label ?? '?').replace(/[^\p{L}\p{N}]/gu, '').charAt(0).toUpperCase() || '?'
}

async function sendInterest(member) {
    if (alreadySent(member) || sendingHandle.value !== null) return

    errorFor.value = { ...errorFor.value, [member.member_handle]: '' }
    sendingHandle.value = member.member_handle

    try {
        await postJson(route('performer.members.interest'), { member_handle: member.member_handle })

        justSent.value[member.member_handle] = true
        toastMessage.value = 'Interesse enviado'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        // 404: o membro pode ter saído do catálogo (desligou a visibilidade,
        // encerrou a conta) entre o render e o clique. Copy única para todos os
        // casos — distinguir "não existe" de "saiu da lista" viraria oráculo.
        const message =
            error.status === 429
                ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                : error.status === 404
                  ? 'Este membro não está mais disponível.'
                  : (error.data?.message ?? 'Não foi possível enviar. Tente novamente.')

        errorFor.value = { ...errorFor.value, [member.member_handle]: message }
    } finally {
        sendingHandle.value = null
    }
}
</script>

<template>
    <AppLayout title="Membros">
        <div class="max-w-5xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Membros</h1>
                    <p class="text-muted text-sm">
                        Membros que topam ser encontrados. Demonstre interesse — eles decidem se abrem a conversa.
                    </p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <p v-if="members.data.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted">
                Nenhum membro disponível no momento.
            </p>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="member in members.data"
                    :key="member.member_handle"
                    class="flex flex-col gap-3 rounded-xl border border-frame bg-surface p-5"
                >
                    <div class="flex items-center gap-3">
                        <img
                            v-if="member.avatar_url"
                            :src="member.avatar_url"
                            alt=""
                            class="h-12 w-12 shrink-0 rounded-full object-cover"
                        />
                        <div
                            v-else
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-surface-2 text-lg font-semibold text-gold"
                        >{{ initial(member.fan_alias_label) }}</div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-cream font-medium">{{ member.fan_alias_label }}</p>
                            <p v-if="member.activity_label" class="text-xs text-muted">{{ member.activity_label }}</p>
                        </div>
                    </div>

                    <p v-if="errorFor[member.member_handle]" class="text-xs text-danger">
                        {{ errorFor[member.member_handle] }}
                    </p>

                    <Button
                        v-if="alreadySent(member)"
                        variant="ghost"
                        disabled
                        class="w-full"
                    >
                        Interesse enviado
                    </Button>
                    <Button
                        v-else
                        :disabled="sendingHandle === member.member_handle"
                        class="w-full"
                        @click="sendInterest(member)"
                    >
                        Enviar interesse
                    </Button>
                </div>
            </div>

            <div v-if="members.last_page > 1" class="flex justify-center gap-2 pt-2">
                <Link
                    v-for="link in members.links"
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

        <!-- Toast simples de confirmação de envio. -->
        <transition name="fade">
            <div
                v-if="toastMessage"
                class="fixed bottom-4 right-4 z-50 rounded-lg border border-gold/40 bg-surface px-4 py-2 text-sm text-cream shadow-xl"
            >
                {{ toastMessage }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
