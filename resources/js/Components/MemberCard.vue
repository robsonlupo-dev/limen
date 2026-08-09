<script setup>
/**
 * Card de MEMBRO no catálogo da performer (home dela) — espelha o PerformerCard
 * "maison": foto retrato 3:4 preenche o card, barra inferior sobreposta com o
 * mínimo (FanAlias + atividade em faixa). Duas ações por card: CORAÇÃO (interesse
 * grátis) e MENSAGEM (personalizada). O membro NÃO tem avatar no produto, então o
 * card cai sempre no placeholder de silhueta — `avatar_url` fica no contrato por
 * clareza ("avatar se um dia tiver").
 *
 * Privacidade (LOCKED): o payload já vem MASCARADO do MemberCatalogService —
 * FanAlias label + handle, faixa de atividade e nada mais. Nunca nome, e-mail,
 * tier ou saldo do membro. Não há o que vazar no DevTools.
 */
const props = defineProps({
    // { fan_alias_label, member_handle, avatar_url, activity_label, hearted }
    member: { type: Object, required: true },
    hearted: { type: Boolean, default: false },
    hearting: { type: Boolean, default: false },
})

const emit = defineEmits(['heart', 'message'])
</script>

<template>
    <div class="group relative aspect-[3/4] overflow-hidden rounded-xl bg-limen-surface-2 ring-1 ring-limen-line transition-all duration-200 hover:ring-limen-gold/40">
        <!-- Foto do membro se houver; senão placeholder neutro (silhueta), nunca
             uma métrica. Hoje é sempre a silhueta (membro não tem avatar). -->
        <img
            v-if="member.avatar_url"
            :src="member.avatar_url"
            alt=""
            loading="lazy"
            class="h-full w-full object-cover"
        />
        <div v-else class="flex h-full w-full items-center justify-center bg-limen-surface-2">
            <svg class="h-16 w-16 text-limen-ink-mute/40" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
            </svg>
        </div>

        <!-- Scrim para legibilidade da barra inferior. -->
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-[#100d0a] via-[#100d0a]/70 to-transparent" />

        <!-- Ações: CORAÇÃO + MENSAGEM, canto inferior direito. Dourado (limen-gold),
             nunca limen-live — essa cor é EXCLUSIVA do estado "ao vivo". -->
        <div class="absolute bottom-3 right-3 z-20 flex items-center gap-2">
            <!-- Coração: preenchido quando já curtido; grátis e ilimitado. -->
            <button
                type="button"
                :aria-label="hearted ? 'Curtido' : 'Curtir'"
                :disabled="hearting"
                class="grid h-9 w-9 place-items-center rounded-full bg-black/45 text-limen-gold ring-1 ring-limen-gold/40 backdrop-blur-sm transition-colors hover:bg-black/65 hover:ring-limen-gold/70 disabled:opacity-60"
                @click="emit('heart')"
            >
                <svg
                    class="h-[18px] w-[18px]"
                    viewBox="0 0 24 24"
                    :fill="hearted ? 'currentColor' : 'none'"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z" />
                </svg>
            </button>

            <!-- Mensagem personalizada: abre o modal de composição (estado no pai). -->
            <button
                type="button"
                aria-label="Enviar mensagem"
                class="grid h-9 w-9 place-items-center rounded-full bg-black/45 text-limen-gold ring-1 ring-limen-gold/40 backdrop-blur-sm transition-colors hover:bg-black/65 hover:ring-limen-gold/70"
                @click="emit('message')"
            >
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.5A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" />
                </svg>
            </button>
        </div>

        <!-- Barra inferior: FanAlias + atividade em faixa. pr-24 reserva o canto
             para o cluster de ações. -->
        <div class="absolute inset-x-0 bottom-0 p-3 pr-24">
            <h3 class="truncate font-serif text-base leading-tight text-limen-ink">{{ member.fan_alias_label }}</h3>
            <p v-if="member.activity_label" class="mt-0.5 text-[11px] text-limen-ink-mute">{{ member.activity_label }}</p>
        </div>
    </div>
</template>
