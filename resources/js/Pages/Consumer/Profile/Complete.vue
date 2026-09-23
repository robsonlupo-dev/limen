<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import CityAutocomplete from '@/Components/Catalog/CityAutocomplete.vue'

/**
 * Assistente de conclusão do perfil (feat/member-profile-wizard, etapa 3): uma
 * pergunta por tela, barra de progresso, "Pular" em tudo. Referência de UX: o
 * cadastro em passos do Seeking.
 *
 * NÃO é uma feature nova de dados: escreve nos MESMOS campos públicos do editor
 * (`consumer.profile.public.update`) e, no fim, liga o opt-in mestre
 * (`consumer.gallery.visibility`). Pular = não preenche = não aparece (decisão
 * do PO): nenhum campo é obrigatório, e a privacidade não muda — esconder um
 * campo é de todo membro, não de tier.
 *
 * O editor "tudo numa página" (Consumer/Profile/Edit) continua sendo a via de
 * edição avulsa; este é a via GUIADA, alcançada por um CTA de lá.
 */
const props = defineProps({
    public_profile: { type: Object, default: () => ({}) },
    publicProfileOptions: { type: Object, default: () => ({}) },
    nickname: { type: String, default: null },
    profile_visible: { type: Boolean, default: false },
})

const pp = props.public_profile ?? {}
const opts = props.publicProfileOptions ?? {}
const maxSeeking = computed(() => opts.max_seeking ?? 6)
const maxInterests = computed(() => opts.max_interests ?? 10)

// Um form só, pré-preenchido com o que já existe. Submetido de uma vez no fim.
const form = useForm({
    headline: pp.headline ?? '',
    profile_city: pp.profile_city ?? '',
    profile_uf: pp.profile_uf ?? '',
    profile_city_2: pp.profile_city_2 ?? '',
    profile_uf_2: pp.profile_uf_2 ?? '',
    profile_city_3: pp.profile_city_3 ?? '',
    profile_uf_3: pp.profile_uf_3 ?? '',
    show_age_band: pp.show_age_band ?? false,
    height_cm: pp.height_cm ?? '',
    weight_kg: pp.weight_kg ?? '',
    marital_status: pp.marital_status ?? '',
    education: pp.education ?? '',
    occupation_area: pp.occupation_area ?? '',
    children: pp.children ?? '',
    drinks: pp.drinks ?? '',
    smokes: pp.smokes ?? '',
    availability: pp.availability ?? '',
    public_seeking: [...(pp.public_seeking ?? [])],
    public_interests: [...(pp.public_interests ?? [])],
    bio: pp.bio ?? '',
})

// Passo final: ligar o perfil visível (default = já ligado se estava, senão sim,
// porque o objetivo do assistente é justamente deixar o perfil pronto e visível).
const makeVisible = ref(props.profile_visible || true)

