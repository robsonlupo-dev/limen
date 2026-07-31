<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PhotoGalleryManager from '@/Components/PhotoGalleryManager.vue'
import {
    TAG_GROUPS,
    MAX_TAGS,
    LANGUAGES,
    DRINKS,
    SMOKES,
    HEIGHT_MIN_CM,
    HEIGHT_MAX_CM,
    STATES,
} from '@/lib/performerAttributes'

const props = defineProps({
    profile: { type: Object, required: true },
    // Galeria de fotos (Sprint 10): estado inicial + teto. As mutações vão por
    // endpoints JSON próprios (ver PhotoGalleryManager), não por este form.
    photos: { type: Array, default: () => [] },
    maxPhotos: { type: Number, default: 6 },
})

const avatarForm = useForm({ file: null })
const avatarPreview = ref(null)

// Os quatro mundos oficiais (espelha PerformerProfile::WORLDS). O servidor
// revalida com Rule::in — isto é só o rótulo da tela.
const WORLDS = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const profileForm = useForm({
    stage_name: props.profile.stage_name ?? '',
    bio: props.profile.bio ?? '',
    // Pré-seleção: `worlds` é a fonte da verdade; perfil legado (worlds null)
    // cai no único mundo da `category`.
    worlds: props.profile.worlds ?? [props.profile.category].filter(Boolean),
    // "Sobre mim". O servidor revalida tudo (Rule::in + max) — o que a tela faz
    // aqui é impedir o estado inválido, não substituir a validação.
    tags: [...(props.profile.tags ?? [])],
    languages: [...(props.profile.languages ?? [])],
    drinks: props.profile.drinks ?? null,
    smokes: props.profile.smokes ?? null,
    height_cm: props.profile.height_cm ?? null,
    looking_for: props.profile.looking_for ?? '',
    // Localização opt-in. Os dois começam nulos/vazios para quem nunca
    // preencheu — não há valor "padrão" a sugerir aqui.
    state: props.profile.state ?? null,
    city: props.profile.city ?? '',
})

// Feedback de preenchimento da bio: só empurra a performer a escrever mais.
// Não é validação — o servidor não exige tamanho mínimo e nada aqui trava o
// submit. Ordem decrescente: o primeiro `min` alcançado ganha.
const BIO_TIERS = [
    { min: 300, text: 'Perfil completo atrai mais membros ✓', class: 'text-success' },
    { min: 150, text: 'Você está indo bem! 🔥', class: 'text-gold' },
    { min: 50, text: 'Bom começo! Continue...', class: 'text-muted' },
    { min: 0, text: 'Conte mais sobre você...', class: 'text-muted' },
]

const bioLength = computed(() => profileForm.bio.length)
const bioTier = computed(() => BIO_TIERS.find((tier) => bioLength.value >= tier.min))

function toggleWorld(value) {
    const i = profileForm.worlds.indexOf(value)
    if (i === -1) profileForm.worlds.push(value)
    else profileForm.worlds.splice(i, 1)
}

// Tags: teto de MAX_TAGS. Desmarcar sempre funciona; marcar só até o teto —
// senão a performer no limite fica sem entender por que o clique não pega.
const tagCount = computed(() => profileForm.tags.length)
const tagLimitReached = computed(() => tagCount.value >= MAX_TAGS)

function isTagSelected(value) {
    return profileForm.tags.includes(value)
}

function toggleTag(value) {
    const i = profileForm.tags.indexOf(value)
    if (i !== -1) {
        profileForm.tags.splice(i, 1)
        return
    }
    if (tagLimitReached.value) return
    profileForm.tags.push(value)
}

function toggleLanguage(value) {
    const i = profileForm.languages.indexOf(value)
    if (i === -1) profileForm.languages.push(value)
    else profileForm.languages.splice(i, 1)
}

// Radio que aceita desmarcar: clicar na opção já ativa limpa o campo. Sem isso
// não haveria como voltar a "não informado" depois do primeiro clique — e o
// campo é nullable no banco justamente porque informar é opcional.
function toggleChoice(field, value) {
    profileForm[field] = profileForm[field] === value ? null : value
}

// Localização: os dois campos são independentes e opcionais, então "tem
// localização" é ter QUALQUER um dos dois — é o que decide se o link de limpar
// aparece. Limpar manda o par vazio, e o servidor (nullable) apaga as colunas;
// `city: ''` chega como null pela normalização do Form Request do Laravel.
const hasLocation = computed(() => Boolean(profileForm.state) || profileForm.city.trim() !== '')

function clearLocation() {
    profileForm.state = null
    profileForm.city = ''
}

