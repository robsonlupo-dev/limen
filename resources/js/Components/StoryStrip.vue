<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import ReportModal from '@/Components/ReportModal.vue'

/**
 * Faixa de Stories no perfil da performer — compartilhada pelas DUAS telas de
 * perfil (Catalog/Show para o membro, Performers/Show para a porta pública).
 *
 * Componente único de propósito: o cadeado por nível e o CTA de bloqueado são a
 * cara do paywall, e duas cópias divergiriam justamente aí — a que divergisse
 * provavelmente seria a que mostra o conteúdo.
 *
 * ── O que este componente NÃO faz ───────────────────────────────────────────
 * Não decide nada. `locked` chega resolvido do `StoryVisibilityService`, que
 * reavalia follow e tier no request (§ 2.3), e **story fechado chega sem
 * `image_url`**: não existe miniatura borrada do conteúdo pago, porque blur em
 * CSS não é paywall — uma imagem de verdade entregue "borrada" está inteira no
 * DevTools em dois cliques. O que se desenha no lugar é um placeholder.
 *
 * O `seen` é dado do próprio espectador. Nada aqui informa a performer: ela
 * recebe só a FAIXA de membros únicos (§ 2.1/§ 2.2), nunca quem viu.
 */
const props = defineProps({
    // [{ id, visibility_level, locked, seen, image_url|null }]
    stories: { type: Array, default: () => [] },
    performerName: { type: String, required: true },
    // Rota do CTA de bloqueado: assinar (membro logado) ou cadastro (visitante).
    lockedHref: { type: String, required: true },
    lockedLabel: { type: String, default: 'Assine para ver' },
    // Denunciar exige conta (POST /reportar é autenticado) e exige ter VISTO o
    // story — o servidor recusa o que o espectador não alcança
    // (`Report::visibleTo`), então o botão só aparece no story aberto.
    canReport: { type: Boolean, default: false },
})

const open = ref(null)
const reporting = ref(null)

const hasStories = computed(() => props.stories.length > 0)

// Cadeado por nível: público sem nada, assinantes com cadeado discreto,
// exclusivo com cadeado dourado. O nível é dado da performer (ela escolheu ao
// publicar), não do espectador — exibi-lo é o upsell do Modelo C, o mesmo que o
// 403 do serving já diz.
const LOCK_BY_LEVEL = {
    public: null,
    subscribers: { icon: '🔒', class: 'text-muted', label: 'Para assinantes' },
    exclusive: { icon: '🔒', class: 'text-gold', label: 'Exclusivo Black e Founders' },
}

function lockFor(level) {
    return LOCK_BY_LEVEL[level] ?? null
}
</script>

<template>
    <div v-if="hasStories" class="mt-8 space-y-3">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="font-serif text-xl text-cream">Stories</h2>
            <span class="text-xs text-muted">somem em 24 horas</span>
        </div>

        <div class="flex flex-wrap gap-3">
            <template v-for="story in stories" :key="story.id">
                <!-- Aberto: miniatura de verdade, servida pela rota autenticada
                     por sessão. Abre em overlay; sem botão de baixar. -->
                <button
                    v-if="!story.locked"
                    type="button"
                    class="relative h-24 w-24 shrink-0 overflow-hidden rounded-xl border-2 bg-surface-2 transition-colors"
                    :class="story.seen ? 'border-frame' : 'border-gold'"
                    :aria-label="story.seen ? 'Story já visto' : 'Story não visto'"
                    @click="open = story"
                >
                    <img :src="story.image_url" alt="" class="h-full w-full object-cover" />
                    <span
                        v-if="lockFor(story.visibility_level)"
                        class="absolute bottom-1 right-1 text-xs"
                        :class="lockFor(story.visibility_level).class"
                        :title="lockFor(story.visibility_level).label"
                        aria-hidden="true"
                    >
                        {{ lockFor(story.visibility_level).icon }}
                    </span>
                </button>

                <!-- Fechado: placeholder, NUNCA o conteúdo. Não há `image_url`
                     aqui — o servidor não a manda (ver o docblock). -->
                <Link
                    v-else
                    :href="lockedHref"
                    class="group relative flex h-24 w-24 shrink-0 flex-col items-center justify-center gap-1 rounded-xl border border-frame bg-gradient-to-br from-surface-2 to-background no-underline"
                >
                    <span
                        class="text-xl"
                        :class="lockFor(story.visibility_level)?.class ?? 'text-muted'"
                        aria-hidden="true"
                    >🔒</span>
                    <span class="px-1.5 text-center text-[10px] leading-tight text-muted group-hover:text-gold transition-colors">
                        {{ lockedLabel }}
                    </span>
                </Link>
            </template>
        </div>

        <!-- Overlay. Content-Disposition: inline e no-store no endpoint; a tela
             não oferece download (o que não impede um print — e a copy não
             promete o que o TTL não entrega). -->
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-background/95 p-6"
            @click="open = null"
        >
            <div class="max-w-lg space-y-3 text-center" @click.stop>
                <img
                    :src="open.image_url"
                    :alt="`Story de ${performerName}`"
                    class="max-h-[70vh] w-auto rounded-xl border border-frame"
                />
                <div class="flex items-center justify-center gap-4">
                    <button class="text-xs text-muted hover:text-cream" @click="open = null">Fechar</button>
                    <!-- Canal de compliance (§ 2.4): sem ele o story é
                         "denunciável" só no papel — nenhum membro alcançaria o
                         endpoint. Discreto de propósito, como no perfil: precisa
                         existir e ser achável, não competir com o conteúdo.
                         Denunciar CONGELA o GC e a deleção manual daquele story. -->
                    <button
                        v-if="canReport"
                        type="button"
                        class="text-xs text-muted hover:text-danger"
                        @click="reporting = open"
                    >
                        Denunciar
                    </button>
                </div>
            </div>
        </div>

        <ReportModal
            v-if="reporting"
            :show="reporting !== null"
            reportable-type="performer_story"
            :reportable-id="reporting.id"
            @close="reporting = null"
        />
    </div>
</template>
