<script setup>
import { useForm } from '@inertiajs/vue3'

/**
 * Coração de "salvar para ver depois" — bookmark PRIVADO.
 *
 * Não é um FollowButton com outro ícone, e a diferença é de produto: seguir é
 * público (a performer conta seguidores e, passado o Piso de Anonimato, vê a
 * lista), favoritar não chega a ela de forma nenhuma. Por isso não há contador
 * ao lado do ícone aqui e não deve haver em lugar nenhum.
 *
 * Um POST só, que alterna. O componente não manda o estado desejado: quem
 * resolve a corrida entre dois cliques é o FavoriteService, sob lock.
 */
const props = defineProps({
    slug: { type: String, required: true },
    saved: { type: Boolean, required: true },
    // Quais props recarregar depois do toggle (partial reload do Inertia).
    reloadOnly: { type: Array, default: undefined },
    // 'icon' = coração sozinho, para o canto do card do catálogo.
    // 'button' = coração + rótulo, para a página de perfil.
    variant: { type: String, default: 'icon' },
})

const form = useForm({})

function toggle() {
    form.post(route('favorites.toggle', props.slug), {
        preserveScroll: true,
        only: props.reloadOnly,
    })
}

// O rótulo acessível diz o que o clique FAZ, não o estado atual — é o que um
// leitor de tela precisa de um controle, e o `aria-pressed` já carrega o estado.
</script>

<template>
    <!-- Variante do card: só o ícone, sobre a capa. -->
    <button
        v-if="variant === 'icon'"
        type="button"
        :aria-label="saved ? 'Remover dos salvos' : 'Salvar perfil'"
        :aria-pressed="saved"
        :disabled="form.processing"
        class="grid h-9 w-9 place-items-center rounded-full bg-background/70 backdrop-blur-sm ring-1 ring-frame/60 text-cream transition-all duration-200 hover:bg-background/90 hover:ring-gold/50 disabled:opacity-60"
        @click.stop.prevent="toggle"
    >
        <svg
            class="h-[18px] w-[18px] transition-colors"
            :class="saved ? 'text-gold' : 'text-cream/70'"
            viewBox="0 0 24 24"
            :fill="saved ? 'currentColor' : 'none'"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z" />
        </svg>
    </button>

    <!-- Variante da página de perfil: ícone + rótulo. -->
    <button
        v-else
        type="button"
        :aria-pressed="saved"
        :disabled="form.processing"
        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm transition-all duration-200 disabled:opacity-60"
        :class="saved
            ? 'border-gold/50 bg-gold/10 text-gold hover:bg-gold/15'
            : 'border-frame text-cream hover:border-gold/40 hover:text-gold'"
        @click="toggle"
    >
        <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            :fill="saved ? 'currentColor' : 'none'"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z" />
        </svg>
        {{ saved ? 'Salvo' : 'Salvar' }}
    </button>
</template>
