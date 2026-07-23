<script setup>
// Sprint 7 — Wizard de onboarding da performer. 5 passos, uma pergunta por
// tela: acesso (e-mail/senha) → nome artístico → mundo → bio → foto.
//
// Duas fases porque o passo 3 cruza a fronteira de auth:
//  - phase="register" (guest, página Auth/Register): passos 1–3 acumulam o
//    payload e um ÚNICO POST em register.store cria a conta (o backend exige
//    stage_name + category no cadastro — não há como salvar por passo antes
//    de existir conta). O servidor redireciona para performer.onboarding.
//  - phase="profile" (autenticada, página Performer/Onboarding): passos 4–5
//    postam nas rotas de onboarding existentes (perfil / foto).
//
// Nenhuma rota nova: register.store, performer.onboarding.profile e
// performer.onboarding.avatar já existiam.
//
// Paleta do Sprint 7 (spec do PO): fundo #0c0a10, acento #f3c97e,
// texto #f2e8d6 — deliberadamente distinta dos tokens do design system.
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'

const props = defineProps({
    // 'register' → passos 1–3 (guest) · 'profile' → passos 4–5 (autenticada)
    phase: { type: String, default: 'register' },
    // Prefill da fase profile (bio/avatar já salvos, ex.: volta à página).
    profile: { type: Object, default: null },
})

const emit = defineEmits(['complete'])

const TOTAL_STEPS = 5
const step = ref(props.phase === 'profile' ? 4 : 1)

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const stepTitles = {
    1: 'Crie seu acesso',
    2: 'Como devemos te chamar?',
    3: 'Qual mundo você representa?',
    4: 'Conte sua história',
    5: 'Mostre seu melhor ângulo',
}

// ─── Fase register: um form só, POST único no fim do passo 3 ────────────────
const registerForm = useForm({
    tipo: 'performer',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    birthdate: '',
    stage_name: '',
    category: '',
    accept_terms: false,
    lgpd_consent: false,
})

// ─── Fase profile: cada passo posta na rota de onboarding existente ─────────
const bioForm = useForm({ bio: props.profile?.bio ?? '' })
const avatarForm = useForm({ file: null })
const avatarPreview = ref(props.profile?.avatar_url ?? null)

// ─── Validação inline ───────────────────────────────────────────────────────
// "Continuar" só habilita com o passo válido. A mensagem inline aparece
// depois que o campo foi tocado (blur) — digitar não pode começar em erro.
const touched = ref({})

function touch(field) {
    touched.value[field] = true
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function adult(birthdate) {
    if (!birthdate) return false
    const cutoff = new Date()
    cutoff.setFullYear(cutoff.getFullYear() - 18)
    return new Date(birthdate) <= cutoff
}

// Espelho das regras do RegisterWebRequest — o servidor continua sendo a
// autoridade; isto só evita um roundtrip para erro óbvio.
const fieldError = computed(() => ({
    name: registerForm.name.trim() ? '' : 'Informe seu nome completo.',
    email: EMAIL_RE.test(registerForm.email) ? '' : 'Informe um e-mail válido.',
    password:
        registerForm.password.length >= 8 && /[A-Z]/.test(registerForm.password) && /[0-9]/.test(registerForm.password)
            ? ''
            : 'Mínimo 8 caracteres, com ao menos uma maiúscula e um número.',
    password_confirmation:
        registerForm.password_confirmation === registerForm.password && registerForm.password
            ? ''
            : 'As senhas não conferem.',
    birthdate: adult(registerForm.birthdate) ? '' : 'Você precisa ter pelo menos 18 anos.',
    stage_name:
        registerForm.stage_name.trim().length >= 2 && registerForm.stage_name.length <= 255
            ? ''
            : 'Escolha um nome artístico com pelo menos 2 caracteres.',
    bio: bioForm.bio.trim().length >= 10 ? '' : 'Escreva pelo menos 10 caracteres.',
}))

function inlineError(field) {
    return touched.value[field] ? fieldError.value[field] : ''
}

const stepValid = computed(() => {
    switch (step.value) {
        case 1:
            return ['name', 'email', 'password', 'password_confirmation', 'birthdate']
                .every((f) => !fieldError.value[f])
        case 2:
            return !fieldError.value.stage_name
        case 3:
            return Boolean(registerForm.category) && registerForm.accept_terms && registerForm.lgpd_consent
        case 4:
            return !fieldError.value.bio
        case 5:
            return Boolean(avatarForm.file) || Boolean(avatarPreview.value)
        default:
            return false
    }
})

// ─── Navegação ──────────────────────────────────────────────────────────────
const firstStepOfPhase = computed(() => (props.phase === 'profile' ? 4 : 1))

function back() {
    if (step.value > firstStepOfPhase.value) step.value -= 1
}

function advance() {
    if (!stepValid.value) return

    if (step.value === 3) {
        // Fim da fase register: POST único cria a conta; o servidor redireciona
        // para performer.onboarding, onde a fase profile assume no passo 4.
        registerForm.post(route('register.store'), {
            onFinish: () => registerForm.reset('password', 'password_confirmation'),
        })
        return
    }

    if (step.value === 4) {
        bioForm.post(route('performer.onboarding.profile'), {
            preserveScroll: true,
            onSuccess: () => (step.value = 5),
        })
        return
    }

    if (step.value === 5) {
        if (avatarForm.file) {
            avatarForm.post(route('performer.onboarding.avatar'), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => emit('complete'),
            })
        } else {
            // Avatar já salvo numa visita anterior — nada a reenviar.
            emit('complete')
        }
        return
    }

    step.value += 1
}

