<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

/**
 * "Performers interessadas em você" — os corações RECEBIDOS pelo membro.
 *
 * O oposto do painel de visitantes: aqui é a PERFORMER que se expõe ao membro,
 * com a identidade pública do catálogo (nome artístico, avatar, slug). Grátis — o
 * membro vê quem curtiu sem pagar nada. Nada de PII, nada de tier.
 */
defineProps({
    // [{ stage_name, slug, avatar_url, hearted_slot }]
    performers: { type: Array, default: () => [] },
})
</script>

<template>
    <AppLayout title="Interessadas">
        <div class="bg-limen-bg">
            <div class="max-w-screen-2xl mx-auto px-6 py-10 space-y-8">
                <div class="space-y-2">
                    <h1 class="font-serif text-4xl text-limen-ink">Interessadas em você</h1>
                    <p class="text-sm text-limen-ink-soft">Performers que demonstraram interesse — o coração é grátis, é só dar o próximo passo.</p>
                </div>

                <!-- Vazio -->
                <div v-if="performers.length === 0" class="flex flex-col items-center justify-center gap-4 py-24 text-center">
                    <PortalLogo :size="72" :show-text="false" />
                    <p class="font-serif text-2xl text-limen-ink">Ainda ninguém demonstrou interesse.</p>
                    <p class="max-w-sm text-sm text-limen-ink-soft">
                        Complete seu perfil e explore o catálogo — quanto mais presente, mais chances de ser notado.
                    </p>
                    <Link :href="route('catalog')" class="text-sm text-limen-gold no-underline hover:text-limen-gold/80">
                        Explorar o catálogo
                    </Link>
                </div>

                <!-- Grid de performers, mesma proporção do catálogo. -->
                <div v-else class="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-4">
                    <Link
                        v-for="performer in performers"
                        :key="performer.slug"
                        :href="route('catalog.show', performer.slug)"
                        class="group relative block aspect-[3/4] overflow-hidden rounded-xl bg-limen-surface-2 no-underline ring-1 ring-limen-line transition-all duration-200 hover:ring-limen-gold/40"
                    >
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            loading="lazy"
                            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center bg-limen-surface-2">
                            <span class="font-serif text-3xl text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
                        </div>

                        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-[#100d0a] via-[#100d0a]/70 to-transparent" />

                        <div class="absolute inset-x-0 bottom-0 p-3">
                            <h3 class="truncate font-serif text-base leading-tight text-limen-ink">{{ performer.stage_name }}</h3>
                            <p class="mt-0.5 text-[11px] text-limen-ink-mute">Curtiu você · {{ performer.hearted_slot }}</p>
                        </div>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
