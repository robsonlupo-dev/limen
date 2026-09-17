<script setup>
import { Link } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'

// Ações do perfil PÚBLICO (visitante ou membro sem conversa) — feat/performer-
// profile-redesign, item 4. Mesma reposição do MemberProfileActions (barra fixa
// no mobile / cartão sticky no desktop), mas com os destinos do lado público:
// quase tudo leva ao cadastro; só o membro que JÁ tem conversa vê "Enviar
// mensagem" (chat é interest-gated — não há chat frio aqui). Nenhuma regra muda.
const props = defineProps({
    performer: { type: Object, required: true },
    // Conversa aberta deste espectador com a performer, ou null.
    chat: { type: Object, default: null },
    // Só membro (role:consumer) abre o modal de gorjeta; senão vai ao cadastro.
    canTip: { type: Boolean, default: false },
    // Estado do favorito, ou null quando o espectador não pode favoritar.
    favorite: { type: Object, default: null },
    bottomOffset: { type: String, default: '0px' },
})

const emit = defineEmits(['tip', 'unlock-chat'])
</script>

<template>
    <div
        :style="{ bottom: bottomOffset }"
        class="z-30 max-lg:fixed max-lg:inset-x-0 max-lg:border-t max-lg:border-limen-line max-lg:bg-limen-surface/95 max-lg:px-4 max-lg:py-3 max-lg:backdrop-blur lg:sticky lg:top-24 lg:rounded-2xl lg:border lg:border-limen-line lg:bg-limen-surface lg:p-5"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-2.5">
            <!-- Primária: Enviar mensagem (membro com conversa) ou Criar conta. -->
            <Link
                v-if="chat && chat.can_access"
                :href="route('chat.show', chat.conversation_id)"
                class="mi-glow inline-flex min-h-[44px] w-full items-center justify-center rounded-lg bg-limen-gold px-4 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90"
            >Enviar mensagem</Link>
            <button
                v-else-if="chat"
                type="button"
                class="mi-glow inline-flex min-h-[44px] w-full items-center justify-center rounded-lg bg-limen-gold px-4 text-sm font-semibold text-limen-bg transition-opacity hover:opacity-90"
                @click="emit('unlock-chat')"
            >Enviar mensagem</button>
            <Link
                v-else
                :href="route('entrada')"
                class="mi-glow inline-flex min-h-[44px] w-full items-center justify-center rounded-lg bg-limen-gold px-4 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90"
            >Criar conta para interagir</Link>

            <!-- Secundárias: Seguir (→ cadastro), Gorjeta (membro → modal, senão
                 cadastro), Salvar (só membro). -->
            <div class="flex min-w-0 gap-2 lg:grid lg:grid-cols-3">
                <Link
                    :href="route('entrada')"
                    class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-lg border border-limen-gold/50 px-3 text-sm text-limen-gold no-underline transition-colors hover:bg-limen-gold/10 lg:w-full"
                >Seguir</Link>
                <Button v-if="canTip" variant="ghost" size="sm" class="min-h-[44px] flex-1 justify-center lg:w-full" @click="emit('tip')">Gorjeta</Button>
                <Link
                    v-else
                    :href="route('entrada')"
                    class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-lg border border-limen-line px-3 text-sm text-limen-ink-mute no-underline transition-colors hover:border-limen-gold/40 hover:text-limen-ink lg:w-full"
                >Gorjeta</Link>
                <FavoriteButton
                    v-if="favorite"
                    :slug="performer.slug"
                    :saved="favorite.saved"
                    :reload-only="['favorite']"
                    variant="button"
                    class="min-h-[44px] flex-1 justify-center lg:w-full"
                />
            </div>
        </div>
    </div>
</template>
