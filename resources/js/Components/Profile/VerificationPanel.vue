<script setup>
import { computed } from 'vue'
import Modal from '@/Components/Modal.vue'

// Painel do selo "Verificada" (feat/performer-profile-redesign, item 8). Explica,
// em linguagem simples, O QUE a plataforma realmente checou — usando só o que o
// sistema JÁ executa. NADA aqui é inventado:
//  - KYC pelo Didit: documento oficial conferido + selfie comparada ao documento
//    (biometria) + maioridade confirmada pela data de nascimento (KycService:
//    'age_confirmed' + 'documento + selfie pelo Didit'). Vale sempre que o selo
//    aparece (is_verified = KYC aprovado).
//  - Voz moderada por PESSOA: só quando há intro aprovada (feat/voice-intro exige
//    moderação humana antes de servir). Some quando não há voz.
// Conteúdo NÃO entra: a checagem de conteúdo é anti-CSAM automática (hash), não
// revisão humana — afirmá-la seria inventar critério.
const props = defineProps({
    show: { type: Boolean, default: false },
    performerName: { type: String, default: 'esta performer' },
    hasVoice: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const items = computed(() => {
    const list = [
        {
            title: 'Documento de identidade conferido',
            body: `${props.performerName} enviou um documento oficial, que foi conferido antes de o perfil entrar no ar.`,
        },
        {
            title: 'Identidade confirmada por biometria',
            body: 'Uma selfie foi comparada ao documento para confirmar que é a mesma pessoa — não é só uma foto enviada.',
        },
        {
            title: 'Maioridade confirmada',
            body: 'A data de nascimento no documento confirma que é maior de 18 anos.',
        },
    ]
    if (props.hasVoice) {
        list.push({
            title: 'Apresentação de voz revisada por uma pessoa',
            body: 'O áudio do perfil foi ouvido e aprovado pela nossa equipe antes de ficar disponível.',
        })
    }
    return list
})
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="space-y-5">
            <header class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-limen-gold/15 text-limen-gold">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 1.5l2.2 1.27 2.55-.2 1.1 2.3 2.3 1.1-.2 2.55L19.5 10l-1.27 2.2.2 2.55-2.3 1.1-1.1 2.3-2.55-.2L10 19.5l-2.2-1.27-2.55.2-1.1-2.3-2.3-1.1.2-2.55L.5 10l1.27-2.2-.2-2.55 2.3-1.1 1.1-2.3 2.55.2L10 1.5z" />
                        <path fill-rule="evenodd" fill="#181410" d="M13.6 7.3a.75.75 0 010 1.06l-3.8 3.8a.75.75 0 01-1.06 0L6.9 10.3a.75.75 0 111.06-1.06l1.3 1.3 3.27-3.27a.75.75 0 011.07 0z" />
                    </svg>
                </span>
                <div>
                    <h2 class="font-serif text-2xl text-limen-ink">Perfil verificado</h2>
                    <p class="text-sm text-limen-ink-mute">O que a Limen confirmou antes deste perfil ir ao ar</p>
                </div>
            </header>

            <ul class="space-y-4">
                <li v-for="item in items" :key="item.title" class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-limen-gold" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m5 10.5 3.5 3.5 6.5-8" />
                    </svg>
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-limen-ink">{{ item.title }}</p>
                        <p class="text-sm leading-relaxed text-limen-ink-soft">{{ item.body }}</p>
                    </div>
                </li>
            </ul>

            <p class="border-t border-limen-line pt-4 text-xs leading-relaxed text-limen-ink-mute">
                A verificação confirma quem é a pessoa por trás do perfil. Ela não
                garante nada sobre o que acontece nas conversas — use o botão de
                denúncia se algo fugir das regras.
            </p>
        </div>
    </Modal>
</template>
