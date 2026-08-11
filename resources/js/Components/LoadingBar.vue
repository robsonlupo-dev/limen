<script setup>
/**
 * Barra de carregamento dourada — troca o spinner genérico de SEÇÃO por uma
 * linha fina limen-gold que preenche em vaivém (feat/micro-interactions).
 *
 * Puramente visual (classe `.mi-loading-bar` em micro-interactions.css, que já
 * carrega o guard de prefers-reduced-motion). Para carregamento de NAVEGAÇÃO a
 * barra é a do Inertia (app.js `progress`, também dourada) — esta é para estados
 * locais (ex.: imagem de um viewer carregando).
 *
 * `width` controla a largura (default estreita e centralizável pelo pai).
 */
defineProps({
    width: { type: String, default: '6rem' },
})
</script>

<template>
    <div class="mi-loading-bar" role="status" aria-label="Carregando" :style="{ width }" />
</template>
