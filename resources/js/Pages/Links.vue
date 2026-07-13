<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'

// Link-in-bio hub. Internal links use Inertia <Link>; social links are plain
// external anchors (new tab, rel noopener). Order is intentional: waitlist first.
const links = [
    { label: 'Entre na lista de espera', href: '/', internal: true },
    { label: 'Instagram', href: 'https://instagram.com/limen.oficial', internal: false },
    { label: 'TikTok', href: 'https://tiktok.com/@limen.oficial', internal: false },
    { label: 'Telegram', href: 'https://t.me/Limenbr', internal: false },
    { label: 'YouTube', href: 'https://youtube.com/@Limen.oficial', internal: false },
    { label: 'X / Twitter', href: 'https://x.com/Limenoficial', internal: false },
]

const btnClass =
    'block w-full rounded-xl border border-gold/70 px-6 py-4 text-center text-base font-medium tracking-wide text-gold no-underline transition-all duration-200 hover:bg-gold hover:text-background hover:border-gold focus-visible:outline-2 focus-visible:outline-gold focus-visible:outline-offset-2'
</script>

<template>
    <Head title="Links oficiais" />

    <main class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-background px-6 py-16">
        <!-- Ambient gold glow (matches the landing hero) -->
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_50%_at_50%_28%,rgba(201,162,75,0.10),transparent)]" />

        <div class="relative z-10 flex w-full max-w-sm flex-col items-center gap-8">
            <!-- Logo + wordmark -->
            <div class="flex flex-col items-center gap-4">
                <PortalLogo :size="96" :show-text="false" />
                <h1 class="font-serif text-4xl uppercase tracking-[0.35em] text-gold">
                    Limen
                </h1>
                <p class="max-w-xs text-center text-sm leading-relaxed text-muted">
                    O portal do desejo, verificado e real. 🔞
                </p>
            </div>

            <!-- Links -->
            <nav class="flex w-full flex-col gap-4">
                <template v-for="link in links" :key="link.label">
                    <Link v-if="link.internal" :href="link.href" :class="btnClass">
                        {{ link.label }}
                    </Link>
                    <a
                        v-else
                        :href="link.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="btnClass"
                    >
                        {{ link.label }}
                    </a>
                </template>
            </nav>

            <!-- Footer -->
            <footer class="pt-4 text-center text-xs tracking-widest text-muted/70">
                Limen · Brasil · +18
            </footer>
        </div>
    </main>
</template>
