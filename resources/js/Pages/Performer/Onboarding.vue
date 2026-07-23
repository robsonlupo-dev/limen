<script setup>
// Sprint 7 — a página de onboarding virou orquestradora de três estágios:
//
//   wizard (passos 4–5: bio → foto) → KycGate (preparação) → form de KYC
//
// Os passos 1–3 do wizard (e-mail/senha, nome artístico, mundo) acontecem no
// cadastro (Auth/Register.vue) — quando a performer chega aqui a conta já
// existe. Quem volta com bio e foto prontas cai direto no KycGate; quem já
// enviou KYC vê o status (em análise / aprovado / rejeitado), como antes.
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PerformerOnboardingWizard from '@/Components/Onboarding/PerformerOnboardingWizard.vue'
import KycGate from '@/Components/Onboarding/KycGate.vue'

const props = defineProps({
    profile: { type: Object, default: null },
    kycStatus: { type: String, default: 'not_submitted' },
    kycRejectionReason: { type: String, default: null },
})

// Estados do backend: not_submitted | pending | review | approved | rejected.
// Form aparece só antes do envio ou após rejeição; pending/review é "aguarde".
const showKycFlow = computed(() => ['not_submitted', 'rejected'].includes(props.kycStatus))
const kycInProgress = computed(() => ['pending', 'review'].includes(props.kycStatus))

// 'wizard' → 'gate' → 'form'. Rejeição pula direto para o form (os passos de
// perfil já foram feitos na primeira passagem; o que falta é reenviar docs).
const stage = ref(
    props.kycStatus === 'rejected'
        ? 'form'
        : props.profile?.bio && props.profile?.avatar_url
            ? 'gate'
            : 'wizard',
)

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
        <!-- Estágio 1: wizard (bio → foto) -->
        <PerformerOnboardingWizard
            v-if="showKycFlow && stage === 'wizard'"
            phase="profile"
            :profile="profile"
            @complete="stage = 'gate'"
        />

        <!-- Estágio 2: preparação para a verificação. 'start-kyc' abre o fluxo
             de verificação — hoje o formulário; quando o SDK Didit entrar,
             troca-se só este handler. -->
        <KycGate
            v-else-if="showKycFlow && stage === 'gate'"
            @start-kyc="stage = 'form'"
        />

        <!-- Estágio 3: envio de documentos (ou status pós-envio) -->
        <div v-else class="max-w-2xl mx-auto px-6 py-12">
            <h1 class="font-serif text-3xl text-cream mb-2">Verificação de identidade</h1>
            <p class="text-muted text-sm mb-8">
                Antes de publicar conteúdo, você precisa concluir a verificação (documento + selfie).
            </p>

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
                <Link :href="route('performer.dashboard')" class="inline-block text-sm text-gold hover:text-gold-light">
                    Ir para o painel &rarr;
                </Link>
            </div>

            <!-- Não enviada ou rejeitada: formulário -->
            <form v-else class="space-y-6 bg-surface border border-frame rounded-2xl p-8" @submit.prevent="submitKyc">
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

                <div class="flex justify-end">
                    <Button variant="primary" type="submit" :loading="kycForm.processing">
                        Enviar documentos para verificação
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
