<script setup>
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

const props = defineProps({
    profile: { type: Object, default: null },
    kycStatus: { type: String, default: 'not_submitted' },
    kycRejectionReason: { type: String, default: null },
})

const step = ref(1)

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const kycLabels = {
    not_submitted: { text: 'Não iniciada', class: 'text-muted' },
    pending: { text: 'Pendente', class: 'text-gold' },
    review: { text: 'Em análise', class: 'text-gold' },
    approved: { text: 'Aprovada', class: 'text-success' },
    rejected: { text: 'Rejeitada', class: 'text-danger' },
}

const avatarForm = useForm({ file: null })

function submitAvatar(e) {
    avatarForm.file = e.target.files[0]
    avatarForm.post(route('performer.onboarding.avatar'), { forceFormData: true })
}

const profileForm = useForm({
    bio: props.profile?.bio ?? '',
    category: props.profile?.category ?? 'mulheres',
    rate_public: props.profile?.rate_public ?? 60,
})

function saveProfile() {
    profileForm.post(route('performer.onboarding.profile'))
}

// ─── KYC (Step 3) ───────────────────────────────────────────────────────────
// Estados do backend: not_submitted | pending | review | approved | rejected.
// Form aparece só antes do envio ou após rejeição; pending/review é "aguarde".
const showKycForm = computed(() => ['not_submitted', 'rejected'].includes(props.kycStatus))
const kycInProgress = computed(() => ['pending', 'review'].includes(props.kycStatus))

const documentTypes = [
    { value: 'rg', label: 'RG' },
    { value: 'cnh', label: 'CNH' },
    { value: 'cpf', label: 'CPF' },
]

const kycForm = useForm({
    document_type: 'rg',
    cpf: '',
    full_legal_name: '',
    date_of_birth: '',
    document_front: null,
    document_back: null,
    selfie: null,
})

const kycFileFields = [
    { field: 'document_front', label: 'Frente do documento', required: true },
    { field: 'document_back', label: 'Verso do documento (opcional)', required: false },
    { field: 'selfie', label: 'Selfie segurando o documento', required: true },
]

const previews = ref({ document_front: null, document_back: null, selfie: null })

function pickKycFile(field, e) {
    const file = e.target.files[0] ?? null
    kycForm[field] = file
    if (previews.value[field]) URL.revokeObjectURL(previews.value[field])
    previews.value[field] = file ? URL.createObjectURL(file) : null
}

