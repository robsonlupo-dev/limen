<script setup>
// Carrossel de Stories no topo do catálogo (Sprint 13) — a UI que faltava para
// o endpoint `stories.feed` (existe e é testado desde o PR #106).
//
// Círculos tipo Instagram Stories: avatar da performer, borda dourada quando há
// story não visto e cinza quando já viu todos, nome abaixo e "💌" quando o grupo
// traz um convite. A ausência de story NÃO visto derivada do servidor
// (`has_unseen`) — para membro Black/FC com Ghost Mode o servidor nunca grava a
// view, então o círculo fica dourado para sempre, e isso é o perk funcionando
// (ver § dos Stories no CLAUDE.md).
const props = defineProps({
    // Grupos vindos de StoryVisibilityService::feedFor(): cada um com
    // { performer: { stage_name, slug, avatar_url }, stories: [...], has_unseen }.
    feed: { type: Array, default: () => [] },
})

const emit = defineEmits(['open'])

function hasInvite(group) {
    return group.stories.some((s) => s.is_invite)
}

function initial(group) {
    return group.performer.stage_name?.charAt(0)?.toUpperCase() ?? '?'
}
</script>

<template>
    <div v-if="feed.length" class="-mx-6 px-6">
        <div class="flex gap-4 overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <button
                v-for="(group, i) in feed"
                :key="group.performer.slug"
                type="button"
                class="flex shrink-0 flex-col items-center gap-1.5 focus:outline-none"
                @click="emit('open', i)"
            >
                <span
                    class="relative flex h-[68px] w-[68px] items-center justify-center rounded-full p-[3px] transition-colors"
                    :class="group.has_unseen ? 'bg-gradient-to-br from-gold to-gold-light' : 'bg-frame'"
                >
                    <span class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-background bg-surface-2">
                        <img
                            v-if="group.performer.avatar_url"
                            :src="group.performer.avatar_url"
                            :alt="group.performer.stage_name"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-xl text-gold">{{ initial(group) }}</span>
                    </span>

                    <!-- Selo de convite (Sprint 12): "💌" quando o grupo traz um
                         Story marcado como convite para este membro. -->
                    <span
                        v-if="hasInvite(group)"
                        class="absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-background text-[11px] ring-2 ring-background"
                        aria-label="Convite"
                    >💌</span>
                </span>
                <span class="max-w-[68px] truncate text-xs text-muted">{{ group.performer.stage_name }}</span>
            </button>
        </div>
    </div>
</template>
