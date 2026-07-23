<script setup>
// Sprint 7 — Banner fixo no topo do dashboard enquanto o KYC não está
// aprovado. SEM botão de fechar, de propósito: ele só some quando o status
// vira aprovado — é o lembrete permanente de que o perfil ainda não existe
// para o público.
//
// O dashboard fala o vocabulário do DashboardController ('active' quando a
// verificação foi aprovada); aceitamos 'approved' também para não acoplar o
// componente a uma tela específica.
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    kycStatus: { type: String, required: true },
})

const visible = computed(() => !['approved', 'active'].includes(props.kycStatus))
</script>

<template>
    <div
        v-if="visible"
        class="sticky top-0 z-40 border-b border-[#f3c97e]/30 bg-[#0c0a10] px-6 py-3"
        role="status"
    >
        <div class="max-w-6xl mx-auto flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm">
            <span class="text-[#f2e8d6]">Seu perfil não aparece no catálogo ainda.</span>
            <Link
                :href="route('performer.onboarding')"
                class="text-[#f3c97e] font-medium hover:opacity-80 transition-opacity"
            >
                Verificar agora &rarr;
            </Link>
        </div>
    </div>
</template>
