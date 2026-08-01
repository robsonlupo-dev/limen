<script setup>
import { Link } from '@inertiajs/vue3'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import VerificationBadges from '@/Components/VerificationBadges.vue'
import { WORLD_LABELS, WORLD_ICONS } from '@/lib/worlds'

defineProps({
    performer: { type: Object, required: true },
})
</script>

<template>
    <Link
        :href="route('performers.public.show', performer.slug)"
        class="group block no-underline rounded-xl border border-frame bg-surface overflow-hidden transition-all duration-200 hover:border-gold/40 hover:shadow-[0_0_24px_-8px_rgba(201,162,75,0.35)]"
    >
        <div class="relative aspect-[4/3] bg-surface-2 overflow-hidden">
            <img
                v-if="performer.cover_url"
                :src="performer.cover_url"
                :alt="performer.stage_name"
                loading="lazy"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
            />
            <div v-else class="h-full w-full bg-gradient-to-br from-gold/25 via-surface-2 to-background" />

            <div
                v-if="performer.is_live"
                role="img"
                aria-label="Ao vivo"
                class="absolute bottom-2 left-2 h-3 w-3 rounded-full bg-green-500 ring-2 ring-white animate-pulse"
            />

            <!-- Contador da galeria (Sprint 10). Discreto, no canto da capa, e só
                 quando há foto além do avatar. `photos_count` vem do
                 withCount('photos') do resource — número exato, é conteúdo público
                 da performer (não é canal lateral como o de seguidores). -->
            <div
                v-if="performer.photos_count > 0"
                :aria-label="`${performer.photos_count} fotos`"
                class="absolute top-2 right-2 flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-xs text-cream backdrop-blur-sm"
            >
                <span aria-hidden="true">📷</span>{{ performer.photos_count }}
            </div>

            <div class="absolute -bottom-6 left-4">
                <div class="h-14 w-14 rounded-full border-2 border-gold bg-surface-2 overflow-hidden flex items-center justify-center shadow-lg">
                    <img
                        v-if="performer.avatar_url"
                        :src="performer.avatar_url"
                        :alt="performer.stage_name"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <span v-else class="font-serif text-xl text-gold">{{ performer.stage_name?.charAt(0) }}</span>
                </div>

                <!-- Story não visto (Sprint 9C). Idêntico ao card autenticado —
                     esta porta é pública, mas o membro logado também chega aqui
                     por link direto, e para ele o indicador funciona igual. Para
                     visitante deslogado o servidor manda `false` em todos.
                     Sem pulsar: o pulso é do "ao vivo". -->
                <span
                    v-if="performer.has_unseen_stories"
                    role="img"
                    aria-label="Stories não vistos"
                    class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-gold ring-2 ring-white"
                />
            </div>
        </div>

        <div class="px-4 pt-9 pb-4 space-y-1.5">
            <div class="flex items-center gap-1.5 min-w-0">
                <h3 class="font-serif text-lg text-cream truncate">{{ performer.stage_name }}</h3>
                <VerifiedBadge :category="performer.category" />
            </div>
            <VerificationBadges
                :is-verified="performer.is_verified"
                :email-verified="performer.email_verified"
                :category="performer.category"
                :tier="performer.tier"
            />
            <p class="text-xs text-muted uppercase tracking-wide flex items-center gap-1">
                <span aria-hidden="true">{{ WORLD_ICONS[performer.category] }}</span>
                {{ WORLD_LABELS[performer.category] ?? performer.category }}
            </p>
            <!-- "Disponível para conversa" (Sprint 11): idêntico ao card
                 autenticado. Some quando is_live (a bolinha já diz "agora"). -->
            <p v-if="performer.is_available && !performer.is_live" class="text-xs text-gold flex items-center gap-1">
                <span aria-hidden="true">💬</span> Quer conversar
            </p>
            <!-- Última atividade em faixa (Sprint 10): idêntico ao card
                 autenticado. Some quando is_live (a bolinha já diz "agora") e
                 quando null. Ver ActivitySlot. -->
            <p v-if="performer.activity_label && !performer.is_live" class="text-xs text-muted">
                {{ performer.activity_label }}
            </p>
            <!-- Mesma regra do PerformerCard: UF só, fora da foto (onde mora a
                 bolinha de ao vivo) e ausente enquanto ela estiver ao vivo OU
                 disponível — presença + localização é a correlação do R2. -->
            <p v-if="performer.state && !performer.is_live && !performer.is_available" class="text-xs text-muted">
                <span class="rounded border border-frame px-1.5 py-0.5 tracking-wide">{{ performer.state }}</span>
            </p>
            <p class="text-xs text-muted pt-1">{{ performer.followers_label }} apoiadores</p>
        </div>
    </Link>
</template>
