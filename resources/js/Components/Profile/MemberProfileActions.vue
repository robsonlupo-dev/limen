<script setup>
import { Link } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
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

            <div class="flex gap-2 lg:flex-col lg:gap-2.5">
                <!-- Primária: Conversar, com o custo de abertura. -->
                <Link
                    :href="chatHref"
                    class="mi-glow inline-flex min-h-[44px] flex-1 items-center justify-center gap-1.5 rounded-lg bg-limen-gold px-4 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60 lg:w-full lg:flex-none"
                >
                    Conversar
                    <span v-if="chatCost" class="font-normal opacity-80">· {{ chatCost }}</span>
                </Link>

                <!-- Secundárias: no celular ficam ao lado da primária (uma sub-linha
                     de 3); no desktop viram uma grade de 3 abaixo dela. -->
                <div class="flex min-w-0 gap-2 lg:grid lg:grid-cols-3">
                    <Button variant="ghost" size="sm" class="min-h-[44px] flex-1 justify-center lg:w-full" @click="emit('tip')">Gorjeta</Button>
                    <FollowButton
                        :slug="performer.slug"
                        :following="performer.is_following"
                        :reload-only="['performer']"
                        size="sm"
                        short
                        class="min-h-[44px] flex-1 justify-center lg:w-full"
                    />
                    <FavoriteButton
                        :slug="performer.slug"
                        :saved="!!performer.is_favorited"
                        :reload-only="['performer']"
                        variant="button"
                        class="min-h-[44px] flex-1 justify-center lg:w-full"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
