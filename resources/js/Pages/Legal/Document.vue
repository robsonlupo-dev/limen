<script setup>
import { Head, Link } from '@inertiajs/vue3'

// Página única para os dois textos jurídicos — o que muda é a prop `document`.
// O corpo é placeholder até o escritório entregar o texto; a versão exibida vem
// do servidor (config/documents.php), a mesma que o aceite grava.
defineProps({
    document: { type: Object, required: true },
})
</script>

<template>
    <Head :title="document.title" />

    <div class="min-h-screen bg-background px-6 py-16">
        <div class="mx-auto max-w-3xl">
            <Link href="/" class="text-muted text-sm no-underline hover:text-cream">&larr; Limen</Link>

            <h1 class="font-serif text-3xl text-cream mt-6">{{ document.title }}</h1>
            <p class="text-muted text-sm mt-2">Versão {{ document.version }}</p>

            <div class="mt-10 rounded-2xl border border-frame bg-surface p-8">
                <p class="text-cream/80 leading-relaxed">
                    [CONTEÚDO JURÍDICO — aguardando Opice Blum]
                </p>
            </div>

            <p class="text-muted text-xs mt-6">
                Este texto ainda não é a versão final. O aceite registrado hoje aponta para a
                versão {{ document.version }}; quando o texto definitivo for publicado, a versão
                muda e o aceite é solicitado novamente.
            </p>
        </div>
    </div>
</template>
