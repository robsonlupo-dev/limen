<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

// Barra de completude do perfil da performer (Sprint 10). 100% frontend: a
// completude é DERIVADA dos campos que já existem no perfil, calculada aqui —
// não há coluna, migration nem endpoint por trás dela. Só incentiva a performer
// a preencher o que deixa o perfil mais atraente no catálogo.
//
// A prop `profile` traz só o necessário para a conta: presença de avatar/capa
// como booleano (o caminho no storage não precisa vazar para medir "tem ou não
// tem") e os campos de "Sobre mim" que a própria performer já vê na tela de
// edição. O Estilo de Vida é do MEMBRO — não é campo de perfil da performer e
// deliberadamente não entra aqui.
const props = defineProps({
    profile: { type: Object, required: true },
})

// Bio e tags têm piso de qualidade, não só presença: uma bio de 3 letras ou uma
// única tag não "completam" o campo. Espelha o incentivo que a tela de edição já
// dá (BIO_TIERS a partir de 150) e o mínimo de descoberta por tags.
const BIO_MIN_CHARS = 150
const TAGS_MIN = 3

function filled(value) {
    return value !== null && value !== undefined && value !== ''
}

// Cada item: peso, se está completo, e a copy com ícone para a lista do que
// falta. A soma dos pesos é 100. Ordem = ordem de exibição na lista de pendentes.
const items = computed(() => {
    const p = props.profile
    const bioLen = typeof p.bio === 'string' ? p.bio.trim().length : 0
    const tagCount = Array.isArray(p.tags) ? p.tags.length : 0
    const langCount = Array.isArray(p.languages) ? p.languages.length : 0

    return [
        { key: 'avatar', weight: 20, done: !!p.has_avatar, label: '📷 Adicionar foto de perfil' },
        { key: 'cover', weight: 10, done: !!p.has_cover, label: '🖼️ Adicionar foto de capa' },
        { key: 'bio', weight: 15, done: bioLen >= BIO_MIN_CHARS, label: `✍️ Escrever bio (mín. ${BIO_MIN_CHARS} caracteres)` },
        { key: 'tags', weight: 15, done: tagCount >= TAGS_MIN, label: `🏷️ Escolher ao menos ${TAGS_MIN} tags` },
        { key: 'looking_for', weight: 10, done: filled(p.looking_for), label: '💬 Descrever o que você procura' },
        { key: 'languages', weight: 5, done: langCount > 0, label: '🗣️ Informar os idiomas que fala' },
        { key: 'drinks', weight: 5, done: filled(p.drinks), label: '🍷 Informar preferência de bebida' },
        { key: 'smokes', weight: 5, done: filled(p.smokes), label: '🚬 Informar preferência de fumo' },
        { key: 'height_cm', weight: 5, done: p.height_cm !== null && p.height_cm !== undefined, label: '📏 Informar sua altura' },
        { key: 'state', weight: 5, done: filled(p.state), label: '📍 Informar seu estado' },
        { key: 'city', weight: 5, done: filled(p.city), label: '🏙️ Informar sua cidade' },
    ]
})

// Pesos são inteiros e somam 100, então a porcentagem é sempre inteira (sem
// arredondamento a definir faixa).
const percent = computed(() => items.value.reduce((sum, item) => (item.done ? sum + item.weight : sum), 0))

const missing = computed(() => items.value.filter((item) => !item.done))

const isComplete = computed(() => percent.value === 100)

// Texto motivacional por faixa (spec do PO). As faixas não se sobrepõem: 100 é
// tratado à parte (barra some), então aqui só chegam 0–99.
const message = computed(() => {
    const pct = percent.value
    if (pct >= 76) return 'Falta pouco para o perfil perfeito!'
    if (pct >= 51) return 'Quase lá! Perfis completos recebem 3x mais visitas'
    if (pct >= 26) return 'Bom começo! Continue preenchendo'
    return 'Seu perfil precisa de atenção'
})
</script>

<template>
    <!-- 100%: sem barra e sem lista — só um selo discreto de concluído. Manter a
         barra cheia todo dia viraria ruído no painel de quem já terminou. -->
    <div
        v-if="isComplete"
        class="flex items-center gap-3 rounded-xl border border-success/30 bg-success/5 px-5 py-4"
    >
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-success/15 text-success" aria-hidden="true">
            ✓
        </span>
        <p class="text-sm text-success">Perfil completo ✓</p>
    </div>

    <!-- < 100%: barra dourada + faixa motivacional + o que falta + atalho. -->
    <div v-else class="rounded-xl border border-frame bg-surface p-6 space-y-4">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="font-serif text-xl text-cream">Complete seu perfil</h2>
            <span class="text-sm text-gold tabular-nums">{{ percent }}%</span>
        </div>

        <p class="text-sm text-muted">{{ message }}</p>

        <!-- Barra dourada (#C9A24B = token `gold`) sobre trilho escuro. -->
        <div
            class="h-2.5 w-full overflow-hidden rounded-full bg-surface-2"
            role="progressbar"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Completude do perfil"
        >
            <div class="h-full rounded-full bg-gold transition-[width] duration-500" :style="{ width: `${percent}%` }" />
        </div>

        <!-- O que falta preencher. Só os pendentes: o que já está pronto não
             precisa ser listado de volta. -->
        <ul class="space-y-1.5 pt-1">
            <li v-for="item in missing" :key="item.key" class="text-sm text-muted">
                {{ item.label }}
            </li>
        </ul>

        <div class="pt-1">
            <Link
                :href="route('performer.profile.edit')"
                class="inline-flex items-center rounded-lg border border-gold px-4 py-2 text-sm text-gold no-underline transition-colors hover:bg-gold/10"
            >
                Completar perfil &rarr;
            </Link>
        </div>
    </div>
</template>
