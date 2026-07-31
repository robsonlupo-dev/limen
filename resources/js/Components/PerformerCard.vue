<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import VerificationBadges from '@/Components/VerificationBadges.vue'
import StarRating from '@/Components/StarRating.vue'
import FollowButton from '@/Components/FollowButton.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'

const props = defineProps({
    performer: { type: Object, required: true },
    // Quais props recarregar depois do toggle de favorito. O catálogo recarrega
    // 'performers'; a tela de Favoritos usa o mesmo nome de prop.
    favoriteReloadOnly: { type: Array, default: () => ['performers'] },
})

// O catálogo autenticado não é exclusivo do membro — performer e admin também
// navegam por ele. Só `role:consumer` alcança POST /favoritos/{slug}, então o
// coração só aparece para quem o clique levaria a algum lugar. Mesmo corte do
// `canTip` na página pública do perfil.
const page = usePage()
const canFavorite = computed(() => page.props.auth?.user?.role === 'consumer')

const categoryLabels = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}
</script>

<template>
    <div class="group relative rounded-xl border border-frame bg-surface overflow-hidden transition-all duration-200 hover:border-gold/40 hover:shadow-[0_0_24px_-8px_rgba(201,162,75,0.35)]">
        <!-- Coração de "salvar para ver depois" (Sprint 10).
             Fica FORA do <Link> — um <button> dentro de um <a> é HTML inválido e
             o clique disputaria com a navegação. Como irmão posicionado, o
             toggle não abre o perfil e o card inteiro continua clicável.
             Bookmark PRIVADO: nada daqui chega à performer, e por isso não há
             contador ao lado (ver FavoriteService). -->
        <div v-if="canFavorite" class="absolute top-2 right-2 z-10">
            <FavoriteButton
                :slug="performer.slug"
                :saved="!!performer.is_favorited"
                :reload-only="favoriteReloadOnly"
            />
        </div>

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

                <!-- Contador da galeria (Sprint 10). No canto SUPERIOR ESQUERDO,
                     porque o direito é do coração de favorito. Discreto e só com
                     foto além do avatar. Número exato: é conteúdo público da
                     performer (ver photos_count no PerformerPublicResource). -->
                <div
                    v-if="performer.photos_count > 0"
                    :aria-label="`${performer.photos_count} fotos`"
                    class="absolute top-2 left-2 flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-xs text-cream backdrop-blur-sm"
                >
                    <span aria-hidden="true">📷</span>{{ performer.photos_count }}
                </div>

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

                    <!-- Story não visto (Sprint 9C): ponto dourado, sem texto e
                         SEM pulsar — o pulso é do "ao vivo", que é estado em
                         tempo real; story é conteúdo parado esperando você.
                         Dois indicadores pulsando no mesmo card competiriam.
                         Fica FORA do wrapper com overflow-hidden, senão o
                         arredondamento do avatar corta o ponto.
                         `has_unseen_stories` é dado do MEMBRO — a performer não
                         recebe nada daqui (ver StoryVisibilityService). -->
                    <span
                        v-if="performer.has_unseen_stories"
                        role="img"
                        aria-label="Stories não vistos"
                        class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-gold ring-2 ring-white"
                    />
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
                <!-- Última atividade em faixa (Sprint 10): texto discreto abaixo
                     do nome. Vem pronto do servidor (ActivitySlot) — nunca um
                     horário. Suprimido quando is_live: aí a bolinha verde na foto
                     já diz "agora", e repetir em texto seria redundante (decisão
                     do PO). Ausente quando null (nunca ativa ou perk ligado). -->
                <p v-if="performer.activity_label && !performer.is_live" class="text-xs text-muted">
                    {{ performer.activity_label }}
                </p>
                <!-- Estado: SÓ a UF, e some quando a performer está ao vivo.
                     São duas regras distintas do PO no mesmo elemento:
                     1. Fica na área de info, NUNCA sobre a foto — a bolinha
                        verde de "ao vivo" mora lá, e o pedido é separá-los.
                     2. Nunca no mesmo card que o indicador ao vivo: "está
                        transmitindo AGORA" + "está em SP" é uma correlação em
                        tempo real que o estado sozinho não dá. Por isso o
                        `!performer.is_live`, e não só o afastamento visual.
                     A cidade não existe nesta prop (ver PerformerPublicResource). -->
                <p v-if="performer.state && !performer.is_live" class="text-xs text-muted">
                    <span class="rounded border border-frame px-1.5 py-0.5 tracking-wide">{{ performer.state }}</span>
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
