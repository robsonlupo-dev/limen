<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import ImageCropper from '@/Components/ImageCropper.vue'
import CityAutocomplete from '@/Components/Catalog/CityAutocomplete.vue'
import { TAG_GROUPS, MAX_TAGS } from '@/lib/performerAttributes'

// Os interesses do membro usam o MESMO conjunto de slugs das tags da performer
// — é o que torna o cruzamento de afinidade possível. Por isso a tela importa
// de performerAttributes em vez de ter uma tabela própria de rótulos: duas
// listas divergiriam no primeiro slug novo, e o chip renderizaria o slug cru.
//
// MAX_TAGS espelha User::MAX_INTERESTS (os dois valem 8, pelo mesmo motivo de a
// interseção ser a medida). O servidor revalida — isto aqui impede o estado
// inválido, não substitui a validação.
const MAX_INTERESTS = MAX_TAGS

const props = defineProps({
    profile: { type: Object, required: true },
    // Rótulos e descrições vêm do servidor (App\Support\LifestyleTier), nunca
    // de uma tabela local: o mesmo vocabulário é lido pelo painel da performer,
    // e uma cópia aqui divergiria justo no lado que o membro não vê.
    lifestyleOptions: { type: Array, default: () => [] },
    // Props TOP-LEVEL (o controller as envia fora de `profile`).
    avatar_url: { type: String, default: null },
    // Apelido (feat/member-nickname): valor atual + quando a próxima troca libera.
    nickname: { type: String, default: null },
    nickname_change_available_at: { type: String, default: null },
    // Galeria de perfil (feat/member-gallery-and-profile): TODAS as fotos do dono
    // (qualquer status), o opt-in mestre e o teto de fotos.
    gallery: { type: Array, default: () => [] },
    profile_visible: { type: Boolean, default: false },
    gallery_max: { type: Number, default: 4 },
    // Perfil PÚBLICO v2 (feat/member-profile-v2): valores atuais + preview
    // (age_band/is_verified/member_since derivados) e as listas controladas.
    public_profile: { type: Object, default: () => ({}) },
    publicProfileOptions: { type: Object, default: () => ({}) },
})

const form = useForm({
    interests: [...(props.profile.interests ?? [])],
    seeking: props.profile.seeking ?? '',
})

// Formulário SEPARADO, e não mais um campo no de cima. Os dois têm destinos
// opostos — interesses/seeking nunca saem do servidor, a faixa é exibida à
// performer — e um único botão "Salvar" cobrindo os dois faria o membro
// publicar a faixa no gesto em que ajusta um chip. A rota também é própria:
// `lifestyle_tier` está fora do $fillable do User.
const lifestyleForm = useForm({
    lifestyle_tier: props.profile.lifestyle_tier ?? 'prefer_not_to_say',
})

// ── Perfil PÚBLICO v2 (feat/member-profile-v2) ───────────────────────────────
// Formulário PRÓPRIO (rota própria): TUDO aqui VOLTA para a performer quando o
// perfil está visível — por isso a copy avisa "isto a performer vê" e a caixa
// "só seu" mais abaixo NÃO cobre estes campos.
const pp = props.public_profile ?? {}
const ppOpts = props.publicProfileOptions ?? {}
const publicForm = useForm({
    bio: pp.bio ?? '',
    headline: pp.headline ?? '',
    public_seeking: [...(pp.public_seeking ?? [])],
    public_interests: [...(pp.public_interests ?? [])],
    profile_city: pp.profile_city ?? '',
    profile_uf: pp.profile_uf ?? '',
    profile_city_2: pp.profile_city_2 ?? '',
    profile_uf_2: pp.profile_uf_2 ?? '',
    profile_city_3: pp.profile_city_3 ?? '',
    profile_uf_3: pp.profile_uf_3 ?? '',
    marital_status: pp.marital_status ?? '',
    height_cm: pp.height_cm ?? '',
    weight_kg: pp.weight_kg ?? '',
    education: pp.education ?? '',
    occupation_area: pp.occupation_area ?? '',
    children: pp.children ?? '',
    drinks: pp.drinks ?? '',
    smokes: pp.smokes ?? '',
    availability: pp.availability ?? '',
    show_age_band: pp.show_age_band ?? false,
})

const maxPublicSeeking = computed(() => ppOpts.max_seeking ?? 6)
const maxPublicInterests = computed(() => ppOpts.max_interests ?? 10)

// Toggle genérico de chip com teto (desmarcar sempre; marcar só até o limite).
function toggleChip(list, value, max) {
    const i = list.indexOf(value)
    if (i !== -1) {
        list.splice(i, 1)
        return
    }
    if (list.length >= max) return
    list.push(value)
}

// Preview "como a performer vê": rótulos das tags escolhidas, na ordem da lista.
function labelsFor(options, chosen) {
    return (options ?? []).filter((o) => chosen.includes(o.value)).map((o) => o.label)
}
const previewSeeking = computed(() => labelsFor(ppOpts.seeking, publicForm.public_seeking))
const previewInterests = computed(() => labelsFor(ppOpts.interests, publicForm.public_interests))
const previewMarital = computed(
    () => (ppOpts.marital ?? []).find((o) => o.value === publicForm.marital_status)?.label ?? null,
)
const previewHeight = computed(
    () => (ppOpts.heights ?? []).find((o) => o.value === publicForm.height_cm)?.label ?? null,
)
const previewCity = computed(() => {
    const city = (publicForm.profile_city ?? '').trim()
    if (!city) return null
    const uf = (publicForm.profile_uf ?? '').trim()
    return uf ? `${city}, ${uf}` : city
})

