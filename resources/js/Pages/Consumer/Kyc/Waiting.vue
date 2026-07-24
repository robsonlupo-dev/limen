<script setup>
import { Head, Link, router } from '@inertiajs/vue3'

defineProps({
    kycStatus: { type: String, default: 'pending' },
    kycRejectionReason: { type: String, default: null },
})

function logout() {
    router.post(route('logout'))
}
</script>

<template>
    <Head title="Verificação em andamento" />

    <div class="page">
        <!-- Rejeitada não pertence a esta tela: o reenvio vive em consumer.kyc.index. -->
        <div v-if="kycStatus === 'rejected'" class="card">
            <div class="icon">⚠️</div>
            <h1 class="title">Verificação rejeitada</h1>
            <p class="subtitle">
                <span v-if="kycRejectionReason">{{ kycRejectionReason }}</span>
                Você pode enviar uma nova selfie.
            </p>
            <Link :href="route('consumer.kyc.index')" class="cta">Enviar nova selfie</Link>
        </div>

        <div v-else class="card">
            <div class="icon clock">🕐</div>
            <h1 class="title">Verificação em andamento</h1>
            <p class="subtitle">
                Você receberá um e-mail em até 48h quando sua verificação for concluída.
            </p>
            <button type="button" class="logout" @click="logout">Sair</button>
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
    max-width: 420px;
    text-align: center;
}
.icon { font-size: 56px; line-height: 1; margin-bottom: 20px; }
.icon.clock {
    color: #f3c97e;
    /* Emoji ganha o dourado de acento via drop-shadow suave. */
    filter: drop-shadow(0 0 10px rgba(243, 201, 126, 0.35));
}
.title {
    font-size: 24px;
    font-weight: 600;
    margin: 0 0 12px;
    color: #f2e8d6;
}
.subtitle {
    font-size: 14px;
    line-height: 1.6;
    color: #b8ad9c;
    margin: 0 0 28px;
}
.cta {
    display: inline-block;
    border: 1px solid #f3c97e;
    color: #f3c97e;
    border-radius: 10px;
    padding: 10px 20px;
    font-size: 14px;
    text-decoration: none;
    transition: background 0.15s ease;
}
.cta:hover { background: rgba(243, 201, 126, 0.1); }
.logout {
    font: inherit;
    font-size: 14px;
    background: none;
    border: none;
    color: #8a8280;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 4px;
    transition: color 0.15s ease;
}
.logout:hover { color: #f2e8d6; }
</style>