function submitKyc() {
    // No sucesso o back() do Inertia re-renderiza a página com props frescas do
    // servidor — kycStatus já volta 'pending', sem estado local para manter.
    kycForm.post(route('performer.onboarding.kyc'), {
        forceFormData: true,
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout title="Configurar perfil">
        <div class="max-w-2xl mx-auto px-6 py-12">
            <h1 class="font-serif text-3xl text-cream mb-2">Configure seu perfil</h1>
            <p class="text-muted text-sm mb-8">Complete os passos abaixo para começar no Limen.</p>

            <!-- Steps indicator -->
            <div class="flex items-center gap-2 mb-10">
                <button
                    v-for="s in [1, 2, 3]"
                    :key="s"
                    class="flex-1 h-1.5 rounded-full transition-colors"
                    :class="s <= step ? 'bg-gold' : 'bg-frame'"
                    @click="step = s"
                />
            </div>

            <!-- Step 1: Photo -->
            <div v-show="step === 1" class="bg-surface border border-frame rounded-2xl p-8 space-y-6">
                <div>
                    <h2 class="font-serif text-xl text-cream">1. Foto de perfil</h2>
                    <p class="text-muted text-sm mt-1">Escolha uma foto principal para o seu perfil.</p>
                </div>

                <div class="flex items-center gap-6">
                    <div class="h-24 w-24 rounded-full overflow-hidden bg-surface-2 border border-frame flex items-center justify-center">
                        <img v-if="profile?.avatar_url" :src="profile.avatar_url" alt="Avatar" class="h-full w-full object-cover" />
                        <span v-else class="text-3xl">🌟</span>
                    </div>
                    <label class="cursor-pointer">
                        <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                            Enviar foto
                        </span>
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="submitAvatar" />
                    </label>
                </div>
                <p v-if="avatarForm.errors.file" class="text-xs text-danger">{{ avatarForm.errors.file }}</p>

                <div class="flex justify-end">
                    <Button variant="primary" @click="step = 2">Continuar</Button>
                </div>
            </div>

            <!-- Step 2: Bio & World -->
            <div v-show="step === 2" class="bg-surface border border-frame rounded-2xl p-8 space-y-6">
                <div>
                    <h2 class="font-serif text-xl text-cream">2. Bio & Mundo</h2>
                    <p class="text-muted text-sm mt-1">Conte um pouco sobre você e defina seu mundo.</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="bio" class="text-sm font-medium text-cream">Bio</label>
                    <textarea
                        id="bio"
                        v-model="profileForm.bio"
                        rows="4"
                        maxlength="5000"
                        placeholder="Fale sobre você..."
                        class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                    />
                    <p v-if="profileForm.errors.bio" class="text-xs text-danger">{{ profileForm.errors.bio }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium text-cream">Mundo</label>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <button
                            v-for="world in worlds"
                            :key="world.value"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-sm transition-colors"
                            :class="profileForm.category === world.value
                                ? 'border-gold text-gold bg-gold/10'
                                : 'border-frame text-muted hover:border-gold/50'"
                            @click="profileForm.category = world.value"
                        >
                            {{ world.label }}
                        </button>
                    </div>
                    <p v-if="profileForm.errors.category" class="text-xs text-danger mt-1">{{ profileForm.errors.category }}</p>
                </div>

                <Input
                    id="rate_public"
                    v-model="profileForm.rate_public"
                    label="Gorjeta mínima (tokens)"
                    type="number"
                    :required="true"
                    :error="profileForm.errors.rate_public"
                />

                <div class="flex justify-between">
                    <Button variant="ghost" @click="step = 1">Voltar</Button>
                    <Button variant="primary" :loading="profileForm.processing" @click="saveProfile(); step = 3">
                        Salvar e continuar
                    </Button>
                </div>
            </div>

            <!-- Step 3: KYC -->
            <div v-show="step === 3" class="bg-surface border border-frame rounded-2xl p-8 space-y-6">
                <div>
                    <h2 class="font-serif text-xl text-cream">3. Verificação de identidade</h2>
                    <p class="text-muted text-sm mt-1">
                        Antes de publicar conteúdo, você precisa concluir a verificação (documento + selfie).
                    </p>
                </div>

                <div class="rounded-xl border border-frame bg-surface-2 p-4 flex items-center justify-between">
                    <span class="text-sm text-muted">Status da verificação</span>
                    <span class="text-sm font-medium" :class="kycLabels[kycStatus]?.class">
                        {{ kycLabels[kycStatus]?.text ?? kycStatus }}
                    </span>
                </div>

                <!-- Aprovada -->
                <div v-if="kycStatus === 'approved'" class="rounded-xl border border-success/30 bg-success/5 p-6 text-center space-y-3">
                    <div class="text-4xl">✅</div>
                    <p class="text-sm text-cream">Identidade verificada com sucesso.</p>
                    <Link :href="route('catalog')" class="inline-block">
                        <Button variant="primary">Explorar o portal</Button>
                    </Link>
                </div>

                <!-- Enviada, aguardando análise -->
                <div v-else-if="kycInProgress" class="rounded-xl border border-gold/30 bg-gold/5 p-6 text-center space-y-3">
                    <div class="text-4xl">🕐</div>
                    <p class="text-sm text-muted">
                        Documentos recebidos — sua verificação está em andamento.
                        Você receberá um e-mail quando concluída.
                    </p>
                </div>

                <!-- Não enviada ou rejeitada: formulário -->
                <form v-else class="space-y-6" @submit.prevent="submitKyc">
                    <div
                        v-if="kycStatus === 'rejected'"
                        class="rounded-xl border border-danger/40 bg-danger/10 p-4 text-sm"
                    >
                        <p class="font-medium text-danger">Sua verificação anterior foi rejeitada.</p>
                        <p v-if="kycRejectionReason" class="text-muted mt-1">Motivo: {{ kycRejectionReason }}</p>
                        <p class="text-muted mt-1">Corrija os documentos e envie novamente.</p>
                    </div>

                    <p v-if="kycForm.errors.kyc" class="rounded-xl border border-danger/40 bg-danger/10 p-4 text-sm text-danger">
                        {{ kycForm.errors.kyc }}
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <label for="document_type" class="text-sm font-medium text-cream">Tipo de documento</label>
                        <select
                            id="document_type"
                            v-model="kycForm.document_type"
                            class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream focus:border-gold focus:outline-none"
                        >
                            <option v-for="dt in documentTypes" :key="dt.value" :value="dt.value">{{ dt.label }}</option>
                        </select>
                        <p v-if="kycForm.errors.document_type" class="text-xs text-danger">{{ kycForm.errors.document_type }}</p>
                    </div>

                    <Input
                        id="full_legal_name"
                        v-model="kycForm.full_legal_name"
                        label="Nome completo (como no documento)"
                        :required="true"
                        :error="kycForm.errors.full_legal_name"
                    />

                    <Input
                        id="cpf"
                        v-model="kycForm.cpf"
                        label="CPF"
                        placeholder="000.000.000-00"
                        :required="true"
                        :error="kycForm.errors.cpf"
                    />

                    <Input
                        id="date_of_birth"
                        v-model="kycForm.date_of_birth"
                        label="Data de nascimento"
                        type="date"
                        :required="true"
                        :error="kycForm.errors.date_of_birth"
                    />

                    <div v-for="{ field, label, required } in kycFileFields" :key="field" class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-cream">{{ label }}</span>
                        <div class="flex items-center gap-4">
                            <div class="h-20 w-28 rounded-lg overflow-hidden bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                                <img v-if="previews[field]" :src="previews[field]" :alt="label" class="h-full w-full object-cover" />
                                <span v-else class="text-2xl">📄</span>
                            </div>
                            <label class="cursor-pointer">
                                <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                                    {{ kycForm[field] ? 'Trocar arquivo' : 'Escolher arquivo' }}
                                </span>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png"
                                    :required="required && !kycForm[field]"
                                    class="hidden"
                                    @change="pickKycFile(field, $event)"
                                />
                            </label>
                        </div>
                        <p v-if="kycForm.errors[field]" class="text-xs text-danger">{{ kycForm.errors[field] }}</p>
                    </div>

                    <div class="flex justify-between">
                        <Button variant="ghost" type="button" @click="step = 2">Voltar</Button>
                        <Button variant="primary" type="submit" :loading="kycForm.processing">
                            Enviar documentos para verificação
                        </Button>
                    </div>
                </form>

                <div v-if="!showKycForm" class="flex justify-between">
                    <Button variant="ghost" @click="step = 2">Voltar</Button>
                    <Link v-if="kycStatus !== 'approved'" :href="route('catalog')">
                        <Button variant="primary">Explorar o portal</Button>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
