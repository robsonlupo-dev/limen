<script setup>
import { Head, Link } from '@inertiajs/vue3'

// Página única para todos os textos jurídicos públicos — o que muda é a prop
// `document`. O corpo (`document.html`) vem RENDERIZADO do servidor a partir de
// resources/legal/*.md (conteúdo nosso, versionado no Git, nunca input de
// usuário), por isso v-html é seguro aqui. A versão exibida é a mesma que o
// aceite grava (config/documents.php).
defineProps({
    document: { type: Object, required: true },
    // Enquanto true (LEGAL_DRAFT), o texto é minuta pré-lançamento: a faixa avisa
    // que ainda não é a versão definitiva revisada por advogado.
    draft: { type: Boolean, default: true },
    related: { type: Array, default: () => [] },
})

// Chave do documento → nome da rota pública (todas no allowlist do Ziggy).
const ROUTES = {
    terms: 'legal.terms',
    privacy: 'legal.privacy',
    content_policy: 'legal.content-policy',
    performance_contract: 'legal.performance-contract',
    age_verification: 'legal.age-verification',
}
</script>

<template>
    <Head :title="document.title" />

    <div class="min-h-screen bg-background px-5 py-12 sm:px-6 sm:py-16">
        <div class="mx-auto max-w-3xl">
            <Link href="/" class="text-muted text-sm no-underline hover:text-cream">&larr; Limen</Link>

            <h1 class="font-serif text-3xl text-cream mt-6">{{ document.title }}</h1>
            <p class="text-muted text-sm mt-2">Versão {{ document.version }}</p>

            <div
                v-if="draft"
                role="note"
                class="mt-6 rounded-xl border border-gold/40 bg-gold/10 px-4 py-3 text-sm text-gold-light"
            >
                <strong class="font-semibold">Versão pré-lançamento.</strong>
                Este texto está em fase de revisão jurídica e pode ser ajustado antes do lançamento
                oficial da plataforma. Quando houver alteração relevante, você será avisado.
            </div>

            <article class="legal-prose mt-8 rounded-2xl border border-frame bg-surface p-6 sm:p-8" v-html="document.html" />

            <nav class="mt-10" aria-label="Outros documentos">
                <p class="text-muted text-xs uppercase tracking-wide">Outros documentos</p>
                <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <li v-for="item in related" :key="item.key">
                        <span v-if="item.key === document.key" class="text-gold" aria-current="page">{{ item.title }}</span>
                        <Link
                            v-else
                            :href="route(ROUTES[item.key])"
                            class="text-muted no-underline hover:text-cream"
                        >{{ item.title }}</Link>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<style scoped>
/* Tipografia do corpo jurídico (sem depender do plugin typography). Tokens do
   tema via variáveis CSS; nada de cor solta. */
.legal-prose {
    color: color-mix(in srgb, var(--color-cream) 82%, transparent);
    line-height: 1.7;
    font-size: 0.95rem;
    overflow-wrap: anywhere;
}
.legal-prose :deep(h2) {
    font-family: var(--font-serif, serif);
    color: var(--color-cream);
    font-size: 1.2rem;
    margin: 2rem 0 0.75rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--color-frame);
}
.legal-prose :deep(h2:first-child) {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}
.legal-prose :deep(h3) {
    color: var(--color-gold-light);
    font-size: 1rem;
    margin: 1.4rem 0 0.5rem;
}
.legal-prose :deep(p) {
    margin: 0.75rem 0;
}
.legal-prose :deep(ul),
.legal-prose :deep(ol) {
    margin: 0.75rem 0;
    padding-left: 1.4rem;
}
.legal-prose :deep(ul) {
    list-style: disc;
}
.legal-prose :deep(ol) {
    list-style: decimal;
}
.legal-prose :deep(li) {
    margin: 0.3rem 0;
}
.legal-prose :deep(strong) {
    color: var(--color-cream);
}
.legal-prose :deep(a) {
    color: var(--color-gold);
    text-decoration: underline;
}
.legal-prose :deep(table) {
    display: block;
    overflow-x: auto;
    width: 100%;
    margin: 1rem 0;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.legal-prose :deep(th),
.legal-prose :deep(td) {
    border: 1px solid var(--color-frame);
    padding: 0.5rem 0.7rem;
    text-align: left;
    vertical-align: top;
}
.legal-prose :deep(th) {
    background: var(--color-surface-2);
    color: var(--color-cream);
}
</style>
