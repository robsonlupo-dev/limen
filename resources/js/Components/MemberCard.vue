<script setup>
/**
 * Card de MEMBRO no catálogo da performer — redesign FOTO-PRIMEIRO (referência
 * visual: Seeking.com). A foto principal aprovada (variante 3:4 enquadrada)
 * PREENCHE o card; um gradiente escuro na base sustenta a sobreposição com o
 * mínimo: rótulo (apelido/FanAlias) + selo verificado + faixa etária + cidade —
 * cada um só quando existe. Badge de câmera (nº de fotos) no topo, como o Seeking;
 * selo "Novo" mantido. Duas ações por card: CORAÇÃO e MENSAGEM.
 *
 * Privacidade (LOCKED): o payload já chega MASCARADO do MemberCatalogService.
 * Tudo que aparece aqui é o que o membro PREENCHEU/consentiu — nunca nome,
 * e-mail, tier, saldo, ou PII involuntário. Faixa/cidade/selo vêm null quando
 * não há, e a UI simplesmente não renderiza.
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
    // { fan_alias_label, member_handle, avatar_url, activity_label, is_new,
    //   hearted, is_verified, age_band, city_label, photo_count }
    member: { type: Object, required: true },
    hearted: { type: Boolean, default: false },
    hearting: { type: Boolean, default: false },
})

const emit = defineEmits(['heart', 'message', 'view'])

// Linha de metadados sob o nome: faixa etária + cidade, separadas por ponto —
// só as que existem, na ordem. Vazia → o nome fica sozinho.
const meta = computed(() =>
    [props.member.age_band, props.member.city_label].filter(Boolean).join(' · '),
)

// "Pop" ao CURTIR (não ao descurtir): o estado real chega pelo prop `hearted`.
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
    <div class="mi-card group relative overflow-hidden rounded-xl bg-limen-surface-2 ring-1 ring-limen-line transition-all duration-200 hover:ring-limen-gold/40">
        <!-- Retrato 3:4 foto-primeiro. O corpo é o alvo do clique (abre o perfil e
             registra a visita); as ações ficam por cima e param a propagação. -->
        <button
            type="button"
            class="relative block aspect-[3/4] w-full cursor-pointer overflow-hidden"
            :aria-label="`Ver perfil de ${member.fan_alias_label}`"
            @click="emit('view')"
        >
            <img
                v-if="member.avatar_url"
                :src="member.avatar_url"
                alt=""
                loading="lazy"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
            />
            <!-- Sem foto: placeholder elegante com silhueta (nunca métrica), mesma
                 proporção para não quebrar o grid. -->
            <span v-else class="grid h-full w-full place-items-center bg-limen-surface">
                <svg class="h-16 w-16 text-limen-ink-mute/30" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.7-8 6v2h16v-2c0-3.3-3.6-6-8-6z" />
                </svg>
            </span>

            <!-- Gradiente na base sustenta a sobreposição de texto. -->
            <span class="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-black/85 via-black/40 to-transparent"></span>

            <!-- Nome + selo + metadados, sobrepostos na base. -->
            <span class="absolute inset-x-0 bottom-0 flex flex-col items-start gap-0.5 p-3 text-left">
                <span class="flex items-center gap-1.5">
                    <span class="truncate font-serif text-base leading-tight text-white drop-shadow">{{ member.fan_alias_label }}</span>
                    <!-- Selo verificado (KYC): só o selo, nunca dado do documento. -->
                    <svg
                        v-if="member.is_verified"
                        class="h-4 w-4 shrink-0 text-limen-gold drop-shadow"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path d="M12 2l2.4 1.8 3 .1 1 2.8 2.4 1.7-.9 2.8.9 2.8-2.4 1.7-1 2.8-3 .1L12 22l-2.4-1.8-3-.1-1-2.8L3.2 15l.9-2.8-.9-2.8 2.4-1.7 1-2.8 3-.1L12 2z" />
                        <path d="M10.4 14.6l-2-2 1.1-1.1.9.9 3-3 1.1 1.1-4.1 4.1z" fill="#181410" />
                    </svg>
                    <span v-if="member.is_verified" class="sr-only">Verificado</span>
                </span>
                <span v-if="meta" class="truncate text-[11px] text-white/80 drop-shadow">{{ meta }}</span>
                <span v-else-if="member.activity_label" class="truncate text-[11px] text-white/70 drop-shadow">{{ member.activity_label }}</span>
            </span>
        </button>

        <!-- Selo "Novo": idade de conta, não presença. -->
        <span
            v-if="member.is_new"
            class="pointer-events-none absolute top-2 left-2 z-10 rounded-full bg-limen-surface/90 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-limen-gold ring-1 ring-limen-gold/50"
        >Novo</span>

        <!-- Badge de câmera + contagem (estilo Seeking). Só com fotos. -->
        <span
            v-if="member.photo_count > 0"
            class="pointer-events-none absolute top-2 right-2 z-10 inline-flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-[11px] font-medium text-white ring-1 ring-white/15 backdrop-blur-sm"
        >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                <circle cx="12" cy="13" r="4" />
            </svg>
            {{ member.photo_count }}
        </span>

        <!-- Ações: CORAÇÃO + MENSAGEM, sobrepostas no canto inferior direito.
             Dourado (nunca limen-live). Alvos ≥44px. -->
        <div class="absolute right-2 bottom-2 z-10 flex items-center gap-2">
            <button
                type="button"
                :aria-label="hearted ? 'Curtido' : 'Curtir'"
                :disabled="hearting"
                class="mi-press grid h-11 w-11 place-items-center rounded-full bg-black/45 text-limen-gold ring-1 ring-white/15 backdrop-blur-sm transition-colors hover:bg-black/60 disabled:opacity-60"
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
                class="mi-press grid h-11 w-11 place-items-center rounded-full bg-black/45 text-limen-gold ring-1 ring-white/15 backdrop-blur-sm transition-colors hover:bg-black/60"
                @click.stop="emit('message')"
            >
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.5A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" />
                </svg>
            </button>
        </div>
    </div>
</template>
