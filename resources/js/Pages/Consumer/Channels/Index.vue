<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

/**
 * "Canais" — os broadcasts das performers que o membro SEGUE (roadmap social,
 * Onda 2). Grátis, direção performer→seguidor. Sem resposta no canal: o CTA
 * "Conversar" leva ao chat pago (o modelo do Limen — falar com a performer custa).
 * A performer não é anônima (identidade pública do catálogo); nada de membro
 * atravessa (a entrega é derivada de "seguir", não uma lista materializada).
 */
defineProps({
    // [{ id, body, performer: { stage_name, slug, avatar_url }, sent_slot }]
    messages: { type: Array, default: () => [] },
})
</script>

<template>
    <AppLayout title="Canais">
        <div class="bg-limen-bg">
            <div class="mx-auto max-w-screen-md space-y-8 px-6 py-10">
                <div class="space-y-2">
                    <h1 class="font-serif text-4xl text-limen-ink">Canais</h1>
                    <p class="text-sm text-limen-ink-soft">
                        Transmissões das performers que você segue. Para conversar, é só abrir o chat.
                    </p>
                </div>

                <!-- Vazio -->
                <div v-if="messages.length === 0" class="flex flex-col items-center justify-center gap-4 py-24 text-center">
                    <PortalLogo :size="72" :show-text="false" />
                    <p class="font-serif text-2xl text-limen-ink">Nenhuma transmissão ainda.</p>
                    <p class="max-w-sm text-sm text-limen-ink-soft">
                        Siga performers no catálogo para receber as transmissões delas aqui.
                    </p>
                    <Link :href="route('catalog')" class="text-sm text-limen-gold no-underline hover:text-limen-gold/80">
                        Explorar o catálogo
                    </Link>
                </div>

                <!-- Lista de transmissões, mais recentes primeiro. -->
                <ul v-else class="space-y-3">
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-2xl border border-limen-line bg-limen-surface p-4"
                    >
                        <div class="flex items-center gap-3">
                            <Link :href="route('catalog.show', message.performer.slug)" class="shrink-0">
                                <img
                                    v-if="message.performer.avatar_url"
                                    :src="message.performer.avatar_url"
                                    :alt="message.performer.stage_name"
                                    loading="lazy"
                                    class="h-10 w-10 rounded-full object-cover ring-1 ring-limen-line"
                                />
                                <span v-else class="flex h-10 w-10 items-center justify-center rounded-full bg-limen-surface-2 font-serif text-limen-gold">
                                    {{ message.performer.stage_name?.charAt(0) }}
                                </span>
                            </Link>
                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="route('catalog.show', message.performer.slug)"
                                    class="truncate font-serif text-base text-limen-ink no-underline hover:text-limen-gold"
                                >
                                    {{ message.performer.stage_name }}
                                </Link>
                                <p class="text-[11px] text-limen-ink-mute">{{ message.sent_slot }}</p>
                            </div>
                        </div>

                        <p class="mt-3 whitespace-pre-line text-sm text-limen-ink-soft">{{ message.body }}</p>

                        <!-- CTA: sem resposta no canal — leva ao chat pago. -->
                        <div class="mt-3 flex justify-end">
                            <Link
                                :href="route('catalog.show', message.performer.slug)"
                                class="rounded-lg border border-limen-line px-3 py-1.5 text-xs text-limen-ink no-underline transition-colors hover:border-limen-gold/50 hover:text-limen-gold"
                            >
                                Conversar
                            </Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
