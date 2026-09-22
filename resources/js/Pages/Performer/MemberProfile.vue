<script setup>
import { computed, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import Lightbox from '@/Components/Lightbox.vue'
import ReportNicknameModal from '@/Components/ReportNicknameModal.vue'
import { postJson } from '@/lib/http'

/**
 * PERFIL de um membro, visto pela performer — redesign RICO (referência visual:
 * Seeking.com). Foto hero + grade de miniaturas (lightbox na variante completa),
 * chips de status, e blocos "Sobre mim" / "O que busco" / "Interesses" /
 * "Detalhes" — cada um só quando o membro preencheu (nada de "não informado").
 *
 * O payload já chega MASCARADO do servidor (MemberProfileController): rótulo,
 * handle opaco, fotos por token e SÓ o que o membro consentiu em expor — nunca
 * nome, e-mail, tier, saldo. As ações (coração, mensagem) reusam as portas do
 * catálogo, resolvidas pelo handle.
 */
const props = defineProps({
    // { fan_alias_label, member_handle, photos:[{id,url,full_url}], activity_label,
    //   is_new, hearted, bio, seeking[], interests[], age_band, city_label,
    //   marital_status, height, member_since, is_verified }
    member: { type: Object, required: true },
    messagesRemaining: { type: Number, default: 0 },
    messagesDailyLimit: { type: Number, default: 0 },
})

const hearted = ref(props.member.hearted)
const hearting = ref(false)
const remaining = ref(props.messagesRemaining)
const toast = ref('')

const msg = reactive({ open: false, body: '', sending: false, error: '' })

// Denúncia de apelido (feat/nickname-report, 4b-ui): só quando o rótulo é um
// apelido escolhido pelo membro (member.nickname), não o FanAlias.
const reportOpen = ref(false)

// Lightbox: índice aberto (null = fechado).
const lightboxIndex = ref(null)
function openPhoto(i) {
    lightboxIndex.value = i
}

const photos = computed(() => props.member.photos ?? [])
// Capa = avatar do membro (a foto que ele escolheu como cara). Sem avatar, cai
// na 1ª foto da galeria (comportamento antigo).
const avatarUrl = computed(() => props.member.avatar_url ?? null)
const heroPhoto = computed(() => (avatarUrl.value ? null : photos.value[0] ?? null))
// Grid abaixo da capa (cada item guarda o índice REAL na galeria p/ o lightbox):
// com avatar, mostra a galeria inteira; sem avatar, a foto[0] já é a capa.
const galleryThumbs = computed(() => {
    const list = photos.value.map((photo, index) => ({ photo, index }))
    return avatarUrl.value ? list : list.slice(1)
})
const hasVisual = computed(() => !!avatarUrl.value || photos.value.length > 0)

// Chips de status: só os que existem.
const chips = computed(() =>
    [
        props.member.activity_label,
        props.member.member_since ? `Membro desde ${props.member.member_since}` : null,
    ].filter(Boolean),
)

// "Detalhes" — grid de rótulo/valor, só os preenchidos.
const details = computed(() =>
    [
        { label: 'Faixa etária', value: props.member.age_band },
        { label: 'Cidade', value: props.member.city_label },
        { label: 'Estado civil', value: maritalLabel(props.member.marital_status) },
        { label: 'Altura', value: props.member.height },
    ].filter((d) => d.value),
)

// O estado civil chega como rótulo pronto do servidor (marital_status já é o
// label quando montado no controller); guardado por robustez se vier slug.
function maritalLabel(v) {
    return v || null
}

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
        <div class="mx-auto max-w-3xl px-4 py-6 pb-28 sm:pb-10">
            <Link :href="route('performer.members')" class="inline-flex items-center gap-1 text-sm text-limen-ink-mute no-underline hover:text-limen-ink">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" />
                </svg>
                Catálogo
            </Link>

            <!-- Capa = avatar do membro; galeria (aprovada) como miniaturas ao
                 lado. Sem avatar, a capa cai na 1ª foto. Clicar numa miniatura
                 abre o lightbox (variante completa). Vazio → estado neutro. -->
            <div v-if="hasVisual" class="mt-4 grid gap-3 sm:grid-cols-[1.4fr_1fr]">
                <!-- Capa: avatar (não entra no lightbox da galeria) OU 1ª foto -->
                <div
                    v-if="avatarUrl"
                    class="relative aspect-[3/4] overflow-hidden rounded-2xl bg-limen-surface ring-1 ring-limen-line"
                >
                    <img :src="avatarUrl" alt="Foto de perfil do membro" class="h-full w-full object-cover" />
                </div>
                <button
                    v-else
                    type="button"
                    class="relative aspect-[3/4] overflow-hidden rounded-2xl bg-limen-surface ring-1 ring-limen-line"
                    aria-label="Ampliar foto"
                    @click="openPhoto(0)"
                >
                    <img :src="heroPhoto.url" alt="Foto do membro" class="h-full w-full object-cover" />
                </button>

                <div v-if="galleryThumbs.length" class="grid grid-cols-2 gap-3 sm:grid-cols-1 sm:content-start">
                    <button
                        v-for="t in galleryThumbs"
                        :key="t.photo.id"
                        type="button"
                        class="relative aspect-[3/4] overflow-hidden rounded-xl bg-limen-surface ring-1 ring-limen-line sm:aspect-[3/2]"
                        aria-label="Ampliar foto"
                        @click="openPhoto(t.index)"
                    >
                        <img :src="t.photo.url" alt="Foto do membro" loading="lazy" class="h-full w-full object-cover" />
                    </button>
                </div>
            </div>
            <div v-else class="mt-4 grid place-items-center rounded-2xl border border-limen-line bg-limen-surface py-16 text-center">
                <p class="text-sm text-limen-ink-mute">Este membro ainda não adicionou fotos.</p>
            </div>

            <!-- Cabeçalho: nome + selo verificado + cidade. Nada de PII. -->
            <div class="mt-5">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="font-serif text-2xl text-limen-ink">{{ member.fan_alias_label }}</h1>
                    <svg
                        v-if="member.is_verified"
                        class="h-5 w-5 shrink-0 text-limen-gold"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path d="M12 2l2.4 1.8 3 .1 1 2.8 2.4 1.7-.9 2.8.9 2.8-2.4 1.7-1 2.8-3 .1L12 22l-2.4-1.8-3-.1-1-2.8L3.2 15l.9-2.8-.9-2.8 2.4-1.7 1-2.8 3-.1L12 2z" />
                        <path d="M10.4 14.6l-2-2 1.1-1.1.9.9 3-3 1.1 1.1-4.1 4.1z" fill="#181410" />
                    </svg>
                    <span
                        v-if="member.is_new"
                        class="rounded-full bg-limen-surface px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50"
                    >Novo</span>
                </div>

                <!-- Chips de status: atividade + membro desde. -->
                <div v-if="chips.length" class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="chip in chips"
                        :key="chip"
                        class="rounded-full border border-limen-line bg-limen-surface px-3 py-1 text-xs text-limen-ink-soft"
                    >{{ chip }}</span>
                </div>

                <!-- Denunciar apelido: só quando o rótulo é um apelido escolhido
                     (não o FanAlias). Discreto — ação de exceção, não de destaque. -->
                <button
                    v-if="member.nickname"
                    type="button"
                    class="mt-3 inline-flex min-h-[36px] items-center gap-1.5 text-xs text-limen-ink-mute transition-colors hover:text-limen-ink"
                    @click="reportOpen = true"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 22V4a1 1 0 0 1 1-1h11l-1.5 4L16 11H5" />
                    </svg>
                    Denunciar apelido
                </button>
            </div>

            <!-- Sobre mim (bio). -->
            <section v-if="member.bio" class="mt-6">
                <h2 class="font-serif text-lg text-limen-ink">Sobre mim</h2>
                <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-limen-ink-soft">{{ member.bio }}</p>
            </section>

            <!-- O que busco (tags). -->
            <section v-if="member.seeking?.length" class="mt-6">
                <h2 class="font-serif text-lg text-limen-ink">O que busco</h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="tag in member.seeking"
                        :key="tag"
                        class="rounded-full border border-limen-gold/40 bg-limen-gold/10 px-3 py-1 text-xs text-limen-gold"
                    >{{ tag }}</span>
                </div>
            </section>

            <!-- Interesses (tags). -->
            <section v-if="member.interests?.length" class="mt-6">
                <h2 class="font-serif text-lg text-limen-ink">Interesses</h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="tag in member.interests"
                        :key="tag"
                        class="rounded-full border border-limen-line bg-limen-surface-2 px-3 py-1 text-xs text-limen-ink-soft"
                    >{{ tag }}</span>
                </div>
            </section>

            <!-- Detalhes (grid rótulo/valor). -->
            <section v-if="details.length" class="mt-6">
                <h2 class="font-serif text-lg text-limen-ink">Detalhes</h2>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div v-for="d in details" :key="d.label" class="min-w-0">
                        <dt class="text-[11px] uppercase tracking-wide text-limen-ink-mute">{{ d.label }}</dt>
                        <dd class="truncate text-sm text-limen-ink">{{ d.value }}</dd>
                    </div>
                </dl>
            </section>

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

        <!-- Lightbox: variante COMPLETA (sem corte). -->
        <Lightbox v-model:index="lightboxIndex" :photos="photos" />

        <!-- Denúncia de apelido (só existe quando member.nickname). -->
        <ReportNicknameModal
            v-if="member.nickname"
            :show="reportOpen"
            :nickname="member.nickname"
            @close="reportOpen = false"
        />

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
