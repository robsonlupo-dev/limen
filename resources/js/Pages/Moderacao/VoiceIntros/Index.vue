<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import VoiceIntroPlayer from '@/Components/VoiceIntroPlayer.vue'

/**
 * Fila de moderação das intros de voz (feat/voice-intro).
 *
 * O moderador (ou admin) OUVE cada áudio pendente e aprova ou recusa (com motivo).
 * Ao contrário da fila de denúncias, aqui NÃO há PII de membro: a intro é conteúdo
 * da performer — identidade pública verificada. Só `approved` vai ao ar; a análise
 * humana é o controle contra negociação de encontro/contato por voz, que dribla o
 * filtro de texto do chat.
 */
const props = defineProps({
    intros: { type: Object, required: true },
    pendingCount: { type: Number, required: true },
})

// Estado do formulário de recusa por item (motivo).
const rejecting = reactive({})

function toggleReject(id) {
    rejecting[id] = rejecting[id] === undefined ? '' : undefined
}

function approve(intro) {
    router.patch(route('moderacao.voice-intros.update', intro.id), { status: 'approved' }, {
        preserveScroll: true,
    })
}

function reject(intro) {
    router.patch(route('moderacao.voice-intros.update', intro.id), {
        status: 'rejected',
        reject_reason: rejecting[intro.id] ?? '',
    }, {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout title="Moderação de áudios">
        <div class="mx-auto max-w-3xl space-y-6 px-6 py-10">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-serif text-3xl text-cream">Apresentações de voz</h1>
                    <p class="text-sm text-muted">{{ pendingCount }} aguardando análise.</p>
                </div>
                <Link :href="route('moderacao.reports.index')" class="text-sm text-gold/80 no-underline hover:text-gold">Denúncias &rarr;</Link>
            </div>

            <div v-if="intros.data.length === 0" class="rounded-xl border border-frame bg-surface p-10 text-center text-sm text-muted">
                Nada na fila.
            </div>

            <ul v-else class="space-y-4">
                <li
                    v-for="intro in intros.data"
                    :key="intro.id"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="font-serif text-lg text-cream">{{ intro.stage_name ?? '—' }}</p>
                            <p class="text-xs text-muted">
                                <a :href="`/performers/${intro.slug}`" target="_blank" class="text-gold/70 hover:text-gold no-underline">ver perfil</a>
                                <span v-if="intro.duration_seconds"> · {{ intro.duration_seconds }}s</span>
                            </p>
                        </div>
                        <VoiceIntroPlayer :url="intro.audio_url" label="apresentação" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-frame pt-4">
                        <Button variant="primary" size="sm" @click="approve(intro)">Aprovar</Button>
                        <Button variant="ghost" size="sm" @click="toggleReject(intro.id)">Recusar</Button>
                    </div>

                    <div v-if="rejecting[intro.id] !== undefined" class="space-y-2">
                        <textarea
                            v-model="rejecting[intro.id]"
                            rows="2"
                            maxlength="500"
                            placeholder="Motivo da recusa (a performer verá)"
                            class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold/50 focus:outline-none"
                        />
                        <Button variant="danger" size="sm" :disabled="!rejecting[intro.id]?.trim()" @click="reject(intro)">
                            Confirmar recusa
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
