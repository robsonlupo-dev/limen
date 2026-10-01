<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { postJson } from '@/lib/http'
import { formatTokens } from '@/lib/tokens'

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    balance: { type: Number, default: 0 },
})

const busy = ref('')
const error = ref('')

const STATUS = {
    active: { label: 'Ativa', cls: 'border-limen-gold/40 bg-limen-gold/10 text-limen-gold' },
    paused: { label: 'Pausada (sem saldo)', cls: 'border-limen-live/40 bg-limen-live/10 text-limen-live' },
}
function statusInfo(s) { return STATUS[s] ?? { label: s, cls: 'border-limen-line text-limen-ink-mute' } }

function fmtDate(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: 'long' })
}

async function cancel(sub) {
    if (busy.value) return
    if (!window.confirm(`Desvincular do fã-clube de ${sub.performer.stage_name}? Você mantém o acesso até o fim do ciclo já pago; depois não renova.`)) return
    busy.value = sub.performer.slug
    error.value = ''
    try {
        await postJson(route('fanclub.cancel', sub.performer.slug))
        router.reload({ only: ['subscriptions'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível desvincular. Tente novamente.'
    } finally {
        busy.value = ''
    }
}
</script>

<template>
    <AppLayout title="Minhas assinaturas">
        <div class="mx-auto max-w-2xl px-6 py-10 space-y-6">
            <div class="flex items-center justify-between gap-4">
                <h1 class="font-serif text-3xl text-limen-ink">Minhas assinaturas</h1>
                <span class="text-sm text-limen-ink-mute">Saldo: <span class="text-limen-gold">{{ formatTokens(balance) }}</span> tokens</span>
            </div>

            <p v-if="error" class="rounded-lg border border-limen-live/40 bg-limen-live/10 px-4 py-2 text-sm text-limen-live">{{ error }}</p>

            <p v-if="!subscriptions.length" class="rounded-2xl border border-limen-line bg-limen-surface px-6 py-10 text-center text-sm text-limen-ink-mute">
                Você ainda não assina nenhum fã-clube. Encontre performers no catálogo e assine o clube delas.
            </p>

            <ul v-else class="space-y-3">
                <li v-for="sub in subscriptions" :key="sub.performer.slug" class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="route('catalog.show', sub.performer.slug)" class="font-medium text-limen-ink no-underline hover:text-limen-gold">
                                {{ sub.performer.stage_name }}
                            </Link>
                            <p class="mt-0.5 text-xs text-limen-ink-mute">
                                {{ formatTokens(sub.price_tokens) }} tokens/mês
                                <span v-if="sub.price_tier === 'vip'" class="ml-1 rounded bg-limen-gold/15 px-1.5 py-0.5 text-[11px] text-limen-gold">VIP</span>
                                <template v-if="sub.renews_at"> · renova em {{ fmtDate(sub.renews_at) }}</template>
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs" :class="statusInfo(sub.status).cls">{{ statusInfo(sub.status).label }}</span>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button
                            type="button" :disabled="busy === sub.performer.slug"
                            class="rounded-lg border border-limen-line px-4 py-2 text-xs text-limen-ink-soft transition-colors hover:bg-limen-surface-2 disabled:opacity-50"
                            @click="cancel(sub)"
                        >{{ busy === sub.performer.slug ? 'Desvinculando…' : 'Desvincular' }}</button>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