// Pelo menos um mundo é obrigatório. Validação inline (o servidor também
// recusa min:1) — trava o submit e mostra o aviso.
const worldsInvalid = computed(() => profileForm.worlds.length === 0)

// Trocar o nome regenera o slug: a URL pública muda e a antiga deixa de existir.
// Avisar antes de salvar, não depois — depois o link já quebrou.
const willRename = computed(
    () => profileForm.stage_name.trim() !== '' && profileForm.stage_name.trim() !== props.profile.stage_name,
)

const currentAvatar = computed(() => avatarPreview.value ?? props.profile.avatar_url)

function submitAvatar(event) {
    const file = event.target.files[0]
    if (!file) return

    avatarPreview.value = URL.createObjectURL(file)
    avatarForm.file = file
    avatarForm.post(route('performer.profile.photo'), {
        forceFormData: true,
        preserveScroll: true,
        onError: () => (avatarPreview.value = null),
    })
}

function save() {
    if (worldsInvalid.value) return
    profileForm.post(route('performer.profile.save'), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Editar perfil">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Editar perfil</h1>
                    <p class="text-muted text-sm">Seu nome, sua bio e sua foto — como o público te vê.</p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <!-- Avatar -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <h2 class="font-serif text-xl text-cream">Foto de perfil</h2>

                <div class="flex items-center gap-6">
                    <div class="h-24 w-24 rounded-full overflow-hidden bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                        <img v-if="currentAvatar" :src="currentAvatar" alt="Sua foto de perfil" class="h-full w-full object-cover" />
                        <span v-else class="font-serif text-3xl text-gold">{{ profile.stage_name?.charAt(0) }}</span>
                    </div>

                    <div class="space-y-2">
                        <label class="cursor-pointer inline-block">
                            <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                                {{ avatarForm.processing ? 'Enviando...' : 'Trocar foto' }}
                            </span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                :disabled="avatarForm.processing"
                                @change="submitAvatar"
                            />
                        </label>
                        <p class="text-xs text-muted">JPG, PNG ou WebP. Até 5 MB.</p>
                        <p v-if="avatarForm.errors.file" class="text-xs text-danger">{{ avatarForm.errors.file }}</p>
                    </div>
                </div>
            </div>

            <!-- Galeria de fotos (Sprint 10) -->
            <PhotoGalleryManager :initial-photos="photos" :max-photos="maxPhotos" />

            <!-- Name & bio -->
            <form class="rounded-xl border border-frame bg-surface p-6 space-y-5" @submit.prevent="save">
                <h2 class="font-serif text-xl text-cream">Nome e bio</h2>

                <Input
                    id="stage_name"
                    v-model="profileForm.stage_name"
                    label="Nome artístico"
                    required
                    :error="profileForm.errors.stage_name"
                />

                <div class="flex flex-col gap-1.5">
                    <label for="bio" class="text-sm font-medium text-cream">Bio</label>
                    <textarea
                        id="bio"
                        v-model="profileForm.bio"
                        rows="5"
                        maxlength="5000"
                        placeholder="Conte aos membros premium o que te torna única..."
                        class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                    />
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-xs transition-colors" :class="bioTier.class">{{ bioTier.text }}</p>
                        <span class="text-xs text-muted tabular-nums shrink-0">{{ bioLength }}/5000</span>
                    </div>
                    <p v-if="profileForm.errors.bio" class="text-xs text-danger">{{ profileForm.errors.bio }}</p>
                </div>

                <!-- Mundos: onde a performer aparece no catálogo. Múltipla
                     escolha; ao menos um. A category é derivada de worlds[0]
                     no servidor. -->
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-cream">Mundos</span>
                    <p class="text-xs text-muted">Em quais mundos você aparece no catálogo. Escolha ao menos um.</p>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <label
                            v-for="world in WORLDS"
                            :key="world.value"
                            class="flex items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm cursor-pointer transition-colors"
                            :class="profileForm.worlds.includes(world.value)
                                ? 'border-gold bg-gold/10 text-gold'
                                : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                        >
                            <input
                                type="checkbox"
                                class="accent-gold h-4 w-4"
                                :value="world.value"
                                :checked="profileForm.worlds.includes(world.value)"
                                @change="toggleWorld(world.value)"
                            />
                            {{ world.label }}
                        </label>
                    </div>

                    <p v-if="worldsInvalid" class="text-xs text-danger">Selecione pelo menos um mundo.</p>
                    <p v-else-if="profileForm.errors.worlds" class="text-xs text-danger">{{ profileForm.errors.worlds }}</p>
                </div>

                <!-- Sobre mim: tudo opcional e auto-declarado. Aparece no seu
                     perfil público — a copy abaixo diz isso à performer antes
                     de ela preencher, não depois. -->
                <div class="border-t border-frame pt-5 space-y-6">
                    <div class="space-y-1">
                        <h2 class="font-serif text-xl text-cream">Sobre mim</h2>
                        <p class="text-xs text-muted">
                            Tudo aqui é opcional e aparece no seu perfil público. Preencha só o que
                            quiser mostrar.
                        </p>
                    </div>

                    <!-- Tags -->
                    <div class="flex flex-col gap-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-cream">Tags</span>
                            <span class="text-xs text-muted tabular-nums shrink-0">
                                {{ tagCount }}/{{ MAX_TAGS }} selecionadas
                            </span>
                        </div>
                        <p class="text-xs text-muted">
                            Escolha até {{ MAX_TAGS }} que te descrevem. Membros usam as tags para
                            te encontrar.
                        </p>

                        <div v-for="group in TAG_GROUPS" :key="group.key" class="space-y-2">
                            <p class="text-xs text-muted uppercase tracking-wide">{{ group.label }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="tag in group.tags"
                                    :key="tag.value"
                                    type="button"
                                    :disabled="!isTagSelected(tag.value) && tagLimitReached"
                                    :aria-pressed="isTagSelected(tag.value)"
                                    class="rounded-full border px-3 py-1.5 text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="isTagSelected(tag.value)
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                    @click="toggleTag(tag.value)"
                                >
                                    {{ tag.label }}
                                </button>
                            </div>
                        </div>

                        <p v-if="tagLimitReached" class="text-xs text-muted">
                            Limite atingido. Desmarque uma tag para escolher outra.
                        </p>
                        <p v-if="profileForm.errors.tags" class="text-xs text-danger">{{ profileForm.errors.tags }}</p>
                    </div>

                    <!-- Idiomas -->
                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-medium text-cream">Idiomas</span>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1">
                            <label
                                v-for="language in LANGUAGES"
                                :key="language.value"
                                class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors"
                                :class="profileForm.languages.includes(language.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                            >
                                <input
                                    type="checkbox"
                                    class="accent-gold h-4 w-4"
                                    :value="language.value"
                                    :checked="profileForm.languages.includes(language.value)"
                                    @change="toggleLanguage(language.value)"
                                />
                                {{ language.label }}
                            </label>
                        </div>
                        <p v-if="profileForm.errors.languages" class="text-xs text-danger">{{ profileForm.errors.languages }}</p>
                    </div>

                    <!-- Bebida / Fumo -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div class="flex flex-col gap-2">
                            <span class="text-sm font-medium text-cream">Bebida</span>
                            <div class="flex flex-col gap-2 pt-1">
                                <label
                                    v-for="option in DRINKS"
                                    :key="option.value"
                                    class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors"
                                    :class="profileForm.drinks === option.value
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                >
                                    <input
                                        type="radio"
                                        name="drinks"
                                        class="accent-gold h-4 w-4"
                                        :value="option.value"
                                        :checked="profileForm.drinks === option.value"
                                        @click="toggleChoice('drinks', option.value)"
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                            <p v-if="profileForm.errors.drinks" class="text-xs text-danger">{{ profileForm.errors.drinks }}</p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <span class="text-sm font-medium text-cream">Fumo</span>
                            <div class="flex flex-col gap-2 pt-1">
                                <label
                                    v-for="option in SMOKES"
                                    :key="option.value"
                                    class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors"
                                    :class="profileForm.smokes === option.value
                                        ? 'border-gold bg-gold/10 text-gold'
                                        : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                >
                                    <input
                                        type="radio"
                                        name="smokes"
                                        class="accent-gold h-4 w-4"
                                        :value="option.value"
                                        :checked="profileForm.smokes === option.value"
                                        @click="toggleChoice('smokes', option.value)"
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                            <p v-if="profileForm.errors.smokes" class="text-xs text-danger">{{ profileForm.errors.smokes }}</p>
                        </div>
                    </div>

                    <!-- Altura. O slider não consegue expressar "não informado",
                         então o campo nasce vazio e há como voltar a vazio. -->
                    <div class="flex flex-col gap-2">
                        <div class="flex items-baseline justify-between gap-3">
                            <label for="height_cm" class="text-sm font-medium text-cream">Altura</label>
                            <span class="text-xs tabular-nums shrink-0" :class="profileForm.height_cm ? 'text-gold' : 'text-muted'">
                                {{ profileForm.height_cm ? `${profileForm.height_cm} cm` : 'Não informado' }}
                            </span>
                        </div>

                        <div v-if="profileForm.height_cm" class="flex items-center gap-3">
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ HEIGHT_MIN_CM }}</span>
                            <input
                                id="height_cm"
                                v-model.number="profileForm.height_cm"
                                type="range"
                                class="accent-gold w-full"
                                :min="HEIGHT_MIN_CM"
                                :max="HEIGHT_MAX_CM"
                                step="1"
                            />
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ HEIGHT_MAX_CM }}</span>
                            <button
                                type="button"
                                class="text-xs text-muted underline underline-offset-4 hover:text-cream transition-colors shrink-0"
                                @click="profileForm.height_cm = null"
                            >
                                Limpar
                            </button>
                        </div>
                        <button
                            v-else
                            type="button"
                            class="self-start rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream hover:border-gold/50 transition-colors"
                            @click="profileForm.height_cm = 165"
                        >
                            Informar altura
                        </button>

                        <p v-if="profileForm.errors.height_cm" class="text-xs text-danger">{{ profileForm.errors.height_cm }}</p>
                    </div>

                    <!-- O que estou procurando -->
                    <div class="flex flex-col gap-1.5">
                        <label for="looking_for" class="text-sm font-medium text-cream">O que estou procurando</label>
                        <textarea
                            id="looking_for"
                            v-model="profileForm.looking_for"
                            rows="3"
                            maxlength="1000"
                            placeholder="Descreva o tipo de conexão que você busca..."
                            class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                        />
                        <span class="text-xs text-muted tabular-nums self-end">{{ profileForm.looking_for.length }}/1000</span>
                        <p v-if="profileForm.errors.looking_for" class="text-xs text-danger">{{ profileForm.errors.looking_for }}</p>
                    </div>

                    <!-- Localização: opt-in, e o aviso vem ANTES dos campos.
                         Depois deles a performer já teria digitado a cidade sem
                         saber para onde ela vai. -->
                    <div class="flex flex-col gap-3 border-t border-frame pt-5">
                        <div class="flex items-start gap-2 rounded-lg border border-frame bg-surface-2 px-3 py-2.5">
                            <svg
                                class="h-4 w-4 shrink-0 text-gold mt-0.5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M10 1a4 4 0 00-4 4v3H5.5A1.5 1.5 0 004 9.5v7A1.5 1.5 0 005.5 18h9a1.5 1.5 0 001.5-1.5v-7A1.5 1.5 0 0014.5 8H14V5a4 4 0 00-4-4zm2.5 7V5a2.5 2.5 0 10-5 0v3h5z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                            <p class="text-xs leading-relaxed text-muted">
                                Sua localização exata nunca é revelada. Apenas seu estado aparecerá no
                                catálogo.
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="state" class="text-sm font-medium text-cream">Estado</label>
                                <select
                                    id="state"
                                    v-model="profileForm.state"
                                    class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                                >
                                    <option :value="null">Não informado</option>
                                    <option v-for="uf in STATES" :key="uf.value" :value="uf.value">
                                        {{ uf.label }}
                                    </option>
                                </select>
                                <p v-if="profileForm.errors.state" class="text-xs text-danger">{{ profileForm.errors.state }}</p>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="city" class="text-sm font-medium text-cream">Cidade</label>
                                <Input
                                    id="city"
                                    v-model="profileForm.city"
                                    type="text"
                                    maxlength="100"
                                    placeholder="Opcional"
                                />
                                <!-- Dito na tela, não só no código: a cidade fica
                                     guardada mas não é publicada em lugar nenhum. -->
                                <p class="text-xs text-muted">Não aparece no seu perfil nem no catálogo.</p>
                                <p v-if="profileForm.errors.city" class="text-xs text-danger">{{ profileForm.errors.city }}</p>
                            </div>
                        </div>

                        <button
                            v-if="hasLocation"
                            type="button"
                            class="self-start text-xs text-muted underline underline-offset-2 hover:text-cream transition-colors"
                            @click="clearLocation"
                        >
                            Não compartilhar localização
                        </button>
                    </div>
                </div>

                <!-- Public address -->
                <div class="rounded-lg border border-frame bg-surface-2 p-4 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Endereço do seu perfil</p>
                    <p class="text-sm text-cream break-all">/performers/{{ profile.slug }}</p>
                </div>

                <div v-if="willRename" class="rounded-lg border border-gold/30 bg-gold/10 p-4 text-sm text-gold">
                    Trocar seu nome artístico muda o endereço do seu perfil. O endereço atual deixa de
                    funcionar, e quem tiver o link antigo não vai mais te encontrar por ele — vale
                    reenviar o novo para quem importa. Seus seguidores e interesses não se perdem.
                </div>

                <div class="flex justify-end">
                    <Button type="submit" variant="primary" :loading="profileForm.processing" :disabled="worldsInvalid">
                        Salvar alterações
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
