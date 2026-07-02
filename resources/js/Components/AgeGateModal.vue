<script setup>
import { ref } from 'vue'
import PortalLogo from './PortalLogo.vue'

// NOTE: this age gate is a UI/UX control only. Real age enforcement is
// server-side (18+ birthdate on registration; the catalog is auth-gated).
//
// TODO Fase 13: após declaração, iniciar liveness check via Unico/idwall
// para verificação de maioridade real (como o Chaturbate faz com ID scan).
// Apenas para cadastro de Performer (KYC obrigatório) — Membros: declaração + CPF.

const props = defineProps({
    // Server-known acceptance (limen_age cookie), shared via Inertia props.
    ageAccepted: { type: Boolean, default: false },
})

const accepted = ref(props.ageAccepted)

function accept() {
    const expires = new Date()
    expires.setFullYear(expires.getFullYear() + 1)
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = `limen_age=1; path=/; expires=${expires.toUTCString()}; SameSite=Lax${secure}`
    accepted.value = true
}

function decline() {
    window.location.href = 'https://www.google.com.br'
}
</script>

<template>
    <Teleport to="body">
        <div v-if="!accepted" class="age-gate-overlay">
            <!-- Blurred backdrop: the page behind stays visible but obscured -->
            <div class="age-gate-backdrop" />

            <div class="age-gate-modal">
                <div class="flex flex-col items-center text-center gap-6">
                    <PortalLogo :size="52" />

                    <div>
                        <h2 class="font-serif text-2xl text-cream mb-2">Acesso restrito</h2>
                        <p class="text-muted text-sm leading-relaxed">
                            Este portal contém conteúdo exclusivo para adultos.<br />
                            Você confirma que tem <strong class="text-cream">18 anos ou mais</strong>?
                        </p>
                    </div>

                    <div class="flex flex-col gap-3 w-full">
                        <button class="btn-confirm" @click="accept">
                            Sim, tenho 18 anos ou mais
                        </button>
                        <button class="btn-deny" @click="decline">
                            Não, sair
                        </button>
                    </div>

                    <p class="text-xs text-muted">
                        Ao entrar, você confirma ser maior de idade e aceita os
                        <a href="/termos" class="text-gold underline">termos de uso</a>.
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.age-gate-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.age-gate-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.age-gate-modal {
    position: relative;
    z-index: 1;
    background: #111111;
    border: 1px solid #2a2a2a;
    border-radius: 16px;
    padding: 48px 40px;
    max-width: 480px;
    width: 90%;
}
.btn-confirm {
    width: 100%;
    background: #c9a84c;
    color: #0a0a0a;
    border: none;
    border-radius: 8px;
    padding: 16px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease;
}
.btn-confirm:hover {
    background: #e0bd6a;
}
.btn-deny {
    width: 100%;
    background: transparent;
    color: #c9a84c;
    border: 1px solid #c9a84c;
    border-radius: 8px;
    padding: 14px;
    font-size: 15px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.btn-deny:hover {
    background: rgba(201, 168, 76, 0.1);
}
</style>
