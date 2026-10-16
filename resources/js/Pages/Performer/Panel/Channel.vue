<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import BroadcastPanel from '@/Components/BroadcastPanel.vue'

// Painel › Canal (reorg do Painel, UAT Fase 21): compor a transmissão + o que
// já foi enviado. A leitura por membro NÃO existe por desenho — a entrega é
// derivada de `follows` na leitura e a performer não sabe quem leu; por isso
// o histórico mostra só corpo e hora.
defineProps({
    broadcastMaxLength: { type: Number, default: 1000 },
    // [{ id, body, created_at }] — as últimas enviadas por ela.
    broadcasts: { type: Array, default: () => [] },
})

// "há 2 h" / "ontem" / "3 dias" — relativo ao relógio do cliente; só leitura.
function ago(iso) {
    if (!iso) return ''
    const min = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
    if (min < 1) return 'agora'
    if (min < 60) return `há ${min} min`
    const h = Math.floor(min / 60)
    if (h < 24) return `há ${h} h`
    const d = Math.floor(h / 24)
    return d === 1 ? 'ontem' : `há ${d} dias`
}
</script>

<template>
    <AppLayout title="Canal de transmissão">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-1">
                <h1 class="font-serif text-4xl text-cream">Canal de transmissão</h1>
                <p class="text-muted text-sm max-w-3xl">
                    Uma mensagem para todos os seus seguidores. Eles leem na aba "Canais" — não é
                    um chat: quem responder abre a conversa paga com você. Sem telefone, redes ou
                    links (fica para o chat).
                </p>
            </div>

            <BroadcastPanel :max-length="broadcastMaxLength" />

            <section class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Enviadas</h2>

                <p v-if="!broadcasts.length" class="rounded-xl border border-frame bg-surface p-8 text-center text-sm text-muted">
                    Você ainda não transmitiu nada.
                </p>

                <ul v-else class="space-y-3">
                    <li
                        v-for="b in broadcasts"
                        :key="b.id"
                        class="rounded-xl border border-frame bg-surface p-5 space-y-2"
                    >
                        <p class="text-sm text-cream whitespace-pre-line">{{ b.body }}</p>
                        <p class="text-xs text-muted">{{ ago(b.created_at) }}</p>
                    </li>
                </ul>

                <p v-if="broadcasts.length" class="text-xs text-muted">
                    Quem leu não aparece — a transmissão chega a quem te segue, e a leitura é dele.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
