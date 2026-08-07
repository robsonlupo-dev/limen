<script setup>
// Trilha "Agora" — topo do catálogo (redesign). Uma faixa horizontal com o que
// está acontecendo AGORA, na linguagem de anéis do Instagram:
//   1. AO VIVO primeiro — anel VERMELHO (limen-live) pulsante; clique entra na
//      live. É o achado do UAT (cenário 86): a live tem de aparecer sem esforço.
//   2. Stories depois — anel DOURADO quando há story não visto, cinza quando já
//      viu todos; clique abre o viewer. Mantém a regra do Ghost Mode (o anel de
//      quem tem o perk fica dourado para sempre, porque o servidor nunca grava a
//      view — ver § dos Stories no CLAUDE.md). Substitui o antigo StoriesFeed,
//      que só fazia a parte de stories; a trilha unifica live + story.
//
// Privacidade: nada aqui é do MEMBRO. `lives` é a performer ao vivo (dado
// público, já no card); `feed` é o feed de stories do próprio membro
// (StoryVisibilityService::feedFor, direção performer→membro). Nenhuma
// superfície nova de exposição membro→performer.
const props = defineProps({
    // Lives ativas do mundo atual: [{ slug, stage_name, avatar_url }]. Vem do
    // controller (vazio com a feature de live off).
    lives: { type: Array, default: () => [] },
    // Grupos de stories de StoryVisibilityService::feedFor():
    // [{ performer: { stage_name, slug, avatar_url }, stories, has_unseen }].
    feed: { type: Array, default: () => [] },
})

// open-live: entra na live da performer. open-story: abre o viewer no índice do
// grupo (o mesmo índice que o StoryViewer consome de `feed`).
const emit = defineEmits(['open-live', 'open-story'])

function hasInvite(group) {
    return group.stories.some((s) => s.is_invite)
}

function initial(name) {
    return name?.charAt(0)?.toUpperCase() ?? '?'
}
</script>

<template>
    <div v-if="lives.length || feed.length" class="-mx-6 px-6">
        <div class="flex gap-4 overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <!-- ── AO VIVO (anel vermelho, primeiro) ── -->
            <button
                v-for="live in lives"
                :key="`live-${live.slug}`"
                type="button"
                class="flex shrink-0 flex-col items-center gap-1.5 focus:outline-none"
                @click="emit('open-live', live.slug)"
            >
                <span class="relative flex h-[62px] w-[62px] items-center justify-center rounded-full bg-limen-live p-[2px]">
                    <span class="now-live-ring pointer-events-none absolute inset-0 rounded-full ring-2 ring-limen-live" />
                    <span class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-limen-bg bg-limen-surface-2">
                        <img
                            v-if="live.avatar_url"
                            :src="live.avatar_url"
                            :alt="live.stage_name"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-lg text-limen-gold">{{ initial(live.stage_name) }}</span>
                    </span>
                </span>
                <span class="text-[10px] font-semibold uppercase tracking-wider text-limen-live">Ao vivo</span>
            </button>

            <!-- ── Stories (anel dourado/cinza) ── -->
            <button
                v-for="(group, i) in feed"
                :key="`story-${group.performer.slug}`"
                type="button"
                class="flex shrink-0 flex-col items-center gap-1.5 focus:outline-none"
                @click="emit('open-story', i)"
            >
                <span
                    class="relative flex h-[62px] w-[62px] items-center justify-center rounded-full p-[3px]"
                    :class="group.has_unseen ? 'bg-gradient-to-br from-limen-gold to-gold-light' : 'bg-limen-line'"
                >
                    <span class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-limen-bg bg-limen-surface-2">
                        <img
                            v-if="group.performer.avatar_url"
                            :src="group.performer.avatar_url"
                            :alt="group.performer.stage_name"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-lg text-limen-gold">{{ initial(group.performer.stage_name) }}</span>
                    </span>

                    <!-- Selo de convite (Sprint 12): "💌" quando o grupo traz um
                         convite para este membro. -->
                    <span
                        v-if="hasInvite(group)"
                        class="absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-limen-bg text-[11px] ring-2 ring-limen-bg"
                        aria-label="Convite"
                    >💌</span>
                </span>
                <span class="max-w-[62px] truncate text-xs text-limen-ink-mute">{{ group.performer.stage_name }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Pulso sutil do anel de "ao vivo" — só o anel externo, não o avatar. */
.now-live-ring {
    animation: now-live-pulse 1.8s ease-in-out infinite;
}
@keyframes now-live-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.45; transform: scale(1.06); }
}
@media (prefers-reduced-motion: reduce) {
    .now-live-ring { animation: none; }
}
</style>