function onCitySelect({ name, uf }) {
    publicForm.profile_city = name
    publicForm.profile_uf = uf ?? ''
}
function onCitySelect2({ name, uf }) {
    publicForm.profile_city_2 = name
    publicForm.profile_uf_2 = uf ?? ''
}
function onCitySelect3({ name, uf }) {
    publicForm.profile_city_3 = name
    publicForm.profile_uf_3 = uf ?? ''
}

// Número vazio → null (não 0); string vazia → null.
const numOrNull = (v) => (v === '' || v == null ? null : Number(v))
const strOrNull = (v) => (typeof v === 'string' ? v.trim() || null : v || null)

function savePublic() {
    publicForm
        .transform((data) => ({
            ...data,
            // '' → null nos escalares (o servidor também normaliza; isto deixa o
            // payload limpo). Números vazios viram null (não 0).
            bio: strOrNull(data.bio),
            headline: strOrNull(data.headline),
            profile_city: strOrNull(data.profile_city),
            profile_uf: strOrNull(data.profile_uf),
            profile_city_2: strOrNull(data.profile_city_2),
            profile_uf_2: strOrNull(data.profile_uf_2),
            profile_city_3: strOrNull(data.profile_city_3),
            profile_uf_3: strOrNull(data.profile_uf_3),
            marital_status: data.marital_status || null,
            height_cm: numOrNull(data.height_cm),
            weight_kg: numOrNull(data.weight_kg),
            education: data.education || null,
            occupation_area: data.occupation_area || null,
            children: data.children || null,
            drinks: data.drinks || null,
            smokes: data.smokes || null,
            availability: data.availability || null,
        }))
        .put(route('consumer.profile.public.update'), { preserveScroll: true })
}

// Teto de MAX_INTERESTS. Desmarcar sempre funciona; marcar só até o teto —
// senão quem está no limite fica sem entender por que o clique não pega.
const interestCount = computed(() => form.interests.length)
const limitReached = computed(() => interestCount.value >= MAX_INTERESTS)

function isSelected(value) {
    return form.interests.includes(value)
}

function toggleInterest(value) {
    const i = form.interests.indexOf(value)
    if (i !== -1) {
        form.interests.splice(i, 1)
        return
    }
    if (limitReached.value) return
    form.interests.push(value)
}

// ── Foto de perfil (fix/member-photo-and-crop) ───────────────────────────────
// Mesmo cropper 1:1 da performer; o corte definitivo é server-side. A foto é
// enquadrada em quadrado, então object-cover preenche o círculo sem barra.
const avatarForm = useForm({ file: null })
const pendingAvatarFile = ref(null)
const avatarPreview = ref(null)
const removingAvatar = ref(false)
const currentAvatar = computed(() => avatarPreview.value ?? props.avatar_url)

// ── Apelido (feat/member-nickname) ────────────────────────────────────────────
const nicknameForm = useForm({ nickname: props.nickname ?? '' })
const removingNickname = ref(false)

// Cooldown: a próxima troca só libera na data-alvo (o servidor manda pronta).
const nicknameLocked = computed(() =>
    props.nickname_change_available_at != null && new Date(props.nickname_change_available_at) > new Date(),
)
const nicknameAvailableLabel = computed(() =>
    props.nickname_change_available_at
        ? new Date(props.nickname_change_available_at).toLocaleDateString('pt-BR')
        : null,
)

function saveNickname() {
    nicknameForm.patch(route('consumer.nickname.update'), { preserveScroll: true })
}

function removeNickname() {
    removingNickname.value = true
    router.delete(route('consumer.nickname.destroy'), {
        preserveScroll: true,
        onSuccess: () => { nicknameForm.nickname = '' },
        onFinish: () => { removingNickname.value = false },
    })
}

function pickAvatar(event) {
    const file = event.target.files[0]
    event.target.value = ''
    if (!file) return
    pendingAvatarFile.value = file
}

function onAvatarCropped(file) {
    pendingAvatarFile.value = null
    avatarPreview.value = URL.createObjectURL(file)
    avatarForm.file = file
    avatarForm.post(route('consumer.profile.photo'), {
        forceFormData: true,
        preserveScroll: true,
        onError: () => (avatarPreview.value = null),
    })
}

function removeAvatar() {
    removingAvatar.value = true
    router.delete(route('consumer.profile.photo.destroy'), {
        preserveScroll: true,
        onSuccess: () => (avatarPreview.value = null),
        onFinish: () => (removingAvatar.value = false),
    })
}

