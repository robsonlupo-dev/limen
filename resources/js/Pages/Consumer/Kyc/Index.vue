<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'

const props = defineProps({
    kycStatus: { type: String, default: 'not_submitted' },
    kycRejectionReason: { type: String, default: null },
})

const form = useForm({ selfie: null })
const preview = ref(null)

function pickSelfie(e) {
    const file = e.target.files[0] ?? null
    form.selfie = file
    if (preview.value) URL.revokeObjectURL(preview.value)
    preview.value = file ? URL.createObjectURL(file) : null
}

onBeforeUnmount(() => {
    if (preview.value) URL.revokeObjectURL(preview.value)
})

function submit() {
    // No sucesso o servidor redireciona para a sala de espera (consumer.kyc.waiting);
    // o Inertia segue o redirect, sem estado local para manter.
    form.post(route('consumer.kyc.submit'), {
        forceFormData: true,
        preserveScroll: true,
    })
}
</script>

<template>
    <Head title="Verificação de identidade" />

    <div class="page">
        <div class="card">
            <div class="logo">
                <PortalLogo :size="48" :show-text="false" />
            </div>

            <h1 class="title">Verificação de identidade</h1>
            <p class="subtitle">
                Para proteger nossa comunidade, precisamos confirmar que você é uma
                pessoa real. Envie uma selfie para começar.
            </p>

            <!-- Rejeitada: banner de reenvio antes do formulário. -->
            <div v-if="kycStatus === 'rejected'" class="reject-banner">
                <p class="reject-title">Sua verificação anterior foi rejeitada.</p>
                <p v-if="kycRejectionReason" class="reject-reason">Motivo: {{ kycRejectionReason }}</p>
                <p class="reject-hint">Envie uma nova selfie para tentar de novo.</p>
            </div>

            <p v-if="form.errors.selfie" class="field-error">{{ form.errors.selfie }}</p>

            <form class="form" @submit.prevent="submit">
                <div class="uploader">
                    <div class="preview">
                        <img v-if="preview" :src="preview" alt="Prévia da selfie" />
                        <span v-else class="preview-icon">🙂</span>
                    </div>
                    <label class="file-btn">
                        {{ form.selfie ? 'Trocar selfie' : 'Escolher selfie' }}
                        <input
                            type="file"
                            accept="image/jpeg,image/png"
                            class="hidden-input"
                            @change="pickSelfie"
                        />
                    </label>
                </div>

                <button
                    type="submit"
                    class="submit-btn"
                    :disabled="!form.selfie || form.processing"
                >
                    {{ form.processing ? 'Enviando…' : 'Enviar selfie' }}
                </button>

                <p class="lgpd">
                    Sua selfie é usada apenas para verificação de identidade e não será
                    compartilhada publicamente. Processada com segurança pelo nosso parceiro Didit.
                </p>
            </form>
        </div>
    </div>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background: #0c0a10;
    color: #f2e8d6;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}
.card {
    width: 100%;
    max-width: 440px;
    text-align: center;
}
.logo {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
}
.title {
    font-size: 24px;
    font-weight: 600;
    margin: 0 0 10px;
    color: #f2e8d6;
}
.subtitle {
    font-size: 14px;
    line-height: 1.55;
    color: #b8ad9c;
    margin: 0 0 28px;
}
.reject-banner {
    text-align: left;
    border: 1px solid #b3402f;
    background: rgba(179, 64, 47, 0.12);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
}
.reject-title { color: #e5705e; font-weight: 600; font-size: 14px; margin: 0; }
.reject-reason { color: #d8ccbb; font-size: 13px; margin: 6px 0 0; }
.reject-hint { color: #8a8280; font-size: 13px; margin: 6px 0 0; }
.field-error {
    color: #e5705e;
    font-size: 13px;
    margin: 0 0 16px;
}
.form {
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.uploader {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}
.preview {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    overflow: hidden;
    background: #16121c;
    border: 1px solid #2a2431;
    display: flex;
    align-items: center;
    justify-content: center;
}
.preview img { width: 100%; height: 100%; object-fit: cover; }
.preview-icon { font-size: 44px; opacity: 0.5; }
.file-btn {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    border: 1px solid #f3c97e;
    color: #f3c97e;
    border-radius: 10px;
    padding: 9px 18px;
    font-size: 14px;
    transition: background 0.15s ease;
}
.file-btn:hover { background: rgba(243, 201, 126, 0.1); }
.hidden-input { display: none; }
.submit-btn {
    font: inherit;
    font-size: 15px;
    font-weight: 600;
    padding: 12px 16px;
    border-radius: 10px;
    border: none;
    background: #f3c97e;
    color: #0c0a10;
    cursor: pointer;
    transition: opacity 0.15s ease;
}
.submit-btn:hover:not(:disabled) { opacity: 0.9; }
.submit-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.lgpd {
    font-size: 11px;
    line-height: 1.5;
    color: #8a8280;
    margin: 0;
}
</style>
