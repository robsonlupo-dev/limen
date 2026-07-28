<script setup>
import { Link } from '@inertiajs/vue3'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import VerificationBadges from '@/Components/VerificationBadges.vue'
import StarRating from '@/Components/StarRating.vue'
import FollowButton from '@/Components/FollowButton.vue'

defineProps({
    performer: { type: Object, required: true },
})

const categoryLabels = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}
</script>

<template>
    <div class="group rounded-xl border border-frame bg-surface overflow-hidden transition-all duration-200 hover:border-gold/40 hover:shadow-[0_0_24px_-8px_rgba(201,162,75,0.35)]">
        <Link :href="route('catalog.show', performer.slug)" class="block no-underline">
            <div class="relative aspect-[4/3] bg-surface-2 overflow-hidden">
                <img
                    v-if="performer.cover_url"
                    :src="performer.cover_url"
                    :alt="performer.stage_name"
                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                />
                <div v-else class="h-full w-full bg-gradient-to-br from-gold/25 via-surface-2 to-background" />

                <div
                    v-if="performer.is_live"
                    role="img"
                    aria-label="Ao vivo"
                    class="absolute bottom-2 left-2 h-3 w-3 rounded-full bg-green-500 ring-2 ring-white animate-pulse"
                />

                <div class="absolute -bottom-6 left-4">
                    <div class="h-14 w-14 rounded-full border-2 border-gold bg-surface-2 overflow-hidden flex items-center justify-center shadow-lg">
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-xl text-gold">{{ performer.stage_name?.charAt(0) }}</span>
                    </div>
                </div>
            </div>

            <div class="px-4 pt-9 pb-3 space-y-1.5">
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
                <p class="text-xs text-muted uppercase tracking-wide">
                    {{ categoryLabels[performer.category] ?? performer.category }}
                </p>
                <div class="flex items-center justify-between pt-1">
                    <StarRating :rating="performer.rating_avg" />
                    <span class="text-xs text-muted">{{ performer.followers_label }} seguidores</span>
                </div>
            </div>
        </Link>

        <div class="px-4 pb-4">
            <FollowButton
                :slug="performer.slug"
                :following="performer.is_following"
                :reload-only="['performers']"
                size="sm"
                class="w-full"
            />
        </div>
    </div>
</template>
