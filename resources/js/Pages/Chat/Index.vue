<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    conversations: { type: Object, required: true }, // paginator
    accessCost: { type: Number, required: true },
})

function when(iso) {
    if (!iso) return ''
    const d = new Date(iso)
    const today = new Date()
    const sameDay = d.toDateString() === today.toDateString()
    return sameDay
        ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}
</script>

<template>
    <AppLayout title="Mensagens">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8">
            <h1 class="font-serif text-2xl text-cream mb-6">Mensagens</h1>

            <p v-if="conversations.data.length === 0" class="text-sm text-muted py-12 text-center">
                Você ainda não tem conversas. Elas aparecem aqui quando uma performer demonstra interesse e você desbloqueia.
            </p>

            <ul v-else class="divide-y divide-frame/50 rounded-2xl border border-frame/60 overflow-hidden">
                <li v-for="c in conversations.data" :key="c.id">
                    <Link
                        :href="route('chat.show', c.id)"
                        class="flex items-center gap-4 px-4 py-4 no-underline hover:bg-surface/60 transition-colors"
                    >
                        <!-- Avatar (inicial) -->
                        <div class="h-12 w-12 shrink-0 rounded-full border border-gold/40 bg-surface-2 flex items-center justify-center">
                            <span class="font-serif text-lg text-gold">{{ c.performer.stage_name?.charAt(0) }}</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-cream font-medium truncate">{{ c.performer.stage_name }}</span>
                                <span class="text-xs text-muted shrink-0">{{ when(c.last_message_at) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-0.5">
                                <p class="text-sm text-muted truncate">
                                    <span v-if="c.locked" class="text-gold/70">🔒 Renove para ler</span>
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
