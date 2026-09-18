<script setup>
import { useForm } from '@inertiajs/vue3'

// Seguir é uma ação TERCIÁRIA da barra do perfil (fix/uat-content-and-gifts,
// fix 6): estilo neutro em limen-*, nunca dourado-preenchido — o dourado cheio
// é exclusivo da primária (Conversar). Ativo ("Seguindo") ganha só um tom
// dourado suave para marcar estado, sem competir com a primária.
const props = defineProps({
    slug: { type: String, required: true },
    following: { type: Boolean, required: true },
    reloadOnly: { type: Array, default: undefined },
    size: { type: String, default: 'md' },
    // Rótulo curto ("Seguindo") para caber numa linha na grade de ações do perfil
    // (item 4): "Deixar de seguir" quebrava em 3 linhas.
    short: { type: Boolean, default: false },
})

const form = useForm({})

function toggle() {
    const options = {
        preserveScroll: true,
        only: props.reloadOnly,
    }

    if (props.following) {
        form.delete(route('catalog.unfollow', props.slug), options)
    } else {
        form.post(route('catalog.follow', props.slug), options)
    }
}
</script>

<template>
    <button
        type="button"
        :aria-pressed="following"
        :disabled="form.processing"
        class="mi-press inline-flex items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60 disabled:opacity-60"
        :class="following
            ? 'border-limen-gold/40 bg-limen-gold/10 text-limen-gold'
            : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/40 hover:text-limen-ink'"
        @click="toggle"
    >
        <span v-if="form.processing" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
        {{ following ? (short ? 'Seguindo' : 'Deixar de seguir') : 'Seguir' }}
    </button>
</template>
