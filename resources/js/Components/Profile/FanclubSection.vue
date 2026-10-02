<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { postJson, isInsufficientBalance } from '@/lib/http'
import { useInsufficientBalance } from '@/composables/useInsufficientBalance'

const { promptToBuy } = useInsufficientBalance()

// Seção de Fã-Clube no perfil público da performer (Onda 4, fork da assinatura). Mostra o
// preço do ESPECTADOR (público/VIP), o botão assinar, e a grade do set (teaser p/ quem não
// assina, destravada p/ assinante). O backend (FanclubService::memberView) decide tudo; aqui
// só renderiza e dispara assinar.
const props = defineProps({
    fanclub: { type: Object, default: null },
    slug: { type: String, required: true },
    performerName: { type: String, default: '' },
})

const page = usePage()
const role = computed(() => page.props.auth?.user?.role ?? null)
const isGuest = computed(() => !page.props.auth?.user)
const isConsumer = computed(() => role.value === 'consumer')

const busy = ref(false)
const error = ref('')

const priceBrl = computed(() => {
    const t = Number(props.fanclub?.price_tokens) || 0
    return t.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
})

async function subscribe() {
    if (!isConsumer.value || busy.value) return
    const t = props.fanclub?.price_tokens
    if (!window.confirm(`Assinar o fã-clube de ${props.performerName} por ${t} tokens/mês? Renova sozinho todo mês, debitado do seu saldo. Você pode desvincular quando quiser.`)) return
    busy.value = true
    error.value = ''
    try {
        await postJson(route('fanclub.subscribe', props.slug))
        router.reload({ only: ['fanclub'] })
    } catch (e) {
        if (isInsufficientBalance(e)) { promptToBuy('Você precisa de mais tokens para assinar o fã-clube.'); return }
        error.value = e.data?.message ?? 'Não foi possível assinar. Tente novamente.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <section v-if="fanclub && (fanclub.open || fanclub.is_subscribed)" class="rounded-2xl border border-limen-gold/30 bg-gradient-to-br from-limen-gold/10 to-transparent p-5 space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div class="space-y-0.5">
                <h3 class="font-serif text-lg text-limen-ink">Fã-clube</h3>
                <p class="text-xs text-limen-ink-mute">Acesso ao conteúdo exclusivo dela enquanto a assinatura estiver ativa.</p>
            </div>
            <span v-if="fanclub.is_subscribed" class="shrink-0 rounded-full border border-limen-gold/40 bg-limen-gold/10 px-2.5 py-1 text-xs text-limen-gold">Você assina</span>
        </div>

        <p v-if="error" class="rounded-lg border border-limen-live/40 bg-limen-live/10 px-3 py-2 text-sm text-limen-live">{{ error }}</p>

        <!-- Dona vendo o próprio perfil -->
        <div v-if="fanclub.is_owner" class="flex items-center justify-between gap-3">
            <p class="text-sm text-limen-ink-soft">Este é o seu fã-clube.</p>
            <Link :href="route('performer.fanclub')" class="rounded-lg border border-limen-gold px-4 py-2 text-sm text-limen-gold no-underline transition-opacity hover:opacity-90">Gerenciar</Link>
        </div>

        <!-- Assinante ativo -->
        <div v-else-if="fanclub.is_subscribed" class="flex items-center justify-between gap-3">
            <p class="text-sm text-limen-ink-soft">Assinatura ativa.</p>
            <Link :href="route('fanclub.mine')" class="rounded-lg border border-limen-line px-4 py-2 text-sm text-limen-ink-soft no-underline transition-colors hover:bg-limen-surface-2">Minhas assinaturas</Link>
        </div>

        <!-- Não assina ainda -->
        <div v-else-if="fanclub.open" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-limen-ink">
                <span class="font-semibold text-limen-gold">{{ fanclub.price_tokens }} tokens</span>/mês
                <span class="text-limen-ink-mute">(~{{ priceBrl }})</span>
                <span v-if="fanclub.price_tier === 'vip'" class="ml-1 rounded bg-limen-gold/15 px-1.5 py-0.5 text-[11px] text-limen-gold">preço VIP</span>
            </p>
            <button
                v-if="isConsumer"
                type="button" :disabled="busy"
                class="mi-glow inline-flex min-h-[44px] items-center justify-center rounded-lg bg-limen-gold px-6 text-sm font-semibold text-limen-bg transition-opacity hover:opacity-90 disabled:opacity-50"
                @click="subscribe"
            >{{ busy ? 'Assinando…' : 'Assinar' }}</button>
            <Link
                v-else-if="isGuest"
                :href="route('login')"
                class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-limen-gold px-6 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90"
            >Entrar para assinar</Link>
        </div>

        <!-- Grade do set (teaser p/ quem não vê; destravada p/ assinante) -->
        <div v-if="fanclub.set && fanclub.set.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <div v-for="item in fanclub.set" :key="item.id" class="relative aspect-square overflow-hidden rounded-lg border border-limen-line bg-limen-surface-2">
                <img v-if="item.image_url" :src="item.image_url" alt="" class="h-full w-full object-cover" />
                <img v-else-if="item.blur_url" :src="item.blur_url" alt="" class="h-full w-full object-cover" />
                <div v-else class="flex h-full w-full items-center justify-center text-[11px] text-limen-ink-mute">
                    {{ item.kind === 'video' ? 'Vídeo' : '—' }}
                </div>
                <div v-if="item.locked" class="absolute inset-0 flex items-center justify-center bg-limen-bg/40">
                    <svg class="h-5 w-5 text-limen-ink/80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                </div>
                <span v-if="item.kind === 'video'" class="absolute right-1 top-1 rounded bg-limen-bg/70 px-1 py-0.5 text-[10px] text-limen-ink">vídeo</span>
            </div>
        </div>
        <p v-else-if="fanclub.open && !fanclub.is_owner" class="rounded-lg border border-limen-line bg-limen-surface-2 px-4 py-6 text-center text-sm text-limen-ink-mute">
            O set ainda está vazio.
        </p>
    </section>
</template>
