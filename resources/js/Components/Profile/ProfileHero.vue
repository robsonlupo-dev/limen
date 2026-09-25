<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
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
    // Stories no formato do profileStripFor [{id, visibility_level, locked, seen,
    // image_url}]. Alimenta o ANEL e o menu do avatar. Vazio/tudo locked → sem anel.
    stories: { type: Array, default: () => [] },
})

const emit = defineEmits(['open-verified', 'open-story', 'open-avatar-photo'])

// Anel de story no avatar (estilo Instagram/NowStrip): só conta o que é VISÍVEL
// (locked fica de fora). Anel dourado quando há story não visto, cinza quando tudo
// visto, sem anel especial quando não há story. limen-live é EXCLUSIVO de "ao vivo"
// — story usa gold (convenção do projeto).
const viewableStories = computed(() => props.stories.filter((s) => ! s.locked))
const hasStory = computed(() => viewableStories.value.length > 0)
const hasUnseen = computed(() => viewableStories.value.some((s) => ! s.seen))
const ringClass = computed(() => {
    if (hasUnseen.value) return 'bg-gradient-to-br from-limen-gold to-gold-light'
    if (hasStory.value) return 'bg-limen-line'
    return 'bg-limen-gold' // sem story: mantém a borda dourada permanente de antes
})

// Toque no avatar: com story → menu (Ver story / Ver foto); sem story → abre a
// foto direto. O menu fecha no toque fora (backdrop) ou Esc.
const menuOpen = ref(false)
function onAvatarClick() {
    if (hasStory.value) {
        menuOpen.value = ! menuOpen.value
    } else {
        emit('open-avatar-photo')
    }
}
function chooseStory() {
    menuOpen.value = false
    emit('open-story')
}
function choosePhoto() {
    menuOpen.value = false
    emit('open-avatar-photo')
}
function onKey(e) {
    if (e.key === 'Escape') menuOpen.value = false
}
if (typeof window !== 'undefined') window.addEventListener('keydown', onKey)
onBeforeUnmount(() => {
    if (typeof window !== 'undefined') window.removeEventListener('keydown', onKey)
})

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
        <!-- CAPA COMO FAIXA (item 1/3): altura FIXA com teto ~280px no desktop (não
             `aspect-ratio`, que combinado com max-height não capava de forma
             confiável e deixava a capa gigante). object-cover trata a capa como
             banner; véu escuro na base para o que vem por cima ser legível; cantos
             arredondados DENTRO do container centralizado (nunca full-bleed). Sem
             capa: mármore da marca (CSS), nunca vazio. -->
        <div class="profile-cover relative h-40 w-full overflow-hidden rounded-2xl sm:h-52 lg:h-[280px]">
            <img
                v-if="performer.cover_url"
                :src="performer.cover_url"
                :alt="`Capa de ${performer.stage_name}`"
                class="h-full w-full object-cover"
            />
            <div v-else class="profile-marble h-full w-full" aria-hidden="true" />
            <!-- Véu: escurece a base para o avatar que a sobrepõe. -->
            <div class="absolute inset-0 bg-gradient-to-t from-limen-bg/80 via-transparent to-transparent" />

            <div v-if="liveNow" class="absolute right-4 top-4">
                <LiveBadge />
            </div>
        </div>

        <!-- AVATAR + IDENTIDADE (item 2/3): SÓ O AVATAR sobrepõe a base da capa
             (margem negativa nele, não na linha), sempre POR CIMA (z-10, fora do
             overflow da capa) e nunca cortado. O bloco do NOME fica na linha normal,
             ABAIXO da capa — nunca sob ela (corrige o nome/selo cobertos no mobile). -->
        <div class="flex gap-4 px-1 sm:gap-5">
            <!-- Avatar clicável: ANEL de story (gold=não visto, cinza=visto,
                 dourado sólido=sem story) + toque abre story/foto. -->
            <div class="relative z-10 -mt-10 shrink-0 sm:-mt-14">
                <button
                    type="button"
                    class="mi-press block rounded-full p-[3px] shadow-2xl transition-transform active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/50"
                    :class="ringClass"
                    :aria-label="hasStory ? `Abrir story ou foto de ${performer.stage_name}` : `Ver a foto de ${performer.stage_name}`"
                    @click="onAvatarClick"
                >
                    <!-- object-contain: avatar 1:1 preenche o círculo; imagem fora de
                         1:1 (semente) aparece inteira, sem cortar o rosto. A borda
                         interna (limen-bg) cria o respiro entre o anel e a foto. -->
                    <span class="block h-24 w-24 overflow-hidden rounded-full border-2 border-limen-bg bg-limen-surface-2 sm:h-28 sm:w-28">
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            class="h-full w-full object-contain"
                        />
                        <span v-else class="flex h-full w-full items-center justify-center font-serif text-4xl text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
                    </span>
                </button>

                <!-- Backdrop invisível: toque fora fecha o menu (mobile-first). -->
                <div v-if="menuOpen" class="fixed inset-0 z-10" aria-hidden="true" @click="menuOpen = false" />

                <!-- Menu do avatar (só quando há story visível). -->
                <div
                    v-if="menuOpen"
                    class="absolute left-0 top-full z-20 mt-2 w-52 overflow-hidden rounded-xl border border-limen-line bg-limen-surface shadow-2xl"
                    role="menu"
                >
                    <button
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 px-4 py-3 text-left text-sm text-limen-ink hover:bg-limen-surface-2"
                        @click="chooseStory"
                    >
                        <svg class="h-4 w-4 shrink-0 text-limen-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-dasharray="3 3" /><circle cx="12" cy="12" r="4" /></svg>
                        Ver story
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 border-t border-limen-line px-4 py-3 text-left text-sm text-limen-ink hover:bg-limen-surface-2"
                        @click="choosePhoto"
                    >
                        <svg class="h-4 w-4 shrink-0 text-limen-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" /><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6" /></svg>
                        Ver foto de perfil
                    </button>
                </div>
            </div>

            <div class="min-w-0 flex-1 pt-2">
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
