<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'
import Modal from '@/Components/Modal.vue'

/**
 * Fila de CONTEÚDO SINALIZADO (feat/flagged-content-queue, Fase 4c).
 *
 * A moderação age por REINCIDÊNCIA: cada cartão é um USUÁRIO com conduta
 * auto-bloqueada no chat, ordenado por nº de sinalizações. O corpo das mensagens
 * NUNCA chega aqui (decisão de LGPD/PO) — só a contagem, as fontes e a recência.
 * O moderador adverte, suspende ou dispensa; dispensar tira o usuário da fila até
 * um novo flag.
 */
const props = defineProps({
    // Paginator: { data: [{ user_id, role, flag_count, last_at, sources[] }], links }
    flagged: { type: Object, required: true },
    flaggedUserCount: { type: Number, default: 0 },
})

const ROLE_LABELS = { consumer: 'Membro', performer: 'Performer' }
const SOURCE_LABELS = { chat: 'Chat', live_chat: 'Chat ao vivo', profile_text: 'Perfil', nickname: 'Apelido' }

function roleLabel(role) {
    return ROLE_LABELS[role] ?? role
}
function sourceLabel(source) {
    return SOURCE_LABELS[source] ?? source
}
function lastAtLabel(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}

// Modal de ação (advertir/suspender) atrelado a um usuário da lista.
const activeUser = ref(null)
const activeAction = ref(null) // 'warn' | 'suspend'

const warnForm = useForm({ reason: '' })
const suspendForm = useForm({ reason: '', days: 3 })
const dismissForm = useForm({})

const modalTitle = computed(() => {
    if (!activeUser.value) return ''
    const who = `${roleLabel(activeUser.value.role)} #${activeUser.value.user_id}`
    return activeAction.value === 'warn' ? `Advertir ${who}` : `Suspender ${who}`
})

function openWarn(row) {
    activeUser.value = row
    activeAction.value = 'warn'
    warnForm.clearErrors()
    warnForm.reason = ''
}
function openSuspend(row) {
    activeUser.value = row
    activeAction.value = 'suspend'
    suspendForm.clearErrors()
    suspendForm.reason = ''
    suspendForm.days = 3
}
function closeModal() {
    if (warnForm.processing || suspendForm.processing) return
    activeUser.value = null
    activeAction.value = null
}

function submitWarn() {
    warnForm.post(route('moderacao.flagged.warn', activeUser.value.user_id), {
        preserveScroll: true,
        onSuccess: closeModal,
    })
}
function submitSuspend() {
    suspendForm.post(route('moderacao.flagged.suspend', activeUser.value.user_id), {
        preserveScroll: true,
        onSuccess: closeModal,
    })
}
function dismiss(row) {
    dismissForm.post(route('moderacao.flagged.dismiss', row.user_id), { preserveScroll: true })
}
</script>

