<script setup>
import { reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { deleteJson, postJson, putJson } from '@/lib/http'

const props = defineProps({
    followers: { type: Object, required: true },
    remainingToday: { type: Number, required: true },
    dailyLimit: { type: Number, required: true },
    cooldownDays: { type: Number, required: true },
    below_floor: { type: Boolean, default: false },
    total_followers_label: { type: String, default: 'Menos de 5' },
    floor_message: { type: String, default: null },
})

const remaining = ref(props.remainingToday)
// Envios feitos nesta sessão, por handle — evita recarregar a página só
// para o botão refletir o estado.
const justSent = ref({})
const sendingHandle = ref(null)
const errorFor = ref({})
const toastMessage = ref('')

function alreadySent(follower) {
    return follower.interest_sent || justSent.value[follower.member_handle]
}

async function sendInterest(follower) {
    if (alreadySent(follower) || remaining.value <= 0) return

    errorFor.value = { ...errorFor.value, [follower.member_handle]: '' }
    sendingHandle.value = follower.member_handle

    try {
        await postJson(route('performer.interests.send'), { member_handle: follower.member_handle })

        justSent.value[follower.member_handle] = true
        remaining.value = Math.max(0, remaining.value - 1)
        toastMessage.value = 'Interesse enviado'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        const message =
            error.status === 429
                ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                : (error.data?.message ?? 'Não foi possível enviar. Tente novamente.')

        errorFor.value = { ...errorFor.value, [follower.member_handle]: message }
    } finally {
        sendingHandle.value = null
    }
}

// Notas privadas sobre o membro (Sprint 11). Estado local por handle, semeado
// dos props — a performer NUNCA vê o id, só o pseudônimo. O membro nunca vê nada
// disto: é a tela dela.
const notes = reactive(
    Object.fromEntries(props.followers.data.map((f) => [f.member_handle, f.note ?? null])),
)

const noteModal = reactive({
    open: false,
    handle: null,
    label: '',
    draft: '',
    saving: false,
    error: '',
})

function hasNote(follower) {
    return Boolean(notes[follower.member_handle])
}

function openNote(follower) {
    noteModal.open = true
    noteModal.handle = follower.member_handle
    noteModal.label = follower.label
    noteModal.draft = notes[follower.member_handle] ?? ''
    noteModal.error = ''
    noteModal.saving = false
}

function closeNote() {
    noteModal.open = false
    noteModal.handle = null
}

async function saveNote() {
    if (noteModal.saving) return
    const handle = noteModal.handle
    const text = noteModal.draft.trim()

    // Campo esvaziado = apagar: PUT exige conteúdo não-vazio, então a escolha é
    // do front — se havia nota, DELETE; se não havia, nada a fazer.
    if (text === '') {
        if (notes[handle]) {
            await removeNote(handle)
        } else {
            closeNote()
        }
        return
    }

    noteModal.error = ''
    noteModal.saving = true
    try {
        await putJson(route('performer.notes.save', handle), { content: text })
        notes[handle] = text
        toastMessage.value = 'Nota salva'
        setTimeout(() => (toastMessage.value = ''), 4000)
        closeNote()
    } catch (error) {
        noteModal.error =
            error.status === 429
                ? 'Muitas edições em pouco tempo. Aguarde um instante.'
                : (error.data?.message ?? 'Não foi possível salvar a nota. Tente novamente.')
    } finally {
        noteModal.saving = false
    }
}

async function removeNote(handle) {
    noteModal.error = ''
    noteModal.saving = true
    try {
        await deleteJson(route('performer.notes.destroy', handle))
        notes[handle] = null
        toastMessage.value = 'Nota removida'
        setTimeout(() => (toastMessage.value = ''), 4000)
        closeNote()
    } catch (error) {
        noteModal.error = error.data?.message ?? 'Não foi possível remover a nota. Tente novamente.'
    } finally {
        noteModal.saving = false
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
                <div class="flex items-center gap-4 shrink-0">
                    <Link :href="route('performer.notes.index')" class="text-sm text-gold hover:text-gold-light transition-colors">
                        Minhas notas
                    </Link>
                    <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors">
                        Voltar ao painel
                    </Link>
                </div>
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
                    <p class="text-muted text-xs">Seguidores até agora: {{ total_followers_label }}.</p>
                </div>

                <div
                    v-else-if="followers.data.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Seu Portal ainda não foi descoberto.</p>
                    <p class="text-muted text-sm">Complete seu perfil para aparecer no catálogo.</p>
                </div>

                <div
                    v-for="follower in followers.data"
                    :key="follower.member_handle"
                    class="rounded-xl border border-frame bg-surface p-5 flex items-center gap-4"
                >
                    <div class="h-12 w-12 rounded-full bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                        <span class="font-serif text-lg text-gold/60" aria-hidden="true">M</span>
                    </div>

                    <div class="flex-1 min-w-0 space-y-0.5">
                        <!-- Estilo de Vida ao lado do apelido, quando o membro
                             declarou. `v-if` e não fallback: sem faixa não sai
                             nada — um "não informou" diria que ele viu o
                             formulário e recusou, que é informação sobre ele. -->
                        <p class="text-cream">
                            {{ follower.label }}<span v-if="follower.lifestyle" class="text-muted"> · {{ follower.lifestyle }}</span>
                        </p>
                        <p class="text-xs text-muted">Segue desde {{ follower.following_since }}</p>
                        <p v-if="errorFor[follower.member_handle]" class="text-xs text-danger pt-1">
                            {{ errorFor[follower.member_handle] }}
                        </p>
                    </div>

                    <div class="shrink-0 flex items-center gap-2">
                        <!-- Nota privada (Sprint 11). Ícone preenchido quando já
                             existe nota; o membro nunca vê nada disto. -->
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full border transition-colors"
                            :class="hasNote(follower)
                                ? 'border-gold/60 bg-gold/10 text-gold'
                                : 'border-frame text-muted hover:border-gold/40 hover:text-gold'"
                            :title="hasNote(follower) ? 'Editar nota privada' : 'Adicionar nota privada'"
                            :aria-label="hasNote(follower) ? 'Editar nota privada' : 'Adicionar nota privada'"
                            @click="openNote(follower)"
                        >
                            <svg viewBox="0 0 24 24" class="h-4 w-4" aria-hidden="true"
                                 :fill="hasNote(follower) ? 'currentColor' : 'none'"
                                 stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v9A1.5 1.5 0 0118.5 16H9l-5 4V5.5z" />
                            </svg>
                        </button>

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
                            :loading="sendingHandle === follower.member_handle"
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

        <!-- Modal da nota privada (Sprint 11). Textarea simples; o membro nunca
             vê o conteúdo. -->
        <div
            v-if="noteModal.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="closeNote"
        >
            <div class="w-full max-w-lg rounded-2xl border border-frame bg-surface p-6 space-y-4 shadow-2xl">
                <div class="space-y-1">
                    <h2 class="font-serif text-2xl text-cream">Nota sobre {{ noteModal.label }}</h2>
                    <p class="text-muted text-xs">
                        Anotação privada, só sua. {{ noteModal.label }} nunca vê o que você escreve aqui.
                    </p>
                </div>

                <textarea
                    v-model="noteModal.draft"
                    rows="6"
                    maxlength="5000"
                    placeholder="Detalhes de conversa, preferências, o que quiser lembrar…"
                    class="w-full rounded-xl border border-frame bg-surface-2 p-3 text-sm text-cream placeholder:text-muted/60 focus:border-gold/50 focus:outline-none"
                ></textarea>

                <p v-if="noteModal.error" class="text-xs text-danger">{{ noteModal.error }}</p>

                <div class="flex items-center justify-between gap-3 pt-1">
                    <button
                        v-if="notes[noteModal.handle]"
                        type="button"
                        class="text-sm text-danger hover:opacity-80 transition-opacity disabled:opacity-40"
                        :disabled="noteModal.saving"
                        @click="removeNote(noteModal.handle)"
                    >
                        Remover nota
                    </button>
                    <span v-else></span>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="text-sm text-muted hover:text-cream transition-colors"
                            :disabled="noteModal.saving"
                            @click="closeNote"
                        >
                            Cancelar
                        </button>
                        <Button variant="primary" size="sm" :loading="noteModal.saving" @click="saveNote">
                            Salvar
                        </Button>
                    </div>
                </div>
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
