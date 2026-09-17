<script setup>
import { Link } from '@inertiajs/vue3'
import FollowButton from '@/Components/FollowButton.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'

// Ações do perfil, lado do MEMBRO (feat/performer-profile-redesign, item 4).
// UMA instância, reposicionada por breakpoint: no celular vira barra FIXA no
// rodapé (acima da navegação do painel); no desktop, cartão que acompanha a
// rolagem (sticky) na coluna lateral. Sempre alcançável.
//
// Ordem de importância: Ao vivo (se houver) → Conversar (primária, com o custo)
// → Gorjeta · Seguir · Salvar. Alvos ≥44px, rótulos curtos. Nenhuma regra de
// acesso/preço muda: o custo e o destino do chat vêm prontos do servidor.
const props = defineProps({
    performer: { type: Object, required: true },
    features: { type: Object, default: () => ({}) },
    // Destino do "Conversar" (conversa aberta → chat; senão → compor).
    chatHref: { type: String, required: true },
    // Custo de abertura por tier (item 4), null para não-membro.
    chatCost: { type: Number, default: null },
    // Offset inferior da barra fixa no mobile (acima da navegação do painel).
    // CSS string; no desktop (sticky) é ignorado.
    bottomOffset: { type: String, default: '0px' },
})

const emit = defineEmits(['tip'])
</script>

<template>
    <!-- max-lg:fixed = barra no rodapé no celular; lg:sticky = cartão que segue a
         rolagem no desktop. O :style só morde quando fixo. -->
    <div
        :style="{ bottom: bottomOffset }"
        class="z-30 max-lg:fixed max-lg:inset-x-0 max-lg:border-t max-lg:border-limen-line max-lg:bg-limen-surface/95 max-lg:px-4 max-lg:py-3 max-lg:backdrop-blur lg:sticky lg:top-24 lg:rounded-2xl lg:border lg:border-limen-line lg:bg-limen-surface lg:p-5"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-2.5">
            <!-- Ao vivo: acima de tudo, só com a feature ligada. -->
            <Link
                v-if="performer.is_live && features.live_enabled"
                :href="route('live.show', performer.slug)"
                class="mi-glow inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-lg bg-limen-live px-4 text-sm font-semibold text-white no-underline transition-opacity hover:opacity-90"
            >
                <span class="h-2 w-2 animate-pulse rounded-full bg-white" /> Ao vivo — assistir
            </Link>

            <!-- Primária: Conversar, largura cheia nas DUAS larguras (o custo não
                 quebra linha — item 7). -->
            <Link
                :href="chatHref"
                class="mi-glow inline-flex min-h-[44px] w-full items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-limen-gold px-4 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60"
            >
                Conversar
                <span v-if="chatCost" class="font-normal opacity-80">· {{ chatCost }}</span>
            </Link>

            <!-- Secundárias/terciárias: grade de 3 iguais em QUALQUER largura — cabe a
                 360px sem cortar nada (item 7). Hierarquia (fix 6): Gorjeta é a
                 secundária (contorno dourado); Seguir e Salvar são terciárias
                 (contorno neutro), sem competir com a primária dourada. -->
            <div class="grid grid-cols-3 gap-2">
                <button
                    type="button"
                    class="mi-press inline-flex min-h-[44px] w-full items-center justify-center rounded-lg border border-limen-gold px-4 text-sm text-limen-gold transition-colors hover:bg-limen-gold/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60"
                    @click="emit('tip')"
                >Gorjeta</button>
                <FollowButton
                    :slug="performer.slug"
                    :following="performer.is_following"
                    :reload-only="['performer']"
                    size="sm"
                    short
                    class="min-h-[44px] w-full justify-center"
                />
                <FavoriteButton
                    :slug="performer.slug"
                    :saved="!!performer.is_favorited"
                    :reload-only="['performer']"
                    variant="button"
                    class="min-h-[44px] w-full justify-center"
                />
            </div>
        </div>
    </div>
</template>
