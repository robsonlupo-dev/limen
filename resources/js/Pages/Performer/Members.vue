<script setup>
import { reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import MemberCard from '@/Components/MemberCard.vue'
import { postJson } from '@/lib/http'

/**
 * Catálogo de MEMBROS — a HOME da performer (redesign maison). A performer navega
 * membros que optaram por aparecer e engaja por duas ações grátis: CORAÇÃO
 * (interesse grátis e ilimitado — o membro vê quem curtiu, com a identidade dela)
 * e MENSAGEM PERSONALIZADA (franquia diária grátis; o membro paga para LER pelo
 * gate de chat existente). A performer NÃO paga por nenhuma das duas.
 *
 * Cada item já chega MASCARADO do backend (MemberCatalogService): FanAlias label
 * + handle, faixa de atividade e nada mais — nunca nome, e-mail, tier ou saldo.
 */
const props = defineProps({
    // Paginator do Laravel. Cada item de data:
    // { fan_alias_label, member_handle, avatar_url, activity_label, interest_sent, hearted }
    members: { type: Object, required: true },
    // Franquia diária de mensagens grátis e quantas restam hoje (por performer).
    messagesRemaining: { type: Number, default: 0 },
    messagesDailyLimit: { type: Number, default: 0 },
})

// Estado local do coração por handle: parte de `member.hearted` e reflete o
// clique na hora, sem esperar o servidor (o backend é idempotente).
const heartedByHandle = reactive({})
const heartingHandle = ref(null)

// Franquia restante como estado local — a resposta do envio a atualiza.
const remaining = ref(props.messagesRemaining)

const toastMessage = ref('')

// Modal de mensagem personalizada.
const msg = reactive({ open: false, member: null, body: '', sending: false, error: '' })

// Modal de "perfil" do membro (visitas bidirecionais). Abrir registra a visita —
// o membro depois vê "quem visitou seu perfil". O membro não tem mais dado que o
// card mostra (a privacidade não regride): o modal é o gesto de "abrir o perfil"
// e o ponto de partida das ações.
const detail = reactive({ open: false, member: null })

function openDetail(member) {
    detail.member = member
    detail.open = true

    // Fire-and-forget: a visita é lateral à navegação. A dedup de 30min vive no
    // servidor, então reabrir não gera linha nova; erros (404 de membro que saiu
    // da lista, 429) não afetam a tela — o perfil abre de qualquer forma.
    postJson(route('performer.members.visit'), { member_handle: member.member_handle }).catch(() => {})
}

function messageFromDetail() {
    detail.open = false
    openMessage(detail.member)
}

function isHearted(member) {
    return heartedByHandle[member.member_handle] ?? member.hearted
}

function flashToast(text) {
    toastMessage.value = text
    setTimeout(() => (toastMessage.value = ''), 4000)
}

async function sendHeart(member) {
    if (isHearted(member) || heartingHandle.value !== null) return
    heartingHandle.value = member.member_handle

    try {
        await postJson(route('performer.members.heart'), { member_handle: member.member_handle })
        heartedByHandle[member.member_handle] = true
        flashToast('Coração enviado')
    } catch (error) {
        // 404: o membro saiu do catálogo (desligou a visibilidade, encerrou a
        // conta) entre o render e o clique. Copy única — distinguir "não existe"
        // de "saiu da lista" viraria oráculo.
        flashToast(
            error.status === 429
                ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                : error.status === 404
                  ? 'Este membro não está mais disponível.'
                  : 'Não foi possível enviar. Tente novamente.',
        )
    } finally {
        heartingHandle.value = null
    }
}

function openMessage(member) {
    msg.member = member
    msg.body = ''
    msg.error = ''
    msg.open = true
}

async function sendMessage() {
    if (msg.sending || remaining.value <= 0 || msg.body.trim() === '') return
    msg.sending = true
    msg.error = ''

    try {
        const data = await postJson(route('performer.members.message'), {
            member_handle: msg.member.member_handle,
            body: msg.body,
        })
        remaining.value = data.messages_remaining_today ?? remaining.value
        msg.open = false
        flashToast('Mensagem enviada')
    } catch (error) {
        // A franquia esgotada volta o restante zerado no corpo — reflete na UI.
        if (typeof error.data?.messages_remaining_today === 'number') {
            remaining.value = error.data.messages_remaining_today
        }
        msg.error =
            error.status === 404
                ? 'Este membro não está mais disponível.'
                : error.status === 429
                  ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                  : (error.data?.message ?? 'Não foi possível enviar. Tente novamente.')
    } finally {
        msg.sending = false
    }
}
</script>

<template>
    <AppLayout title="Membros">
        <div class="bg-limen-bg">
            <div class="max-w-screen-2xl mx-auto px-6 py-10 space-y-8">
                <!-- Cabeçalho maison -->
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="space-y-2">
                        <h1 class="font-serif text-4xl text-limen-ink">Membros</h1>
                        <p class="text-sm text-limen-ink-soft">
                            Membros que topam ser encontrados. Curta ou mande uma mensagem — eles decidem se abrem a conversa.
                        </p>
                    </div>
                    <!-- Franquia diária de mensagens grátis. -->
                    <div class="shrink-0 rounded-lg border border-limen-line px-3 py-2 text-xs text-limen-ink-mute">
                        Mensagens grátis hoje:
                        <span class="text-limen-gold">{{ remaining }}/{{ messagesDailyLimit }}</span>
                    </div>
                </div>

                <p
                    v-if="members.data.length === 0"
                    class="rounded-xl border border-limen-line bg-limen-surface p-8 text-center text-limen-ink-mute"
                >
                    Nenhum membro disponível no momento.
                </p>

                <!-- Grid full-width, mesma proporção do catálogo de performers. -->
                <div v-else class="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-4">
                    <MemberCard
                        v-for="member in members.data"
                        :key="member.member_handle"
                        :member="member"
                        :hearted="isHearted(member)"
                        :hearting="heartingHandle === member.member_handle"
                        @heart="sendHeart(member)"
                        @message="openMessage(member)"
                        @view="openDetail(member)"
                    />
                </div>

                <!-- Paginação -->
                <div v-if="members.last_page > 1" class="flex flex-wrap justify-center gap-2 pt-2">
                    <Link
                        v-for="link in members.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        preserve-scroll
                        class="rounded-lg px-3 py-1.5 text-sm no-underline transition-colors"
                        :class="[
                            link.active
                                ? 'bg-limen-gold text-limen-bg'
                                : 'border border-limen-line text-limen-ink-mute hover:text-limen-ink',
                            !link.url && 'pointer-events-none opacity-40',
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <!-- Modal de mensagem personalizada -->
        <Modal :show="msg.open" max-width="md" @close="msg.open = false">
            <h2 class="mb-1 font-serif text-2xl text-limen-ink">Mensagem para {{ msg.member?.fan_alias_label }}</h2>
            <p class="mb-4 text-sm text-limen-ink-soft">
                Ele vê que recebeu uma mensagem sua e de quem é, mas só lê o conteúdo depois de abrir o chat.
            </p>

            <template v-if="remaining > 0">
                <textarea
                    v-model="msg.body"
                    rows="4"
                    maxlength="2000"
                    placeholder="Escreva algo pessoal…"
                    class="w-full rounded-lg border border-limen-line bg-limen-surface-2 px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                ></textarea>
                <p v-if="msg.error" class="mt-2 text-xs text-danger">{{ msg.error }}</p>
                <div class="mt-4 flex items-center justify-between">
                    <span class="text-xs text-limen-ink-mute">{{ remaining }}/{{ messagesDailyLimit }} grátis hoje</span>
                    <div class="flex gap-3">
                        <Button variant="ghost" size="sm" @click="msg.open = false">Cancelar</Button>
                        <Button size="sm" :disabled="msg.sending || msg.body.trim() === ''" @click="sendMessage">
                            Enviar
                        </Button>
                    </div>
                </div>
            </template>

            <!-- Franquia esgotada. -->
            <div v-else class="space-y-4">
                <p class="rounded-lg border border-limen-line bg-limen-surface-2 p-4 text-sm text-limen-ink-soft">
                    Você usou suas {{ messagesDailyLimit }} mensagens grátis de hoje. A franquia renova amanhã.
                </p>
                <div class="flex justify-end">
                    <Button variant="ghost" size="sm" @click="msg.open = false">Fechar</Button>
                </div>
            </div>
        </Modal>

        <!-- "Perfil" do membro (visitas bidirecionais). Mostra só o que o card já
             mostra — FanAlias, atividade, selo Novo — porque a privacidade do
             membro não regride: a performer nunca vê nome/e-mail/tier/saldo. É o
             gesto de "abrir o perfil" (que registra a visita) e o ponto de partida
             das ações grátis. -->
        <Modal :show="detail.open" max-width="sm" @close="detail.open = false">
            <div v-if="detail.member" class="space-y-5">
                <div class="flex items-center gap-4">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-limen-surface-2 ring-1 ring-limen-line">
                        <svg class="h-9 w-9 text-limen-ink-mute/40" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h2 class="truncate font-serif text-2xl text-limen-ink">{{ detail.member.fan_alias_label }}</h2>
                        <p v-if="detail.member.activity_label" class="text-sm text-limen-ink-soft">{{ detail.member.activity_label }}</p>
                        <span
                            v-if="detail.member.is_new"
                            class="mt-1 inline-block rounded-full bg-limen-surface-2 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50"
                        >Novo</span>
                    </div>
                </div>

                <p class="text-sm text-limen-ink-soft">
                    Curta ou mande uma mensagem — ele decide se abre a conversa.
                </p>

                <div class="flex items-center justify-end gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="isHearted(detail.member) || heartingHandle !== null"
                        @click="sendHeart(detail.member)"
                    >
                        {{ isHearted(detail.member) ? 'Curtido' : 'Curtir' }}
                    </Button>
                    <Button size="sm" @click="messageFromDetail">Mensagem</Button>
                </div>
            </div>
        </Modal>

        <!-- Toast simples de confirmação. -->
        <transition name="fade">
            <div
                v-if="toastMessage"
                class="fixed bottom-4 right-4 z-50 rounded-lg border border-limen-gold/40 bg-limen-surface px-4 py-2 text-sm text-limen-ink shadow-xl"
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
