<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    conversations: { type: Object, required: true }, // paginator
    accessCost: { type: Number, required: true },
    // A performer vê o MEMBRO (FanAlias) em cada linha; o membro vê a performer.
    // O título já vem resolvido por lado no `c.title`; este flag é só para a copy.
    viewerIsPerformer: { type: Boolean, default: false },
})

const page = usePage()
const myId = computed(() => page.props.auth.user?.id)

// Lista reativa local: parte das props e é MUTADA pelos eventos user.{id} p/
// atualizar preview/badge/timestamp sem recarregar. Clona raso cada item p/ não
// mutar o objeto da prop. Ressincroniza quando as props mudam (paginação/visita).
const items = ref(props.conversations.data.map((c) => ({ ...c })))
watch(
    () => props.conversations.data,
    (data) => { items.value = data.map((c) => ({ ...c })) },
)

function when(iso) {
    if (!iso) return ''
    const d = new Date(iso)
    const today = new Date()
    const sameDay = d.toDateString() === today.toDateString()
    return sameDay
        ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}

let channel = null

onMounted(() => {
    // Tempo real da lista: canal pessoal user.{id}. Sem Echo (Reverb não
    // configurado) a lista fica estática até a próxima visita — degrada limpo.
    if (window.Echo && myId.value) {
        channel = window.Echo.private(`user.${myId.value}`)
        // broadcastAs() = 'new.message' → o ponto inicial ignora o namespace.
        channel.listen('.new.message', (e) => {
            const conv = items.value.find((c) => c.id === e.conversation_id)
            if (!conv) {
                // Conversa ainda não está na lista (ex.: a 1ª mensagem abre o
                // canal). Busca leve só das conversas p/ trazê-la — é um partial
                // reload do Inertia, não um reload de página inteira.
                router.reload({ only: ['conversations'] })
                return
            }
            conv.last_message_at = e.occurred_at
            // `locked` distingue GANCHO (teaser cortado no servidor) de mensagem
            // legível: sem leitura, e.preview já vem como teaser e e.locked=true;
            // com leitura, preview normal e locked=false. O corpo completo nunca
            // chega aqui para quem não pagou — o corte é server-side.
            conv.last_message_preview = e.preview ?? null
            conv.locked = !!e.locked
            if (e.increments_unread) conv.unread_count = (conv.unread_count ?? 0) + 1
            // Lista ordenada por last_message_at desc → a conversa sobe p/ o topo.
            items.value = [conv, ...items.value.filter((c) => c.id !== conv.id)]
        })
    }
})

onBeforeUnmount(() => {
    if (channel && myId.value) window.Echo?.leave(`user.${myId.value}`)
})
</script>

<template>
    <AppLayout title="Mensagens">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8">
            <h1 class="font-serif text-2xl text-cream mb-6">Mensagens</h1>

            <p v-if="items.length === 0" class="text-sm text-muted py-12 text-center">
                {{ viewerIsPerformer
                    ? 'Nenhuma conversa ainda. Elas aparecem aqui quando um membro escreve para você ou você inicia pelo catálogo.'
                    : 'Você ainda não tem conversas. Elas aparecem aqui quando você escreve para uma performer ou uma performer fala com você.' }}
            </p>

            <ul v-else class="divide-y divide-frame/50 rounded-2xl border border-frame/60 overflow-hidden">
                <li v-for="c in items" :key="c.id">
                    <Link
                        :href="route('chat.show', c.id)"
                        class="flex items-center gap-4 px-4 py-4 no-underline hover:bg-surface/60 transition-colors"
                    >
                        <!-- Avatar (inicial do OUTRO participante) -->
                        <div class="h-12 w-12 shrink-0 rounded-full border border-gold/40 bg-surface-2 flex items-center justify-center">
                            <span class="font-serif text-lg text-gold">{{ c.title?.charAt(0) }}</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-cream font-medium truncate">{{ c.title }}</span>
                                <span class="text-xs text-muted shrink-0">{{ when(c.last_message_at) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-0.5">
                                <p class="text-sm text-muted truncate">
                                    <!-- Bloqueada COM gancho: primeiras palavras em
                                         claro + convite ao desbloqueio. Sem gancho
                                         (sem corpo): só o cadeado. -->
                                    <template v-if="c.locked && c.last_message_preview">
                                        <span class="text-cream/90">{{ c.last_message_preview }}</span>
                                        <span class="text-gold/70"> · desbloqueie para ler</span>
                                    </template>
                                    <span v-else-if="c.locked" class="text-gold/70">🔒 Renove para ler</span>
                                    <span v-else-if="c.last_message_preview">{{ c.last_message_preview }}</span>
                                    <span v-else class="italic">Sem mensagens</span>
                                </p>
                                <!-- Badge de não lidas -->
                                <span
                                    v-if="c.unread_count > 0"
                                    class="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-gold text-background text-xs font-medium flex items-center justify-center"
                                >
                                    {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
