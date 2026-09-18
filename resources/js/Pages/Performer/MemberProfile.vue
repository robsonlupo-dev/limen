<script setup>
import { reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

/**
 * PERFIL de um membro, visto pela performer (feat/member-gallery-and-profile,
 * Opção B). Click-through do card: a galeria de fotos APROVADAS + o rótulo
 * (apelido/FanAlias), para a performer saber com quem fala antes de engajar.
 *
 * O payload já chega MASCARADO do servidor (MemberProfileController): rótulo,
 * handle opaco, fotos por token e faixa de atividade — NUNCA nome, e-mail, tier,
 * saldo ou localização. Não há o que vazar no DevTools. As ações (coração,
 * mensagem) reusam as portas do catálogo, resolvidas pelo handle.
 */
const props = defineProps({
    // { fan_alias_label, member_handle, photos:[{id,is_primary,url}], activity_label, is_new, hearted }
    member: { type: Object, required: true },
    messagesRemaining: { type: Number, default: 0 },
    messagesDailyLimit: { type: Number, default: 0 },
})

const hearted = ref(props.member.hearted)
const hearting = ref(false)
const remaining = ref(props.messagesRemaining)
const toast = ref('')

const msg = reactive({ open: false, body: '', sending: false, error: '' })

function flash(text) {
    toast.value = text
    setTimeout(() => (toast.value = ''), 4000)
}

async function toggleHeart() {
    if (hearting.value) return
    hearting.value = true
    try {
        await postJson(route('performer.members.heart'), { member_handle: props.member.member_handle })
        hearted.value = true
        flash('Coração enviado.')
    } catch {
        flash('Não foi possível enviar agora.')
    } finally {
        hearting.value = false
    }
}

async function sendMessage() {
    if (msg.sending || !msg.body.trim()) return
    msg.sending = true
    msg.error = ''
    try {
        const data = await postJson(route('performer.members.message'), {
            member_handle: props.member.member_handle,
            body: msg.body,
        })
        remaining.value = data.messages_remaining_today ?? remaining.value
        msg.open = false
        msg.body = ''
        flash('Mensagem enviada.')
    } catch (e) {
        msg.error = e?.data?.message ?? 'Não foi possível enviar a mensagem.'
        if (e?.data?.messages_remaining_today !== undefined) {
            remaining.value = e.data.messages_remaining_today
        }
    } finally {
        msg.sending = false
    }
}
</script>

<template>
    <AppLayout title="Perfil do membro">
        <div class="mx-auto max-w-2xl px-4 py-6 pb-28 sm:pb-10">
            <Link :href="route('performer.members')" class="inline-flex items-center gap-1 text-sm text-limen-ink-mute no-underline hover:text-limen-ink">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" />
                </svg>
                Catálogo
            </Link>

            <!-- Cabeçalho: rótulo + atividade. Nada de PII. -->
            <div class="mt-4 flex items-center gap-3">
                <h1 class="font-serif text-2xl text-limen-ink">{{ member.fan_alias_label }}</h1>
                <span
                    v-if="member.is_new"
                    class="rounded-full bg-limen-surface px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50"
                >Novo</span>
            </div>
            <p v-if="member.activity_label" class="mt-1 text-sm text-limen-ink-mute">{{ member.activity_label }}</p>

            <!-- Galeria de fotos aprovadas. Vazia → estado neutro. -->
            <div v-if="member.photos.length" class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div
                    v-for="photo in member.photos"
                    :key="photo.id"
                    class="relative aspect-[4/5] overflow-hidden rounded-xl bg-limen-surface ring-1 ring-limen-line"
                >
                    <img :src="photo.url" alt="Foto do membro" loading="lazy" class="h-full w-full object-cover" />
                </div>
            </div>
            <div v-else class="mt-6 grid place-items-center rounded-xl border border-limen-line bg-limen-surface py-12 text-center">
                <p class="text-sm text-limen-ink-mute">Este membro ainda não adicionou fotos.</p>
            </div>

            <!-- Ações: barra fixa no rodapé no mobile; inline no desktop. Dourado
                 (nunca limen-live). Alvos >=44px. -->
            <div class="fixed inset-x-0 bottom-0 z-20 flex items-center gap-3 border-t border-limen-line bg-limen-bg/95 px-4 py-3 backdrop-blur sm:static sm:mt-8 sm:border-0 sm:bg-transparent sm:px-0 sm:py-0">
                <button
                    type="button"
                    :aria-label="hearted ? 'Curtido' : 'Curtir'"
                    :disabled="hearting"
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-limen-surface text-limen-gold ring-1 ring-limen-gold/40 transition-colors hover:ring-limen-gold/70 disabled:opacity-60"
                    @click="toggleHeart"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" :fill="hearted ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z" />
                    </svg>
                </button>
                <Button class="flex-1" :disabled="remaining <= 0" @click="msg.open = true">
                    {{ remaining > 0 ? 'Enviar mensagem' : 'Sem mensagens grátis hoje' }}
                </Button>
            </div>

            <p v-if="toast" class="mt-3 text-center text-sm text-limen-gold">{{ toast }}</p>
        </div>

        <!-- Composer de mensagem personalizada (franquia diária). -->
        <Modal :show="msg.open" @close="msg.open = false">
            <div class="space-y-4 p-6">
                <h2 class="font-serif text-xl text-limen-ink">Mensagem para {{ member.fan_alias_label }}</h2>
                <p class="text-xs text-limen-ink-mute">Restam {{ remaining }} de {{ messagesDailyLimit }} mensagens grátis hoje.</p>
                <textarea
                    v-model="msg.body"
                    rows="4"
                    maxlength="1000"
                    placeholder="Escreva uma mensagem pessoal..."
                    class="w-full rounded-lg border border-limen-line bg-limen-surface-2 px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                />
                <p v-if="msg.error" class="text-sm text-limen-live">{{ msg.error }}</p>
                <div class="flex justify-end gap-3">
                    <Button variant="ghost" @click="msg.open = false">Cancelar</Button>
                    <Button :disabled="msg.sending || !msg.body.trim()" @click="sendMessage">
                        {{ msg.sending ? 'Enviando...' : 'Enviar' }}
                    </Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
