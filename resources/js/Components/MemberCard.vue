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
import { ref, watch } from 'vue'

const props = defineProps({
    // { fan_alias_label, member_handle, avatar_url, activity_label, is_new, hearted }
    member: { type: Object, required: true },
    hearted: { type: Boolean, default: false },
    hearting: { type: Boolean, default: false },
})

const emit = defineEmits(['heart', 'message', 'view'])

// "Pop" ao CURTIR (não ao descurtir): o estado real chega pelo prop `hearted`
// depois que o pai confirma. Ao virar false→true, reinicia a animação no ícone
// (remove classe, força reflow, readiciona) — necessário para retriggar.
const heartIcon = ref(null)
watch(
    () => props.hearted,
    (now, prev) => {
        if (now && !prev && heartIcon.value) {
            heartIcon.value.classList.remove('mi-pop')
            void heartIcon.value.offsetWidth
            heartIcon.value.classList.add('mi-pop')
        }
    },
)
</script>

<template>
    <!-- Card COMPACTO (fix/uat-round-polish, item 10): sem a foto 3:4 que deixava
         uma silhueta minúscula boiando num vazio enorme. Avatar/silhueta + alias +
         atividade + ações, tudo aproveitando o espaço, altura natural curta. -->
    <div class="mi-card relative flex flex-col items-center gap-2 rounded-xl bg-limen-surface-2 px-3 py-4 text-center ring-1 ring-limen-line transition-all duration-200 hover:ring-limen-gold/40">
        <!-- Abrir o perfil do membro: o corpo do card é o alvo do clique (as ações
             ficam por cima e param a propagação). Abrir registra a visita. -->
        <button
            type="button"
            class="absolute inset-0 z-10 cursor-pointer rounded-xl"
            :aria-label="`Ver perfil de ${member.fan_alias_label}`"
            @click="emit('view')"
        ></button>

        <!-- Selo "Novo": idade de conta (7 dias), não presença. -->
        <span
            v-if="member.is_new"
            class="absolute top-2 left-2 z-20 rounded-full bg-limen-surface px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50"
        >Novo</span>

        <!-- Foto do membro quando houver; senão silhueta neutra (nunca métrica). -->
        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full bg-limen-surface ring-1 ring-limen-line">
            <img v-if="member.avatar_url" :src="member.avatar_url" alt="" loading="lazy" class="h-full w-full object-cover" />
            <span v-else class="grid h-full w-full place-items-center">
                <svg class="h-9 w-9 text-limen-ink-mute/40" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
                </svg>
            </span>
        </div>

        <div class="w-full min-w-0">
            <h3 class="truncate font-serif text-sm leading-tight text-limen-ink">{{ member.fan_alias_label }}</h3>
            <p v-if="member.activity_label" class="mt-0.5 text-[11px] text-limen-ink-mute">{{ member.activity_label }}</p>
        </div>

        <!-- Ações: CORAÇÃO + MENSAGEM. Dourado (nunca limen-live). Alvos ≥44px. -->
        <div class="relative z-20 mt-0.5 flex items-center gap-3">
            <button
                type="button"
                :aria-label="hearted ? 'Curtido' : 'Curtir'"
                :disabled="hearting"
                class="mi-press grid h-11 w-11 place-items-center rounded-full bg-limen-surface text-limen-gold ring-1 ring-limen-gold/40 transition-colors hover:ring-limen-gold/70 disabled:opacity-60"
                @click.stop="emit('heart')"
            >
                <svg
                    ref="heartIcon"
                    @animationend="$event.currentTarget.classList.remove('mi-pop')"
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

            <button
                type="button"
                aria-label="Enviar mensagem"
                class="mi-press grid h-11 w-11 place-items-center rounded-full bg-limen-surface text-limen-gold ring-1 ring-limen-gold/40 transition-colors hover:ring-limen-gold/70"
                @click.stop="emit('message')"
            >
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.5A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" />
                </svg>
            </button>
        </div>
    </div>
</template>
