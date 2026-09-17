<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import ImageCropper from '@/Components/ImageCropper.vue'
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