// ── Passos ────────────────────────────────────────────────────────────────
// `kind` decide o input; `optional` mostra o "Pular". intro/final são molduras.
const steps = [
    { key: 'intro', kind: 'intro' },
    { key: 'headline', kind: 'text', optional: true, title: 'Um título para você', subtitle: 'Uma frase curta que aparece em destaque no seu perfil.' },
    { key: 'city', kind: 'city', optional: true, title: 'Onde você está?', subtitle: 'Só a cidade — nunca o bairro ou endereço.' },
    { key: 'cities_extra', kind: 'cities_extra', optional: true, title: 'Frequenta outras cidades?', subtitle: 'Opcional. Até duas cidades a mais.' },
    { key: 'age_band', kind: 'age_band', optional: true, title: 'Mostrar sua faixa etária?', subtitle: 'A performer vê a faixa (ex.: "31-40"). Nunca sua idade exata.' },
    { key: 'height', kind: 'select', field: 'height_cm', options: 'heights', optional: true, title: 'Sua altura', subtitle: 'Em faixas de 5 cm.' },
    { key: 'weight', kind: 'select', field: 'weight_kg', options: 'weights', optional: true, title: 'Seu peso', subtitle: 'Aparece como faixa (ex.: "65–69 kg"), nunca o valor exato.' },
    { key: 'marital', kind: 'select', field: 'marital_status', options: 'marital', optional: true, title: 'Estado civil' },
    { key: 'education', kind: 'select', field: 'education', options: 'education', optional: true, title: 'Escolaridade' },
    { key: 'occupation_area', kind: 'select', field: 'occupation_area', options: 'occupation_area', optional: true, title: 'Área de atuação' },
    { key: 'children', kind: 'select', field: 'children', options: 'children', optional: true, title: 'Filhos' },
    { key: 'drinks', kind: 'select', field: 'drinks', options: 'drinks', optional: true, title: 'Você bebe?' },
    { key: 'smokes', kind: 'select', field: 'smokes', options: 'smokes', optional: true, title: 'Você fuma?' },
    { key: 'availability', kind: 'select', field: 'availability', options: 'availability', optional: true, title: 'Sua disponibilidade' },
    { key: 'seeking', kind: 'chips', field: 'public_seeking', options: 'seeking', max: 'seeking', optional: true, title: 'O que você busca?', subtitle: 'Escolha o que fizer sentido.' },
    { key: 'interests', kind: 'chips', field: 'public_interests', options: 'interests', max: 'interests', optional: true, title: 'Seus interesses' },
    { key: 'bio', kind: 'textarea', field: 'bio', optional: true, title: 'Sobre você', subtitle: 'Sem telefone, e-mail, link ou rede social.' },
    { key: 'final', kind: 'final' },
]

const stepIndex = ref(0)
const current = computed(() => steps[stepIndex.value])
// Progresso 0→100 pela posição (o passo final conta como completo).
const progress = computed(() => Math.round((stepIndex.value / (steps.length - 1)) * 100))
const isFirst = computed(() => stepIndex.value === 0)

function next() {
    if (stepIndex.value < steps.length - 1) stepIndex.value += 1
}
function back() {
    if (stepIndex.value > 0) stepIndex.value -= 1
}

// Chips com teto (desmarca sempre; marca até o limite).
function toggleChip(list, value, max) {
    const i = list.indexOf(value)
    if (i !== -1) { list.splice(i, 1); return }
    if (list.length >= max) return
    list.push(value)
}

function onCity(n, { name, uf }) {
    form[`profile_city${n}`] = name
    form[`profile_uf${n}`] = uf ?? ''
}

const numOrNull = (v) => (v === '' || v == null ? null : Number(v))
const strOrNull = (v) => (typeof v === 'string' ? v.trim() || null : v || null)

const saving = ref(false)

function finish() {
    if (saving.value) return
    saving.value = true
    form
        .transform((data) => ({
            ...data,
            headline: strOrNull(data.headline),
            bio: strOrNull(data.bio),
            profile_city: strOrNull(data.profile_city),
            profile_uf: strOrNull(data.profile_uf),
            profile_city_2: strOrNull(data.profile_city_2),
            profile_uf_2: strOrNull(data.profile_uf_2),
            profile_city_3: strOrNull(data.profile_city_3),
            profile_uf_3: strOrNull(data.profile_uf_3),
            marital_status: data.marital_status || null,
            education: data.education || null,
            occupation_area: data.occupation_area || null,
            children: data.children || null,
            drinks: data.drinks || null,
            smokes: data.smokes || null,
            availability: data.availability || null,
            height_cm: numOrNull(data.height_cm),
            weight_kg: numOrNull(data.weight_kg),
        }))
        .put(route('consumer.profile.public.update'), {
            preserveScroll: true,
            onSuccess: () => {
                // Liga o opt-in mestre se pedido e ainda não estava — segunda
                // porta (endpoint próprio). Depois volta ao editor com o sucesso.
                if (makeVisible.value && !props.profile_visible) {
                    router.patch(
                        route('consumer.gallery.visibility'),
                        { profile_visible: true },
                        { preserveScroll: true, onFinish: goToEdit },
                    )
                } else {
                    goToEdit()
                }
            },
            onFinish: () => (saving.value = false),
        })
}