// ── Galeria de perfil (feat/member-gallery-and-profile) ──────────────────────
// Até `gallery_max` fotos, cada uma moderada (pending → aprovada/recusada) antes
// de aparecer para a performer. Sem cropper: a galeria preserva a proporção (o
// servidor só reduz + sanitiza). O opt-in mestre `profile_visible` decide se a
// galeria/perfil ficam acessíveis à performer — default OFF.
// Agora COM cropper (feat/member-profile-v2): o membro enquadra em 3:4 antes de
// enviar; mandamos o ORIGINAL (`file` → variante completa do lightbox) + o
// recorte (`cropped` → variante enquadrada do card). O corte definitivo é
// server-side; o recorte do cliente é UX + o que ele confirma vira o card.
const galleryForm = useForm({ file: null, cropped: null })
const pendingGalleryFile = ref(null)
const galleryVisible = ref(props.profile_visible)
const busyPhotoId = ref(null)

// Ocupam slot: pending + aprovada (a recusada não conta, o membro pode reenviar).
const activeGalleryCount = computed(
    () => props.gallery.filter((p) => p.status === 'pending' || p.status === 'approved').length,
)
const galleryFull = computed(() => activeGalleryCount.value >= props.gallery_max)

const statusLabels = {
    pending: 'Em análise',
    approved: 'Aprovada',
    rejected: 'Recusada',
}

function pickGalleryPhoto(event) {
    const file = event.target.files[0]
    event.target.value = ''
    if (!file) return
    // Abre o cropper 3:4; o envio acontece no confirmar (onGalleryCropped).
    pendingGalleryFile.value = file
}

function onGalleryCropped(cropped) {
    const original = pendingGalleryFile.value
    pendingGalleryFile.value = null
    if (!original) return
    galleryForm.file = original
    galleryForm.cropped = cropped
    galleryForm.post(route('consumer.gallery.store'), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            galleryForm.file = null
            galleryForm.cropped = null
        },
    })
}

function removeGalleryPhoto(photo) {
    busyPhotoId.value = photo.id
    router.delete(route('consumer.gallery.destroy', photo.id), {
        preserveScroll: true,
        onFinish: () => (busyPhotoId.value = null),
    })
}

function makePrimary(photo) {
    busyPhotoId.value = photo.id
    router.patch(route('consumer.gallery.primary', photo.id), {}, {
        preserveScroll: true,
        onFinish: () => (busyPhotoId.value = null),
    })
}

function toggleGalleryVisibility() {
    const next = !galleryVisible.value
    router.patch(route('consumer.gallery.visibility'), { profile_visible: next }, {
        preserveScroll: true,
        onSuccess: () => (galleryVisible.value = next),
    })
}

function save() {
    form.put(route('consumer.profile.update'), { preserveScroll: true })
}