function pickAvatar(e) {
    const file = e.target.files[0] ?? null
    avatarForm.file = file
    if (avatarPreview.value?.startsWith('blob:')) URL.revokeObjectURL(avatarPreview.value)
    avatarPreview.value = file ? URL.createObjectURL(file) : null
}

const processing = computed(
    () => registerForm.processing || bioForm.processing || avatarForm.processing,
)

const continueLabel = computed(() => {
    if (step.value === 3) return 'Criar meu Portal'
    if (step.value === 5) return 'Concluir'
    return 'Continuar'
})
</script>

<template>
    <div class="min-h-screen bg-[#0c0a10] text-[#f2e8d6] flex flex-col items-center px-6 py-12">
        <div class="w-full max-w-md">
            <!-- Barra de progresso: 5 segmentos + ícone do Portal no final -->
            <div class="flex items-center gap-2 mb-12" aria-label="Progresso do cadastro">
                <div
                    v-for="s in TOTAL_STEPS"
                    :key="s"
                    class="flex-1 h-1 rounded-full transition-colors duration-300"
                    :class="s <= step ? 'bg-[#f3c97e]' : 'bg-[#f2e8d6]/10'"
                />
                <div
                    class="shrink-0 transition-opacity duration-300"
                    :class="step >= TOTAL_STEPS ? 'opacity-100' : 'opacity-30'"
                >
                    <PortalLogo :size="24" :show-text="false" />
                </div>
            </div>

            <h1 class="font-serif text-3xl mb-2">{{ stepTitles[step] }}</h1>
            <p class="text-sm text-[#8a8280] mb-8">Passo {{ step }} de {{ TOTAL_STEPS }}</p>

            <!-- Passo 1 — e-mail / senha -->
            <div v-if="step === 1" class="space-y-5">
                <div>
                    <label for="wiz-name" class="block text-sm mb-1.5">Nome completo</label>
                    <input
                        id="wiz-name"
                        v-model="registerForm.name"
                        type="text"
                        autocomplete="name"
                        placeholder="Seu nome"
                        class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                        @blur="touch('name')"
                    />
                    <p v-if="inlineError('name') || registerForm.errors.name" class="mt-1 text-xs text-red-400">
                        {{ registerForm.errors.name || inlineError('name') }}
                    </p>
                </div>

                <div>
                    <label for="wiz-email" class="block text-sm mb-1.5">E-mail</label>
                    <input
                        id="wiz-email"
                        v-model="registerForm.email"
                        type="email"
                        autocomplete="email"
                        placeholder="voce@email.com"
                        class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                        @blur="touch('email')"
                    />
                    <p v-if="inlineError('email') || registerForm.errors.email" class="mt-1 text-xs text-red-400">
                        {{ registerForm.errors.email || inlineError('email') }}
                    </p>
                </div>

                <div>
                    <label for="wiz-password" class="block text-sm mb-1.5">Senha</label>
                    <input
                        id="wiz-password"
                        v-model="registerForm.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Mínimo 8 caracteres"
                        class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                        @blur="touch('password')"
                    />
                    <p v-if="inlineError('password') || registerForm.errors.password" class="mt-1 text-xs text-red-400">
                        {{ registerForm.errors.password || inlineError('password') }}
                    </p>
                </div>

                <div>
                    <label for="wiz-password-confirm" class="block text-sm mb-1.5">Confirmar senha</label>
                    <input
                        id="wiz-password-confirm"
                        v-model="registerForm.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Repita a senha"
                        class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                        @blur="touch('password_confirmation')"
                    />
                    <p v-if="inlineError('password_confirmation')" class="mt-1 text-xs text-red-400">
                        {{ inlineError('password_confirmation') }}
                    </p>
                </div>

                <div>
                    <label for="wiz-birthdate" class="block text-sm mb-1.5">Data de nascimento</label>
                    <input
                        id="wiz-birthdate"
                        v-model="registerForm.birthdate"
                        type="date"
                        class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] focus:border-[#f3c97e] focus:outline-none [color-scheme:dark]"
                        @blur="touch('birthdate')"
                    />
                    <p v-if="inlineError('birthdate') || registerForm.errors.birthdate" class="mt-1 text-xs text-red-400">
                        {{ registerForm.errors.birthdate || inlineError('birthdate') }}
                    </p>
                </div>
            </div>

            <!-- Passo 2 — nome artístico -->
            <div v-else-if="step === 2" class="space-y-3">
                <label for="wiz-stage-name" class="block text-sm mb-1.5">Nome artístico</label>
                <input
                    id="wiz-stage-name"
                    v-model="registerForm.stage_name"
                    type="text"
                    placeholder="Como você quer ser conhecida(o)"
                    class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-lg text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                    @blur="touch('stage_name')"
                />
                <p v-if="inlineError('stage_name') || registerForm.errors.stage_name" class="text-xs text-red-400">
                    {{ registerForm.errors.stage_name || inlineError('stage_name') }}
                </p>
                <p class="text-xs text-[#8a8280]">
                    É o nome que aparece no seu Portal. Seu nome real nunca é exibido.
                </p>
            </div>

            <!-- Passo 3 — categoria / mundo -->
            <div v-else-if="step === 3" class="space-y-6">
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="world in worlds"
                        :key="world.value"
                        type="button"
                        class="rounded-xl border px-4 py-5 text-sm transition-colors"
                        :class="registerForm.category === world.value
                            ? 'border-[#f3c97e] text-[#f3c97e] bg-[#f3c97e]/10'
                            : 'border-[#f2e8d6]/15 text-[#8a8280] hover:border-[#f3c97e]/50'"
                        @click="registerForm.category = world.value"
                    >
                        {{ world.label }}
                    </button>
                </div>
                <p v-if="registerForm.errors.category" class="text-xs text-red-400">
                    {{ registerForm.errors.category }}
                </p>

                <div class="space-y-3 pt-2">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            v-model="registerForm.accept_terms"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded accent-[#f3c97e]"
                        />
                        <span class="text-sm text-[#8a8280]">
                            Li e aceito os <a href="#" class="text-[#f3c97e] underline">termos de uso</a>
                            e os termos específicos de performer
                        </span>
                    </label>
                    <p v-if="registerForm.errors.accept_terms" class="text-xs text-red-400 ml-7">
                        {{ registerForm.errors.accept_terms }}
                    </p>

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            v-model="registerForm.lgpd_consent"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded accent-[#f3c97e]"
                        />
                        <span class="text-sm text-[#8a8280]">
                            Consinto com o <a href="#" class="text-[#f3c97e] underline">tratamento de dados (LGPD)</a>
                        </span>
                    </label>
                    <p v-if="registerForm.errors.lgpd_consent" class="text-xs text-red-400 ml-7">
                        {{ registerForm.errors.lgpd_consent }}
                    </p>
                </div>
            </div>

            <!-- Passo 4 — bio -->
            <div v-else-if="step === 4" class="space-y-3">
                <label for="wiz-bio" class="block text-sm mb-1.5">Sua bio</label>
                <textarea
                    id="wiz-bio"
                    v-model="bioForm.bio"
                    rows="6"
                    maxlength="5000"
                    placeholder="O que te faz única? É o primeiro texto que um visitante lê."
                    class="w-full rounded-lg border border-[#f2e8d6]/15 bg-[#f2e8d6]/5 px-4 py-3 text-sm text-[#f2e8d6] placeholder:text-[#8a8280] focus:border-[#f3c97e] focus:outline-none"
                    @blur="touch('bio')"
                />
                <p v-if="inlineError('bio') || bioForm.errors.bio" class="text-xs text-red-400">
                    {{ bioForm.errors.bio || inlineError('bio') }}
                </p>
            </div>

            <!-- Passo 5 — foto de avatar -->
            <div v-else-if="step === 5" class="space-y-5">
                <div class="flex flex-col items-center gap-5">
                    <div class="h-32 w-32 rounded-full overflow-hidden border-2 border-[#f3c97e]/40 bg-[#f2e8d6]/5 flex items-center justify-center">
                        <img
                            v-if="avatarPreview"
                            :src="avatarPreview"
                            alt="Prévia do avatar"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="text-4xl" aria-hidden="true">🌟</span>
                    </div>
                    <label class="cursor-pointer">
                        <span class="inline-flex items-center rounded-lg border border-[#f3c97e] text-[#f3c97e] px-5 py-2.5 text-sm hover:bg-[#f3c97e]/10 transition-colors">
                            {{ avatarPreview ? 'Trocar foto' : 'Escolher foto' }}
                        </span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            @change="pickAvatar"
                        />
                    </label>
                </div>
                <p v-if="avatarForm.errors.file" class="text-xs text-red-400 text-center">
                    {{ avatarForm.errors.file }}
                </p>
                <p class="text-xs text-[#8a8280] text-center">
                    Essa é a foto do seu card no catálogo. Boa luz vale mais que filtro.
                </p>
            </div>

            <!-- Navegação -->
            <div class="flex items-center justify-between mt-10">
                <button
                    v-if="step > firstStepOfPhase"
                    type="button"
                    class="text-sm text-[#8a8280] hover:text-[#f2e8d6] transition-colors"
                    @click="back"
                >
                    Voltar
                </button>
                <span v-else />

                <button
                    type="button"
                    :disabled="!stepValid || processing"
                    class="rounded-lg bg-[#f3c97e] px-8 py-3 text-sm font-medium text-[#0c0a10] transition-opacity disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90"
                    @click="advance"
                >
                    {{ processing ? 'Enviando…' : continueLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