function goToEdit() {
    router.visit(route('consumer.profile.edit'))
}
</script>

<template>
    <AppLayout title="Completar meu perfil">
        <div class="mx-auto flex min-h-[70vh] max-w-lg flex-col px-5 py-6">
            <!-- Cabeçalho: progresso + sair. -->
            <div class="flex items-center gap-3">
                <Link :href="route('consumer.profile.edit')" class="text-sm text-muted no-underline hover:text-cream" aria-label="Sair do assistente">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </Link>
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-2" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full bg-gold transition-all duration-300" :style="{ width: progress + '%' }"></div>
                </div>
                <span class="w-10 text-right text-xs tabular-nums text-muted">{{ progress }}%</span>
            </div>

            <!-- Corpo do passo -->
            <div class="mt-10 flex-1">
                <!-- Intro -->
                <div v-if="current.kind === 'intro'" class="space-y-4 text-center">
                    <h1 class="font-serif text-3xl text-cream">Vamos completar seu perfil</h1>
                    <p class="text-sm leading-relaxed text-muted">
                        Leva uns dois minutos. Um campo por vez — e você pode <strong class="text-cream">pular</strong> o que não quiser responder.
                        Só aparece para as performers o que você preencher.
                    </p>
                </div>

                <!-- Passos com pergunta -->
                <div v-else-if="current.kind !== 'final'" class="space-y-6">
                    <div class="space-y-1.5">
                        <h1 class="font-serif text-2xl text-cream">{{ current.title }}</h1>
                        <p v-if="current.subtitle" class="text-sm text-muted">{{ current.subtitle }}</p>
                    </div>

                    <!-- Texto (headline) -->
                    <div v-if="current.kind === 'text'">
                        <input
                            v-model="form.headline"
                            type="text"
                            maxlength="80"
                            placeholder="Ex.: Amante de boas conversas e viagens"
                            class="w-full rounded-lg border border-frame bg-surface-2 px-4 py-3 text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                        />
                        <div class="mt-1 text-right text-xs tabular-nums text-muted">{{ form.headline.length }}/80</div>
                        <p v-if="form.errors.headline" class="text-xs text-danger">{{ form.errors.headline }}</p>
                    </div>

                    <!-- Cidade principal -->
                    <div v-else-if="current.kind === 'city'">
                        <CityAutocomplete v-model="form.profile_city" placeholder="Digite sua cidade…" aria-label="Cidade" @select="(p) => onCity('', p)" />
                        <p v-if="form.errors.profile_city" class="mt-1 text-xs text-danger">{{ form.errors.profile_city }}</p>
                    </div>

                    <!-- Cidades extras -->
                    <div v-else-if="current.kind === 'cities_extra'" class="space-y-4">
                        <div>
                            <label class="mb-1.5 block text-sm text-cream">2ª cidade</label>
                            <CityAutocomplete v-model="form.profile_city_2" placeholder="Outra cidade que você frequenta…" aria-label="Segunda cidade" @select="(p) => onCity('_2', p)" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm text-cream">3ª cidade</label>
                            <CityAutocomplete v-model="form.profile_city_3" placeholder="Mais uma cidade…" aria-label="Terceira cidade" @select="(p) => onCity('_3', p)" />
                        </div>
                    </div>

                    <!-- Faixa etária (toggle) -->
                    <div v-else-if="current.kind === 'age_band'">
                        <div class="flex items-center justify-between gap-4 rounded-xl border border-frame bg-surface-2 p-4">
                            <div class="min-w-0">
                                <p class="text-sm text-cream">Mostrar minha faixa etária</p>
                                <p class="text-xs text-muted">
                                    {{ pp.age_band ? `As performers veem "${pp.age_band}".` : 'Nunca a idade exata ou a data de nascimento.' }}
                                </p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="form.show_age_band"
                                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors"
                                :class="form.show_age_band ? 'bg-gold' : 'border border-frame bg-surface'"
                                @click="form.show_age_band = !form.show_age_band"
                            >
                                <span class="inline-block h-5 w-5 transform rounded-full bg-cream transition-transform" :class="form.show_age_band ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Select controlado -->
                    <div v-else-if="current.kind === 'select'">
                        <select
                            v-model="form[current.field]"
                            class="min-h-[48px] w-full rounded-lg border border-frame bg-surface-2 px-4 text-cream focus:border-gold focus:outline-none"
                        >
                            <option value="">Prefiro não dizer</option>
                            <option v-for="opt in (opts[current.options] ?? [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>

                    <!-- Chips com teto -->
                    <div v-else-if="current.kind === 'chips'">
                        <div class="mb-2 text-right text-xs tabular-nums text-muted">
                            {{ form[current.field].length }}/{{ current.max === 'seeking' ? maxSeeking : maxInterests }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="opt in (opts[current.options] ?? [])"
                                :key="opt.value"
                                type="button"
                                :aria-pressed="form[current.field].includes(opt.value)"
                                :disabled="!form[current.field].includes(opt.value) && form[current.field].length >= (current.max === 'seeking' ? maxSeeking : maxInterests)"
                                class="rounded-full border px-3 py-1.5 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                :class="form[current.field].includes(opt.value) ? 'border-gold bg-gold/10 text-gold' : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                @click="toggleChip(form[current.field], opt.value, current.max === 'seeking' ? maxSeeking : maxInterests)"
                            >{{ opt.label }}</button>
                        </div>
                    </div>

                    <!-- Textarea (bio) -->
                    <div v-else-if="current.kind === 'textarea'">
                        <textarea
                            v-model="form.bio"
                            rows="5"
                            maxlength="400"
                            placeholder="Conte um pouco sobre você…"
                            class="w-full rounded-lg border border-frame bg-surface-2 px-4 py-3 text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                        />
                        <div class="mt-1 text-right text-xs tabular-nums text-muted">{{ form.bio.length }}/400</div>
                        <p v-if="form.errors.bio" class="text-xs text-danger">{{ form.errors.bio }}</p>
                    </div>
                </div>

                <!-- Final: revisão + visível -->
                <div v-else class="space-y-6 text-center">
                    <svg class="mx-auto h-14 w-14 text-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M8 12.5l2.5 2.5L16 9.5" /></svg>
                    <div class="space-y-1.5">
                        <h1 class="font-serif text-2xl text-cream">Tudo pronto{{ nickname ? `, ${nickname}` : '' }}!</h1>
                        <p class="text-sm text-muted">Você pode editar qualquer campo depois, no seu perfil.</p>
                    </div>
                    <label class="flex items-start gap-3 rounded-xl border border-gold/30 bg-gold/5 p-4 text-left">
                        <input v-model="makeVisible" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-gold" />
                        <span class="text-sm text-cream">
                            Deixar meu perfil visível para as performers
                            <span class="mt-0.5 block text-xs text-muted">Sem isso, as performers não te encontram no catálogo. Você liga e desliga quando quiser.</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Rodapé: navegação. Alvos ≥44px. -->
            <div class="mt-8 flex items-center gap-3">
                <button
                    v-if="!isFirst"
                    type="button"
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-full border border-frame text-muted transition-colors hover:text-cream"
                    aria-label="Voltar"
                    @click="back"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                </button>

                <button
                    v-if="current.optional"
                    type="button"
                    class="min-h-[44px] px-2 text-sm text-muted transition-colors hover:text-cream"
                    @click="next"
                >Pular</button>

                <Button v-if="current.kind === 'intro'" class="ml-auto" @click="next">Começar</Button>
                <Button v-else-if="current.kind === 'final'" class="ml-auto" :disabled="saving" @click="finish">
                    {{ saving ? 'Salvando…' : 'Concluir' }}
                </Button>
                <Button v-else class="ml-auto" @click="next">Continuar</Button>
            </div>
        </div>
    </AppLayout>
</template>
