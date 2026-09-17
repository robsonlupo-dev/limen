<script setup>
import { computed } from 'vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import CurationSeal from '@/Components/CurationSeal.vue'
import LiveBadge from '@/Components/LiveBadge.vue'

// Cabeçalho do perfil (feat/performer-profile-redesign, itens 1 e 2). Uma unidade
// visual só: capa como FAIXA (não parede), avatar circular sobrepondo a borda
// inferior da capa (sempre por cima, nunca cortado), e ao lado o nome, o selo de
// verificada CLICÁVEL, o selo de curadoria, a presença e as gorjetas.
// Compartilhado pelos dois perfis públicos (membro e visitante) — as AÇÕES ficam
// fora daqui (barra fixa no mobile / coluna lateral no desktop).
const props = defineProps({
    performer: { type: Object, required: true },
    features: { type: Object, default: () => ({}) },
    // Gorjetas recebidas — só entra quando > 0 (métrica zerada não aparece).
    tipsCount: { type: Number, default: 0 },
})

const emit = defineEmits(['open-verified'])

// Capa: só é AO VIVO com a feature ligada (em produção a flag está off).
const liveNow = computed(() => props.performer.is_live && props.features.live_enabled)
const showTips = computed(() => Number(props.tipsCount) > 0)

// Presença compacta na linha do nome. is_live vira o badge da capa; aqui fica o
// "online agora" (presença derivada da sessão) ou a faixa de atividade ("Ativa
// hoje"). Nunca as duas, nunca relógio.
const presence = computed(() => {
    if (liveNow.value) return null
    if (props.performer.is_available) return { online: true, text: 'Online agora' }
    if (props.performer.activity_label) return { online: false, text: props.performer.activity_label }
    return null
})
</script>

<template>
    <div>
        <!-- CAPA COMO FAIXA (item 1): 2:1 no retrato, 3:1 no desktop, teto ~280px.
             object-cover trata a capa como banner (preenche a faixa); a capa bem
             enquadrada (3:1 do cropper) fica exata. Véu escuro na base para o que
             vem por cima ser legível. Sem capa: mármore da marca (CSS), nunca vazio. -->
        <div class="profile-cover relative aspect-[2/1] max-h-[280px] w-full overflow-hidden rounded-b-2xl sm:aspect-[3/1]">
            <img
                v-if="performer.cover_url"
                :src="performer.cover_url"
                :alt="`Capa de ${performer.stage_name}`"
                class="h-full w-full object-cover"
            />
            <div v-else class="profile-marble h-full w-full" aria-hidden="true" />
            <!-- Véu: escurece a base para o avatar/nome que a sobrepõem. -->
            <div class="absolute inset-0 bg-gradient-to-t from-limen-bg via-limen-bg/40 to-transparent" />

            <div v-if="liveNow" class="absolute right-4 top-4">
                <LiveBadge />
            </div>
        </div>

        <!-- AVATAR + IDENTIDADE (item 2): avatar sobrepõe a base da capa, alinhado à
             esquerda, sempre POR CIMA (z-10, fora do overflow da capa) e nunca
             cortado. Nome/selos/presença ao lado formam uma unidade compacta. -->
        <div class="-mt-12 flex items-end gap-4 sm:-mt-14 sm:gap-5">
            <div class="relative z-10 h-24 w-24 shrink-0 overflow-hidden rounded-full border-4 border-limen-gold bg-limen-surface-2 shadow-2xl sm:h-28 sm:w-28">
                <!-- object-contain: avatar 1:1 preenche o círculo; imagem fora de
                     1:1 (semente) aparece inteira, sem cortar o rosto. -->
                <img
                    v-if="performer.avatar_url"
                    :src="performer.avatar_url"
                    :alt="performer.stage_name"
                    class="h-full w-full object-contain"
                />
                <span v-else class="flex h-full w-full items-center justify-center font-serif text-4xl text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
            </div>

            <div class="min-w-0 flex-1 pb-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h1 class="font-serif text-3xl leading-tight text-limen-ink sm:text-4xl">{{ performer.stage_name }}</h1>
                    <!-- Selo de verificada CLICÁVEL (item 8): abre o painel do que
                         foi verificado. Só quando is_verified. -->
                    <button
                        v-if="performer.is_verified"
                        type="button"
                        class="mi-press inline-flex items-center rounded-full transition-colors hover:bg-limen-gold/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/50"
                        aria-label="Ver o que foi verificado neste perfil"
                        @click="emit('open-verified')"
                    >
                        <VerifiedBadge :category="performer.category" />
                    </button>
                    <CurationSeal :tier="performer.tier" />
                </div>

                <!-- Presença + gorjetas: a prova social compacta, na mesma unidade
                     do nome. Cada parte só aparece quando existe. -->
                <div v-if="presence || showTips" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-limen-ink-mute">
                    <span v-if="presence" class="inline-flex items-center gap-1.5">
                        <span
                            v-if="presence.online"
                            aria-hidden="true"
                            class="inline-block h-2 w-2 rounded-full bg-success"
                        />
                        <span :class="presence.online ? 'text-limen-ink' : ''">{{ presence.text }}</span>
                    </span>
                    <span v-if="presence && showTips" aria-hidden="true" class="text-limen-ink-mute/40">·</span>
                    <span v-if="showTips"><span class="text-limen-ink">{{ tipsCount }}</span> gorjetas</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Mármore da marca quando não há capa (item 1) — CSS puro, sem asset externo
   (ExternalAssetPolicy). Pedra escura com veios dourados discretos. */
.profile-marble {
    background-color: #1d1813;
    background-image:
        radial-gradient(120% 80% at 15% 10%, rgba(214, 184, 114, 0.12), transparent 55%),
        radial-gradient(90% 70% at 85% 90%, rgba(214, 184, 114, 0.08), transparent 60%),
        linear-gradient(115deg, transparent 46%, rgba(214, 184, 114, 0.14) 47%, rgba(214, 184, 114, 0.03) 49%, transparent 50%),
        linear-gradient(160deg, transparent 68%, rgba(214, 184, 114, 0.10) 69%, transparent 71%),
        linear-gradient(135deg, #241d16 0%, #181410 60%, #221b14 100%);
}
</style>
