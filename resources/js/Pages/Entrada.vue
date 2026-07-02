<script setup>
import { Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
</script>

<template>
    <GuestLayout title="Entrada">
        <div class="min-h-[80vh] flex flex-col items-center justify-center px-6 py-16">
            <div class="flex flex-col items-center mb-12 text-center">
                <PortalLogo :size="64" />
                <h1 class="font-serif text-3xl md:text-4xl text-cream mt-6">
                    Para quem você é o portal?
                </h1>
                <p class="text-muted text-sm mt-3">Escolha como deseja entrar no Limen.</p>
            </div>

            <div class="grid w-full max-w-3xl grid-cols-1 gap-6 md:grid-cols-2">
                <!-- Membro -->
                <Link
                    href="/cadastro?tipo=membro"
                    class="group flex flex-col items-center rounded-2xl border border-frame bg-surface p-10 text-center no-underline transition-colors hover:border-gold"
                >
                    <span class="text-4xl">👤</span>
                    <h2 class="font-serif text-2xl text-cream mt-5">Sou Membro</h2>
                    <p class="text-muted text-sm mt-2">Quero explorar e conectar</p>
                    <span class="mt-6 text-gold group-hover:text-gold-light transition-colors">
                        → Criar minha conta
                    </span>
                </Link>

                <!-- Performer -->
                <Link
                    href="/cadastro?tipo=performer"
                    class="group flex flex-col items-center rounded-2xl border border-frame bg-surface p-10 text-center no-underline transition-colors hover:border-gold"
                >
                    <span class="text-4xl">🌟</span>
                    <h2 class="font-serif text-2xl text-cream mt-5">Sou Performer</h2>
                    <p class="text-muted text-sm mt-2">Quero publicar e ganhar</p>
                    <span class="mt-6 text-gold group-hover:text-gold-light transition-colors">
                        → Quero ser Performer
                    </span>
                    <p class="text-xs text-muted/70 mt-4 leading-relaxed">
                        Você passará por verificação de identidade (KYC)<br class="hidden md:block" />
                        antes de publicar conteúdo.
                    </p>
                </Link>
            </div>

            <p class="mt-12 text-center text-sm text-muted">
                Já tenho conta?
                <Link :href="route('login')" class="text-gold hover:text-gold-light">Entrar</Link>
            </p>
        </div>
    </GuestLayout>
</template>