function saveLifestyle() {
    lifestyleForm.patch(route('consumer.profile.lifestyle-tier'), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Meu perfil">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Meu perfil</h1>
                    <p class="text-muted text-sm">Seus interesses e o que você procura no Limen.</p>
                </div>
                <Link :href="route('consumer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <!-- Apelido (feat/member-nickname). PÚBLICO: é como as performers te
                 chamam, e aparece também para os outros membros no chat de uma
                 live. A copy avisa ANTES de salvar — não é bilhete privado. -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <div class="space-y-1">
                    <h2 class="font-serif text-xl text-cream">Seu apelido</h2>
                    <p class="text-xs text-muted">
                        Opcional. É como as performers te chamam no lugar de "Fã #0000".
                        <span class="text-cream">É público</span>: as performers veem, e os outros membros
                        presentes veem no chat de uma live. Não é um bilhete privado. Sem apelido, você
                        continua como está hoje.
                    </p>
                </div>

                <form class="flex flex-col gap-2 sm:flex-row sm:items-start" @submit.prevent="saveNickname">
                    <div class="flex-1">
                        <input
                            v-model="nicknameForm.nickname"
                            type="text"
                            maxlength="20"
                            :disabled="nicknameLocked"
                            placeholder="Ex.: Leo, Viajante, MrNoturno"
                            class="min-h-[44px] w-full rounded-lg border border-frame bg-surface-2 px-4 text-sm text-cream placeholder:text-muted/60 focus:border-gold focus:outline-none focus:ring-1 focus:ring-gold disabled:opacity-50"
                        />
                        <p v-if="nicknameForm.errors.nickname" class="mt-1 text-xs text-danger">{{ nicknameForm.errors.nickname }}</p>
                        <p v-else-if="nicknameLocked" class="mt-1 text-xs text-muted">
                            Você poderá trocar de novo a partir de {{ nicknameAvailableLabel }} (uma troca a cada 7 dias).
                        </p>
                        <p v-else class="mt-1 text-xs text-muted">3 a 20 caracteres. Sem telefone, e-mail, link ou rede social.</p>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" class="min-h-[44px]" :loading="nicknameForm.processing" :disabled="nicknameLocked || nicknameForm.nickname.trim() === ''">
                            Salvar
                        </Button>
                        <Button
                            v-if="nickname"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-[44px]"
                            :disabled="removingNickname"
                            @click="removeNickname"
                        >
                            Remover
                        </Button>
                    </div>
                </form>
            </div>

            <!-- Foto de perfil (fix/member-photo-and-crop). Ao contrário dos campos
                 abaixo, a foto é VISÍVEL para as performers no catálogo — a copy
                 avisa isso ANTES, não nos Termos, como a tela da performer avisa
                 que o campo dela é público. Opcional: pode adicionar depois. -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <div class="space-y-1">
                    <h2 class="font-serif text-xl text-cream">Foto de perfil</h2>
                    <p class="text-xs text-muted">
                        Opcional. Diferente do resto desta tela, sua foto <span class="text-cream">aparece para as
                        performers</span> no catálogo. JPG, PNG ou WebP, até 5 MB. Você enquadra em
                        quadrado antes de salvar.
                    </p>
                </div>

                <div class="flex items-center gap-5">
                    <div class="h-24 w-24 shrink-0 rounded-full border-2 border-gold bg-surface-2 overflow-hidden flex items-center justify-center">
                        <!-- Foto enquadrada 1:1 pela pessoa → object-cover preenche
                             o círculo sem barra. -->
                        <img v-if="currentAvatar" :src="currentAvatar" alt="Sua foto de perfil" class="h-full w-full object-cover" />
                        <svg v-else viewBox="0 0 24 24" fill="none" class="h-12 w-12 text-muted" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" fill="currentColor" opacity="0.5" />
                            <path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="currentColor" opacity="0.5" />
                        </svg>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="cursor-pointer inline-block">
                            <span class="inline-flex min-h-[44px] items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                                {{ avatarForm.processing ? 'Enviando...' : (currentAvatar ? 'Trocar foto' : 'Adicionar foto') }}
                            </span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                :disabled="avatarForm.processing"
                                @change="pickAvatar"
                            />
                        </label>
                        <button
                            v-if="currentAvatar"
                            type="button"
                            class="min-h-[44px] text-left text-sm text-muted hover:text-danger transition-colors disabled:opacity-40"
                            :disabled="removingAvatar"
                            @click="removeAvatar"
                        >
                            {{ removingAvatar ? 'Removendo...' : 'Remover foto' }}
                        </button>
                    </div>
                </div>

                <p v-if="avatarForm.errors.file" class="text-xs text-danger">{{ avatarForm.errors.file }}</p>

                <ImageCropper
                    :file="pendingAvatarFile"
                    :aspect-ratio="1"
                    :output-width="512"
                    title="Enquadre sua foto de perfil"
                    hint="Arraste e ajuste o zoom. A foto fica quadrada no seu perfil."
                    @crop="onAvatarCropped"
                    @cancel="pendingAvatarFile = null"
                />
            </div>

            <!-- Perfil público v2 (feat/member-profile-v2). Diferente da caixa
                 "só seu" mais abaixo, TUDO aqui aparece para as performers no seu
                 perfil — quando "Perfil visível" estiver ligado, e só o que você
                 preencher. A copy avisa ANTES, não nos Termos. -->
            <section class="rounded-xl border border-frame bg-surface p-6 space-y-6">
                <div class="space-y-1">
                    <h2 class="font-serif text-xl text-cream">Perfil público</h2>
                    <p class="text-xs text-muted">
                        Opcional. <span class="text-cream">Isto as performers veem</span> no seu perfil
                        (quando "Perfil visível" estiver ligado). Só aparece o que você preencher.
                    </p>
                </div>

                <form class="space-y-6" @submit.prevent="savePublic">
                    <!-- Sobre mim -->
                    <div class="flex flex-col gap-1.5">
                        <label for="bio" class="text-sm font-medium text-cream">Sobre mim</label>
                        <textarea
                            id="bio"
                            v-model="publicForm.bio"
                            rows="4"
                            maxlength="400"
                            placeholder="Conte um pouco sobre você..."
                            class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                        />
                        <div class="flex items-baseline justify-between">
                            <span class="text-xs text-muted">Sem telefone, e-mail, link ou rede social.</span>
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ publicForm.bio.length }}/400</span>
                        </div>
                        <p v-if="publicForm.errors.bio" class="text-xs text-danger">{{ publicForm.errors.bio }}</p>
                    </div>

                    <!-- Título (headline): frase curta em itálico sob o apelido -->
                    <div class="flex flex-col gap-1.5">
                        <label for="headline" class="text-sm font-medium text-cream">Título</label>
                        <input
                            id="headline"
                            v-model="publicForm.headline"
                            type="text"
                            maxlength="80"
                            placeholder="Uma frase curta que te define"
                            class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                        />
                        <div class="flex items-baseline justify-between">
                            <span class="text-xs text-muted">Aparece em destaque, logo abaixo do seu apelido.</span>
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ publicForm.headline.length }}/80</span>
                        </div>
                        <p v-if="publicForm.errors.headline" class="text-xs text-danger">{{ publicForm.errors.headline }}</p>
                    </div>

                    <!-- O que busco (tags controladas) -->
                    <div class="border-t border-frame pt-6 space-y-2">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-cream">O que busco</span>
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ publicForm.public_seeking.length }}/{{ maxPublicSeeking }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="opt in publicProfileOptions.seeking"
                                :key="opt.value"
                                type="button"
                                :aria-pressed="publicForm.public_seeking.includes(opt.value)"
                                :disabled="!publicForm.public_seeking.includes(opt.value) && publicForm.public_seeking.length >= maxPublicSeeking"
                                class="rounded-full border px-3 py-1.5 text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="publicForm.public_seeking.includes(opt.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                @click="toggleChip(publicForm.public_seeking, opt.value, maxPublicSeeking)"
                            >{{ opt.label }}</button>
                        </div>
                        <p v-if="publicForm.errors.public_seeking" class="text-xs text-danger">{{ publicForm.errors.public_seeking }}</p>
                    </div>

                    <!-- Interesses (tags controladas) -->
                    <div class="border-t border-frame pt-6 space-y-2">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-cream">Interesses</span>
                            <span class="text-xs text-muted tabular-nums shrink-0">{{ publicForm.public_interests.length }}/{{ maxPublicInterests }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="opt in publicProfileOptions.interests"
                                :key="opt.value"
                                type="button"
                                :aria-pressed="publicForm.public_interests.includes(opt.value)"
                                :disabled="!publicForm.public_interests.includes(opt.value) && publicForm.public_interests.length >= maxPublicInterests"
                                class="rounded-full border px-3 py-1.5 text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="publicForm.public_interests.includes(opt.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                @click="toggleChip(publicForm.public_interests, opt.value, maxPublicInterests)"
                            >{{ opt.label }}</button>
                        </div>
                        <p v-if="publicForm.errors.public_interests" class="text-xs text-danger">{{ publicForm.errors.public_interests }}</p>
                    </div>

                    <!-- Cidade + detalhes -->
                    <div class="border-t border-frame pt-6 grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium text-cream">Cidade</label>
                            <CityAutocomplete
                                v-model="publicForm.profile_city"
                                :placeholder="'Digite sua cidade…'"
                                aria-label="Cidade"
                                @select="onCitySelect"
                            />
                            <p v-if="publicForm.errors.profile_city" class="text-xs text-danger">{{ publicForm.errors.profile_city }}</p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="marital" class="text-sm font-medium text-cream">Estado civil</label>
                            <select
                                id="marital"
                                v-model="publicForm.marital_status"
                                class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none"
                            >
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.marital" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.marital_status" class="text-xs text-danger">{{ publicForm.errors.marital_status }}</p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="height" class="text-sm font-medium text-cream">Altura</label>
                            <select
                                id="height"
                                v-model="publicForm.height_cm"
                                class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none"
                            >
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.heights" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.height_cm" class="text-xs text-danger">{{ publicForm.errors.height_cm }}</p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="weight" class="text-sm font-medium text-cream">Peso</label>
                            <select
                                id="weight"
                                v-model="publicForm.weight_kg"
                                class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none"
                            >
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.weights" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p class="text-xs text-muted">Mostrado como faixa (ex.: "65–69 kg"), nunca o valor exato.</p>
                            <p v-if="publicForm.errors.weight_kg" class="text-xs text-danger">{{ publicForm.errors.weight_kg }}</p>
                        </div>
                    </div>

                    <!-- Localizações extras (2ª e 3ª) — opt-in, para quem frequenta
                         mais de uma cidade. Só cidade (nunca bairro), como a 1ª. -->
                    <div class="border-t border-frame pt-6 grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium text-cream">2ª cidade <span class="text-muted">(opcional)</span></label>
                            <CityAutocomplete
                                v-model="publicForm.profile_city_2"
                                :placeholder="'Outra cidade que você frequenta…'"
                                aria-label="Segunda cidade"
                                @select="onCitySelect2"
                            />
                            <p v-if="publicForm.errors.profile_city_2" class="text-xs text-danger">{{ publicForm.errors.profile_city_2 }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium text-cream">3ª cidade <span class="text-muted">(opcional)</span></label>
                            <CityAutocomplete
                                v-model="publicForm.profile_city_3"
                                :placeholder="'Mais uma cidade…'"
                                aria-label="Terceira cidade"
                                @select="onCitySelect3"
                            />
                            <p v-if="publicForm.errors.profile_city_3" class="text-xs text-danger">{{ publicForm.errors.profile_city_3 }}</p>
                        </div>
                    </div>

                    <!-- Mais detalhes (selects controlados, opt-in): escolaridade,
                         área, filhos, bebe, fuma, disponibilidade. -->
                    <div class="border-t border-frame pt-6 grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="education" class="text-sm font-medium text-cream">Escolaridade</label>
                            <select id="education" v-model="publicForm.education" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.education" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.education" class="text-xs text-danger">{{ publicForm.errors.education }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="occupation_area" class="text-sm font-medium text-cream">Área de atuação</label>
                            <select id="occupation_area" v-model="publicForm.occupation_area" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.occupation_area" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.occupation_area" class="text-xs text-danger">{{ publicForm.errors.occupation_area }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="children" class="text-sm font-medium text-cream">Filhos</label>
                            <select id="children" v-model="publicForm.children" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.children" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.children" class="text-xs text-danger">{{ publicForm.errors.children }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="availability" class="text-sm font-medium text-cream">Disponibilidade</label>
                            <select id="availability" v-model="publicForm.availability" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.availability" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.availability" class="text-xs text-danger">{{ publicForm.errors.availability }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="drinks" class="text-sm font-medium text-cream">Bebe</label>
                            <select id="drinks" v-model="publicForm.drinks" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.drinks" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.drinks" class="text-xs text-danger">{{ publicForm.errors.drinks }}</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="smokes" class="text-sm font-medium text-cream">Fuma</label>
                            <select id="smokes" v-model="publicForm.smokes" class="min-h-[44px] rounded-lg border border-frame bg-surface-2 px-3 text-sm text-cream focus:border-gold focus:outline-none">
                                <option value="">Prefiro não dizer</option>
                                <option v-for="opt in publicProfileOptions.smokes" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="publicForm.errors.smokes" class="text-xs text-danger">{{ publicForm.errors.smokes }}</p>
                        </div>
                    </div>

                    <!-- Faixa etária: opt-in de EXIBIÇÃO (derivada da sua data de
                         nascimento; a data/idade exata nunca aparece). -->
                    <div class="border-t border-frame pt-6">
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-frame bg-surface-2 p-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-cream">Mostrar minha faixa etária</p>
                                <p class="text-xs text-muted">
                                    {{ public_profile.age_band
                                        ? `As performers veem "${public_profile.age_band}". Nunca sua idade exata ou data de nascimento.`
                                        : 'Sua faixa etária pode aparecer no seu perfil. Nunca a idade exata.' }}
                                </p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="publicForm.show_age_band"
                                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors"
                                :class="publicForm.show_age_band ? 'bg-gold' : 'bg-surface border border-frame'"
                                @click="publicForm.show_age_band = !publicForm.show_age_band"
                            >
                                <span
                                    class="inline-block h-5 w-5 transform rounded-full bg-cream transition-transform"
                                    :class="publicForm.show_age_band ? 'translate-x-6' : 'translate-x-1'"
                                ></span>
                            </button>
                        </div>
                    </div>

                    <!-- Preview: como a performer vê. Só o que está preenchido. -->
                    <div class="border-t border-frame pt-6 space-y-3">
                        <p class="text-xs uppercase tracking-wide text-muted">Como a performer vê</p>
                        <div class="rounded-lg border border-gold/30 bg-gold/5 p-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-serif text-lg text-cream">{{ nickname || 'Seu apelido' }}</span>
                                <span v-if="public_profile.is_verified" class="rounded-full bg-gold/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gold">Verificado</span>
                            </div>
                            <p v-if="publicForm.headline.trim()" class="font-serif text-sm italic text-cream/80">{{ publicForm.headline }}</p>
                            <p class="text-xs text-muted">
                                <template v-if="publicForm.show_age_band && public_profile.age_band">{{ public_profile.age_band }}</template>
                                <template v-if="publicForm.show_age_band && public_profile.age_band && previewCity"> · </template>
                                <template v-if="previewCity">{{ previewCity }}</template>
                                <template v-if="public_profile.member_since"> · Membro desde {{ public_profile.member_since }}</template>
                            </p>
                            <p v-if="publicForm.bio.trim()" class="text-sm text-cream/90 whitespace-pre-line">{{ publicForm.bio }}</p>
                            <div v-if="previewSeeking.length" class="flex flex-wrap gap-1.5">
                                <span v-for="t in previewSeeking" :key="t" class="rounded-full border border-gold/40 bg-gold/10 px-2 py-0.5 text-[11px] text-gold">{{ t }}</span>
                            </div>
                            <div v-if="previewInterests.length" class="flex flex-wrap gap-1.5">
                                <span v-for="t in previewInterests" :key="t" class="rounded-full border border-frame bg-surface px-2 py-0.5 text-[11px] text-muted">{{ t }}</span>
                            </div>
                            <p v-if="previewMarital || previewHeight" class="text-xs text-muted">
                                <template v-if="previewMarital">{{ previewMarital }}</template>
                                <template v-if="previewMarital && previewHeight"> · </template>
                                <template v-if="previewHeight">{{ previewHeight }}</template>
                            </p>
                        </div>
                    </div>

                    <Button type="submit" :disabled="publicForm.processing">
                        {{ publicForm.processing ? 'Salvando...' : 'Salvar perfil público' }}
                    </Button>
                </form>
            </section>

            <!-- Galeria de perfil (feat/member-gallery-and-profile). Até
                 `gallery_max` fotos que a performer vê no seu perfil — SÓ se você
                 ligar "Perfil visível" e SÓ depois que cada foto for aprovada. A
                 copy avisa que passa por análise ANTES do envio. -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-5">
                <div class="space-y-1">
                    <h2 class="font-serif text-xl text-cream">Suas fotos</h2>
                    <p class="text-xs text-muted">
                        Opcional. Até {{ gallery_max }} fotos. Cada foto passa por uma
                        <span class="text-cream">análise</span> antes de aparecer no seu perfil.
                        JPG, PNG ou WebP, até 5 MB. Envie apenas fotos suas.
                    </p>
                </div>

                <!-- Opt-in mestre: sem isto ligado, nada da galeria/perfil fica
                     visível para as performers. Default OFF. -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gold/30 bg-gold/5 p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-cream">Perfil visível para performers</p>
                        <p class="text-xs text-muted">
                            {{ galleryVisible
                                ? 'As performers podem abrir seu perfil e ver suas fotos aprovadas.'
                                : 'Seu perfil está oculto. Ligue para as performers verem suas fotos.' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="galleryVisible"
                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors"
                        :class="galleryVisible ? 'bg-gold' : 'bg-surface-2 border border-frame'"
                        @click="toggleGalleryVisibility"
                    >
                        <span
                            class="inline-block h-5 w-5 transform rounded-full bg-cream transition-transform"
                            :class="galleryVisible ? 'translate-x-6' : 'translate-x-1'"
                        ></span>
                    </button>
                </div>

                <!-- Grade de fotos. Rejeitada não tem imagem (bytes purgados): mostra
                     só o motivo, e o membro remove/reenvia. -->
                <div v-if="gallery.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div
                        v-for="photo in gallery"
                        :key="photo.id"
                        class="relative flex flex-col overflow-hidden rounded-lg border border-frame bg-surface-2"
                    >
                        <div class="relative aspect-[4/5] w-full bg-background">
                            <img
                                v-if="photo.url"
                                :src="photo.url"
                                alt="Sua foto"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="grid h-full w-full place-items-center px-2 text-center">
                                <span class="text-xs text-muted">{{ photo.reject_reason || 'Foto recusada' }}</span>
                            </div>

                            <span
                                v-if="photo.is_primary"
                                class="absolute left-1.5 top-1.5 rounded-full bg-gold px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-background"
                            >Principal</span>

                            <span
                                class="absolute right-1.5 top-1.5 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="{
                                    'bg-surface text-muted': photo.status === 'pending',
                                    'bg-gold/20 text-gold': photo.status === 'approved',
                                    'bg-danger/20 text-danger': photo.status === 'rejected',
                                }"
                            >{{ statusLabels[photo.status] }}</span>
                        </div>

                        <div class="flex items-center justify-between gap-1 p-2">
                            <button
                                v-if="photo.status === 'approved' && !photo.is_primary"
                                type="button"
                                class="min-h-[36px] text-xs text-gold hover:underline disabled:opacity-40"
                                :disabled="busyPhotoId === photo.id"
                                @click="makePrimary(photo)"
                            >Tornar principal</button>
                            <span v-else class="text-xs text-muted">
                                {{ photo.status === 'rejected' ? 'Recusada' : (photo.is_primary ? 'Foto principal' : '') }}
                            </span>
                            <button
                                type="button"
                                aria-label="Remover foto"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-muted hover:text-danger disabled:opacity-40"
                                :disabled="busyPhotoId === photo.id"
                                @click="removeGalleryPhoto(photo)"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <label v-if="!galleryFull" class="cursor-pointer inline-block">
                        <span class="inline-flex min-h-[44px] items-center rounded-lg border border-gold px-4 py-2 text-sm text-gold transition-colors hover:bg-gold/10">
                            {{ galleryForm.processing ? 'Enviando...' : 'Adicionar foto' }}
                        </span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            :disabled="galleryForm.processing"
                            @change="pickGalleryPhoto"
                        />
                    </label>
                    <p v-else class="text-xs text-muted">
                        Você atingiu o limite de {{ gallery_max }} fotos. Remova uma para enviar outra.
                    </p>
                    <p v-if="galleryForm.errors.file" class="mt-2 text-xs text-danger">{{ galleryForm.errors.file }}</p>
                    <p v-if="galleryForm.errors.cropped" class="mt-2 text-xs text-danger">{{ galleryForm.errors.cropped }}</p>
                </div>

                <!-- Enquadramento 3:4 antes de enviar. O membro vê o recorte que
                     vira o card; o servidor sanitiza o recorte E o original (a
                     variante completa do lightbox). -->
                <ImageCropper
                    :file="pendingGalleryFile"
                    :aspect-ratio="3 / 4"
                    :output-width="720"
                    title="Enquadre sua foto"
                    hint="Arraste e ajuste o zoom. Este recorte vira a foto do seu card; a foto inteira aparece ao ampliar."
                    @crop="onGalleryCropped"
                    @cancel="pendingGalleryFile = null"
                />
            </div>

            <!-- A copy de privacidade fica ANTES do formulário, não num rodapé:
                 o membro precisa saber quem vê o dado antes de escrever, não
                 depois de salvar. É a mesma disciplina da tela de edição da
                 performer, invertida — lá a copy avisa que o campo é público.

                 ATENÇÃO ao alcance desta caixa: ela vale para os campos do
                 formulário LOGO ABAIXO, e não para a tela inteira. A seção
                 Estilo de Vida, mais adiante, é exibida à performer e traz o
                 próprio aviso. Uma promessa genérica de "nada aqui é visto"
                 cobrindo os dois seria falsa sobre o único campo que ela lê. -->
            <div class="rounded-xl border border-gold/30 bg-gold/5 p-5 space-y-1">
                <p class="text-cream font-medium text-sm">Isto é só seu</p>
                <p class="text-muted text-sm">
                    Nenhuma performer vê seus interesses nem o que você escreve no formulário
                    abaixo. Usamos esses dados para te mostrar perfis mais próximos do que você
                    procura — e para nada além disso.
                </p>
            </div>

            <form class="rounded-xl border border-frame bg-surface p-6 space-y-6" @submit.prevent="save">
                <!-- Interesses -->
                <div class="flex flex-col gap-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-sm font-medium text-cream">Interesses</span>
                        <span class="text-xs text-muted tabular-nums shrink-0">
                            {{ interestCount }}/{{ MAX_INTERESTS }} selecionadas
                        </span>
                    </div>
                    <p class="text-xs text-muted">
                        Escolha até {{ MAX_INTERESTS }} que combinam com você.
                    </p>

                    <div v-for="group in TAG_GROUPS" :key="group.key" class="space-y-2">
                        <p class="text-xs text-muted uppercase tracking-wide">{{ group.label }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="tag in group.tags"
                                :key="tag.value"
                                type="button"
                                :disabled="!isSelected(tag.value) && limitReached"
                                :aria-pressed="isSelected(tag.value)"
                                class="rounded-full border px-3 py-1.5 text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="isSelected(tag.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                @click="toggleInterest(tag.value)"
                            >
                                {{ tag.label }}
                            </button>
                        </div>
                    </div>

                    <p v-if="limitReached" class="text-xs text-muted">
                        Limite atingido. Desmarque um interesse para escolher outro.
                    </p>
                    <p v-if="form.errors.interests" class="text-xs text-danger">{{ form.errors.interests }}</p>
                </div>

                <!-- O que estou buscando -->
                <div class="flex flex-col gap-1.5 border-t border-frame pt-6">
                    <label for="seeking" class="text-sm font-medium text-cream">O que estou buscando</label>
                    <textarea
                        id="seeking"
                        v-model="form.seeking"
                        rows="4"
                        maxlength="1000"
                        placeholder="Descreva o que você procura no Limen..."
                        class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                    />
                    <div class="flex items-baseline justify-end">
                        <span class="text-xs text-muted tabular-nums shrink-0">{{ form.seeking.length }}/1000</span>
                    </div>
                    <p v-if="form.errors.seeking" class="text-xs text-danger">{{ form.errors.seeking }}</p>
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Salvando...' : 'Salvar' }}
                </Button>
            </form>

            <!-- ── Estilo de Vida ────────────────────────────────────────────
                 Formulário PRÓPRIO, com botão próprio e rota própria. Não é
                 organização visual: este é o único campo da tela que aparece
                 para a performer, e juntá-lo ao formulário de cima faria o
                 membro publicá-lo no gesto em que ajusta um interesse.

                 O aviso de quem vê fica NO MOMENTO da escolha, não nos Termos —
                 mesma disciplina da Foto Efêmera. E é afirmativo ("a performer
                 vê"), nunca uma tranquilização: o campo é opcional justamente
                 porque a resposta certa depende de quanto o membro quer expor. -->
            <section class="rounded-xl border border-frame bg-surface p-6 space-y-6">
                <div class="flex items-start gap-3">
                    <!-- Ícone discreto: diamante de contorno, sem preenchimento.
                         Nada de cifrão ou pilha de moedas — o campo é sobre
                         estilo de vida declarado, e um ícone de dinheiro mudaria
                         a pergunta que o membro acha que está respondendo. -->
                    <svg
                        class="h-5 w-5 text-gold/70 shrink-0 mt-0.5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 3 21 12l-9 9-9-9 9-9Z" />
                    </svg>
                    <div class="space-y-1">
                        <h2 class="text-sm font-medium text-cream">Estilo de Vida</h2>
                        <p class="text-muted text-sm">
                            Opcional. Se você escolher uma faixa, ela aparece para as performers
                            ao lado do seu apelido — nas listas de seguidores, gorjetas e
                            visitantes. Não aparece no catálogo público e não é usada como filtro.
                        </p>
                    </div>
                </div>

                <form class="space-y-4" @submit.prevent="saveLifestyle">
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Faixa de estilo de vida</legend>

                        <!-- Radio, não chips: é escala ordenada e exclusiva, não
                             conjunto combinável. O <label> envolve a linha
                             inteira para a área de clique cobrir a descrição —
                             que é o texto que de fato distingue as faixas. -->
                        <label
                            v-for="option in lifestyleOptions"
                            :key="option.value"
                            class="flex items-start gap-3 rounded-lg border px-4 py-3 cursor-pointer transition-colors"
                            :class="lifestyleForm.lifestyle_tier === option.value
                                ? 'border-gold bg-gold/5'
                                : 'border-frame bg-surface-2 hover:border-gold/50'"
                        >
                            <input
                                v-model="lifestyleForm.lifestyle_tier"
                                type="radio"
                                name="lifestyle_tier"
                                :value="option.value"
                                class="mt-1 accent-gold shrink-0"
                            >
                            <span class="min-w-0">
                                <span class="block text-sm text-cream">{{ option.label }}</span>
                                <span class="block text-xs text-muted">{{ option.description }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <p v-if="lifestyleForm.errors.lifestyle_tier" class="text-xs text-danger">
                        {{ lifestyleForm.errors.lifestyle_tier }}
                    </p>

                    <Button type="submit" :disabled="lifestyleForm.processing">
                        {{ lifestyleForm.processing ? 'Salvando...' : 'Salvar estilo de vida' }}
                    </Button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