<template>
    <ModeratorLayout title="Moderação · Conteúdo sinalizado">
        <div class="mx-auto max-w-4xl space-y-6 px-6 py-10">
            <div class="space-y-1">
                <h1 class="font-serif text-3xl text-cream">Conteúdo sinalizado</h1>
                <p class="text-sm text-muted">
                    {{ flaggedUserCount }} usuário{{ flaggedUserCount === 1 ? '' : 's' }} com conduta auto-bloqueada.
                    A fila agrega por <strong class="text-cream/80">reincidência</strong> — o corpo das mensagens não é exibido.
                </p>
            </div>

            <!-- Vazio -->
            <div
                v-if="flagged.data.length === 0"
                class="rounded-xl border border-frame/60 bg-surface/30 py-16 text-center text-sm text-muted"
            >
                Nenhum conteúdo sinalizado pendente. Tudo limpo por aqui.
            </div>

            <!-- Lista de usuários sinalizados -->
            <ul v-else class="space-y-3">
                <li
                    v-for="row in flagged.data"
                    :key="row.user_id"
                    class="rounded-xl border border-frame/60 bg-surface p-5"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex items-center gap-2">
                                <span class="font-serif text-lg text-cream">{{ roleLabel(row.role) }}</span>
                                <span class="font-mono text-xs text-muted/70">#{{ row.user_id }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-for="s in row.sources"
                                    :key="s"
                                    class="rounded-full border border-frame px-2 py-0.5 text-[11px] text-muted"
                                >{{ sourceLabel(s) }}</span>
                                <span class="text-xs text-muted/70">· última em {{ lastAtLabel(row.last_at) }}</span>
                            </div>
                        </div>
                        <span
                            class="shrink-0 rounded-full border border-gold/50 bg-gold/10 px-3 py-1 text-sm font-semibold text-gold"
                            :title="`${row.flag_count} sinalização(ões) pendente(s)`"
                        >
                            {{ row.flag_count }} flag{{ row.flag_count === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <!-- Ações: advertir / suspender / dispensar. Alvos >=44px. -->
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="min-h-[40px] rounded-lg border border-frame px-3 text-sm text-cream/90 transition-colors hover:border-gold/60 hover:text-gold"
                            @click="openWarn(row)"
                        >
                            Advertir
                        </button>
                        <button
                            type="button"
                            class="min-h-[40px] rounded-lg border border-danger/50 px-3 text-sm text-danger transition-colors hover:bg-danger/10"
                            @click="openSuspend(row)"
                        >
                            Suspender
                        </button>
                        <button
                            type="button"
                            :disabled="dismissForm.processing"
                            class="ml-auto min-h-[40px] rounded-lg px-3 text-sm text-muted transition-colors hover:text-cream disabled:opacity-50"
                            @click="dismiss(row)"
                        >
                            Dispensar
                        </button>
                    </div>
                </li>
            </ul>

            <!-- Paginação -->
            <div v-if="flagged.links && flagged.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-2">
                <template v-for="(link, i) in flagged.links" :key="i">
                    <span
                        v-if="!link.url"
                        class="px-3 py-1.5 text-sm text-muted/50"
                        v-html="link.label"
                    />
                    <Link
                        v-else
                        :href="link.url"
                        preserve-scroll
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition-colors',
                            link.active ? 'bg-gold text-background' : 'border border-frame text-muted hover:text-cream',
                        ]"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- Modal de advertir/suspender -->
        <Modal :show="activeUser !== null" @close="closeModal">
            <div class="space-y-4 p-6">
                <h2 class="font-serif text-xl text-cream">{{ modalTitle }}</h2>

                <!-- Advertir -->
                <form v-if="activeAction === 'warn'" class="space-y-4" @submit.prevent="submitWarn">
                    <div>
                        <label for="warn-reason" class="text-xs uppercase tracking-wide text-muted">Motivo</label>
                        <textarea
                            id="warn-reason"
                            v-model="warnForm.reason"
                            rows="3"
                            maxlength="500"
                            placeholder="Por que esta advertência? (fica na trilha, não vai ao usuário cru)"
                            class="mt-1 w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted/60 focus:border-gold focus:outline-none"
                        />
                        <p v-if="warnForm.errors.reason" class="mt-1 text-xs text-danger">{{ warnForm.errors.reason }}</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="min-h-[40px] rounded-lg px-4 text-sm text-muted hover:text-cream" @click="closeModal">Cancelar</button>
                        <button
                            type="submit"
                            :disabled="warnForm.processing || warnForm.reason.trim() === ''"
                            class="min-h-[40px] rounded-lg bg-gold px-4 text-sm font-medium text-background transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            {{ warnForm.processing ? 'Registrando...' : 'Advertir' }}
                        </button>
                    </div>
                </form>

                <!-- Suspender -->
                <form v-else-if="activeAction === 'suspend'" class="space-y-4" @submit.prevent="submitSuspend">
                    <div>
                        <label for="suspend-reason" class="text-xs uppercase tracking-wide text-muted">Motivo</label>
                        <textarea
                            id="suspend-reason"
                            v-model="suspendForm.reason"
                            rows="3"
                            maxlength="500"
                            placeholder="Por que a suspensão? (fica na trilha)"
                            class="mt-1 w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted/60 focus:border-gold focus:outline-none"
                        />
                        <p v-if="suspendForm.errors.reason" class="mt-1 text-xs text-danger">{{ suspendForm.errors.reason }}</p>
                    </div>
                    <div>
                        <label for="suspend-days" class="text-xs uppercase tracking-wide text-muted">Dias (1–90)</label>
                        <input
                            id="suspend-days"
                            v-model.number="suspendForm.days"
                            type="number"
                            min="1"
                            max="90"
                            class="mt-1 w-28 rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                        />
                        <p v-if="suspendForm.errors.days" class="mt-1 text-xs text-danger">{{ suspendForm.errors.days }}</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="min-h-[40px] rounded-lg px-4 text-sm text-muted hover:text-cream" @click="closeModal">Cancelar</button>
                        <button
                            type="submit"
                            :disabled="suspendForm.processing || suspendForm.reason.trim() === ''"
                            class="min-h-[40px] rounded-lg border border-danger/60 bg-danger/10 px-4 text-sm font-medium text-danger transition-colors hover:bg-danger/20 disabled:opacity-50"
                        >
                            {{ suspendForm.processing ? 'Suspendendo...' : 'Suspender' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </ModeratorLayout>
</template>
