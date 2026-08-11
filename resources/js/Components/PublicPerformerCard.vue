<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

// Card v2 público — mesma estética "maison" do card autenticado, sem as ações
// que exigem login (favorito, mensagem) nem o preview animado da live (rota
// gateada). Só dados JÁ públicos do PerformerPublicResource; sem métricas
// zeradas, sem cidade, sem horário ao minuto.
const props = defineProps({
    performer: { type: Object, required: true },
})

const profileHref = computed(() => route('performers.public.show', props.performer.slug))
const photoUrl = computed(() => props.performer.avatar_url || props.performer.cover_url || null)
const isMaison = computed(() => props.performer.tier === 'maison')
const isSelect = computed(() => props.performer.tier === 'select')
</script>

<template>
    <Link
        :href="profileHref"
        class="mi-card group relative block aspect-[3/4] overflow-hidden rounded-xl bg-limen-surface-2 no-underline transition-all duration-200"
        :class="isMaison
            ? 'ring-1 ring-limen-gold/80 shadow-[0_0_22px_-10px_rgba(214,184,114,0.5)]'
            : 'ring-1 ring-limen-line hover:ring-limen-gold/40'"
        :aria-label="performer.stage_name"
    >
        <!-- Canto superior esquerdo: AO VIVO tem precedência; senão, story não
             visto (para o membro logado que chega por link direto; visitante
             deslogado recebe false do servidor). -->
        <div class="absolute top-3 left-3 z-10 flex flex-col items-start gap-1.5">
            <span
                v-if="performer.is_live"
                role="img"
                aria-label="Ao vivo"
                class="inline-flex items-center gap-1.5 rounded-full bg-limen-live px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-white shadow-lg"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />
                Ao vivo
            </span>
            <span
                v-else-if="performer.has_unseen_stories"
                role="img"
                aria-label="Stories não vistos"
                class="block rounded-full p-0.5 ring-2 ring-limen-gold"
            >
                <span class="grid h-[34px] w-[34px] place-items-center overflow-hidden rounded-full bg-limen-surface-2">
                    <img v-if="performer.avatar_url" :src="performer.avatar_url" :alt="performer.stage_name" loading="lazy" class="h-full w-full object-cover" />
                    <span v-else class="font-serif text-sm text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
                </span>
            </span>
            <!-- Selo "Nova" (feat/activity-badges): entrada recente, BOOLEANO
                 derivado (is_new), nunca a data. Dourado, empilhado abaixo. -->
            <span
                v-if="performer.is_new"
                class="rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50 backdrop-blur-sm"
            >
                Nova
            </span>
        </div>

        <span
            v-if="isMaison"
            class="absolute top-3 right-3 z-10 rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50 backdrop-blur-sm"
        >
            Maison
        </span>

        <img
            v-if="photoUrl"
            :src="photoUrl"
            :alt="performer.stage_name"
            loading="lazy"
            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
        />
        <div v-else class="flex h-full w-full items-center justify-center bg-limen-surface-2">
            <svg class="h-16 w-16 text-limen-ink-mute/40" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
            </svg>
        </div>

        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-[#100d0a] via-[#100d0a]/70 to-transparent" />

        <div class="absolute inset-x-0 bottom-0 p-3">
            <div class="flex items-center gap-1.5 min-w-0">
                <h3 class="font-serif text-base leading-tight text-limen-ink truncate">{{ performer.stage_name }}</h3>
                <svg
                    v-if="performer.is_verified"
                    width="14" height="14" viewBox="0 0 20 20"
                    :fill="isMaison || isSelect ? 'currentColor' : 'none'"
                    stroke="currentColor" stroke-width="1.5"
                    class="shrink-0 text-limen-gold"
                    :title="isMaison ? 'Maison' : isSelect ? 'Select' : 'Verificada'"
                    aria-hidden="true"
                >
                    <path d="M10 1.6l2 1.2 2.3-.2 1 2 2 1-.2 2.3 1.2 2-1.2 2 .2 2.3-2 1-1 2-2.3-.2-2 1.2-2-1.2-2.3.2-1-2-2-1 .2-2.3L1.6 10l1.2-2-.2-2.3 2-1 1-2 2.3.2 2-1.2z" />
                    <path v-if="!(isMaison || isSelect)" d="M6.7 10.2l2.1 2.1 4.5-4.7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <p v-if="performer.activity_label && !performer.is_live" class="mt-0.5 text-[11px] text-limen-ink-mute">
                {{ performer.activity_label }}
            </p>
        </div>
    </Link>
</template>
