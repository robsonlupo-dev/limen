<script setup>
// Sprint 7 — Tela de preparação exibida ANTES de abrir o fluxo de verificação
// (hoje o formulário de upload; amanhã o SDK Didit). O componente não sabe COMO
// a verificação acontece: emite 'start-kyc' e o pai decide — é o que permite
// trocar o form pelo SDK sem tocar aqui.
//
// Copy dos 3 blocos: o docs/SEEKING_UX_CASE_STUDY.md (seção 4.4.1) não existe
// no repo — copy redigido nesta sprint e sujeito a ajuste do PO no review.
//
// Paleta do Sprint 7: fundo #0c0a10, acento #f3c97e, texto #f2e8d6.
import { Link } from '@inertiajs/vue3'

defineEmits(['start-kyc'])

const benefits = [
    {
        icon: '✦',
        title: 'Seu Portal abre para o público',
        subtitle: 'Só perfis verificados aparecem no catálogo e recebem seguidores.',
    },
    {
        icon: '◈',
        title: 'Você passa a receber tokens',
        subtitle: 'Gorjetas, interesses e assinaturas só chegam a contas verificadas.',
    },
    {
        icon: '◉',
        title: 'Seus documentos ficam protegidos',
        subtitle: 'Criptografados em repouso, nunca exibidos no seu perfil público.',
    },
]
</script>

<template>
    <div class="min-h-screen bg-[#0c0a10] text-[#f2e8d6] flex flex-col items-center justify-center px-6 py-12">
        <div class="w-full max-w-md text-center">
            <h1 class="font-serif text-3xl mb-2">Falta um passo para abrir seu Portal</h1>
            <p class="text-sm text-[#8a8280] mb-10">
                A verificação leva poucos minutos e é feita uma única vez.
            </p>

            <div class="space-y-4 mb-10 text-left">
                <div
                    v-for="benefit in benefits"
                    :key="benefit.title"
                    class="flex items-start gap-4 rounded-xl border border-[#f2e8d6]/10 bg-[#f2e8d6]/[0.03] p-5"
                >
                    <span class="text-2xl text-[#f3c97e] leading-none mt-0.5" aria-hidden="true">
                        {{ benefit.icon }}
                    </span>
                    <div class="space-y-1">
                        <p class="text-sm font-medium text-[#f2e8d6]">{{ benefit.title }}</p>
                        <p class="text-xs text-[#8a8280]">{{ benefit.subtitle }}</p>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="w-full rounded-lg bg-[#f3c97e] px-8 py-3.5 text-sm font-medium text-[#0c0a10] hover:opacity-90 transition-opacity"
                @click="$emit('start-kyc')"
            >
                Verificar agora &rarr;
            </button>

            <Link
                :href="route('performer.dashboard')"
                class="inline-block mt-4 text-sm text-[#8a8280] hover:text-[#f2e8d6] transition-colors"
            >
                Verificar depois
            </Link>

            <p class="mt-6 text-[11px] leading-relaxed text-[#8a8280]">
                Ao continuar, você consente com o tratamento dos seus documentos de identidade
                exclusivamente para verificação de idade e identidade, conforme a LGPD.
                Os arquivos são criptografados e nunca aparecem no seu perfil.
            </p>
        </div>
    </div>
</template>
