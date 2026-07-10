<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

// When the visitor arrived via an invite link (/convite/{code}), the server
// passes the referrer's first name so we can show a "convidado por X" banner.
// The referral itself is attributed server-side via the session, not this prop.
defineProps({
    referral: { type: Object, default: null },
})

// ── Content ──────────────────────────────────────────────────────────────────

const memberSteps = [
    { n: '01', title: 'Crie sua conta', text: 'Cadastro rápido e discreto. Confirmação de idade 18+ em segundos.' },
    { n: '02', title: 'Carregue tokens via PIX', text: 'Compre tokens com PIX instantâneo. Sem cartão, sem assinatura obrigatória.' },
    { n: '03', title: 'Conecte e apoie', text: 'Explore performers verificados, envie gorjetas e entre em sessões privadas.' },
]

const performerSteps = [
    { n: '01', title: 'Candidate-se', text: 'Cadastro de performer com nome artístico e o mundo que você representa.' },
    { n: '02', title: 'Verifique sua identidade', text: 'Verificação de identidade e idade (KYC). Segurança para você e para o público.' },
    { n: '03', title: 'Publique e receba', text: 'Você controla seu conteúdo. Receba gorjetas com split automático e saque via PIX.' },
]

const differentials = [
    {
        icon: '✦',
        title: 'Verificado dos dois lados',
        text: 'Todos os performers passam por verificação de identidade e idade. Sem perfis falsos, sem menores.',
    },
    {
        icon: '◈',
        title: 'Seguro e discreto',
        text: 'Seus dados são criptografados e nunca compartilhados. Pagamentos por PIX, cobrança discreta.',
    },
    {
        icon: '◉',
        title: 'Sem app store',
        text: 'Funciona direto no navegador, instalável como app (PWA). Sem intermediários, sem censura de loja.',
    },
]

const worlds = [
    { value: 'mulheres', label: 'Mulheres', glyph: '♀', accent: 'from-rose-500/20' },
    { value: 'homens', label: 'Homens', glyph: '♂', accent: 'from-sky-500/20' },
    { value: 'casais', label: 'Casais', glyph: '⚭', accent: 'from-violet-500/20' },
    { value: 'trans', label: 'Trans', glyph: '⚧', accent: 'from-amber-500/20' },
]

// ── Waitlist form ────────────────────────────────────────────────────────────

const submitted = ref(false)

const form = useForm({
    name: '',
    email: '',
    role: 'member',
    world: null,
    age_confirmed: false,
    website: '', // honeypot — must stay empty
})

function scrollToForm(role) {
    if (role) form.role = role
    document.getElementById('lista-de-espera')?.scrollIntoView({ behavior: 'smooth' })
}

function pickWorld(value) {
    form.world = form.world === value ? null : value
    scrollToForm()
}

function submit() {
    form.post(route('waitlist.store'), {
        preserveScroll: true,
        onSuccess: () => {
            submitted.value = true
            form.reset('name', 'email')
        },
    })
}

// Reveal-on-scroll: adds `.is-visible` when an element enters the viewport.
const vReveal = {
    mounted(el) {
        el.classList.add('reveal')
        const io = new IntersectionObserver(
            ([entry], obs) => {
                if (entry.isIntersecting) {
                    el.classList.add('is-visible')
                    obs.unobserve(el)
                }
            },
            { threshold: 0.15 },
        )
        io.observe(el)
    },
}
</script>

<template>
    <GuestLayout title="Limen — portal verificado de conteúdo adulto">
        <!-- ── Hero ─────────────────────────────────────────────────────── -->
        <section class="relative flex min-h-[88vh] flex-col items-center justify-center overflow-hidden px-6 text-center">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_60%_at_50%_35%,rgba(201,162,75,0.10),transparent)]" />

            <div class="relative z-10 mx-auto flex max-w-2xl flex-col items-center gap-8 animate-fade-in">
                <div
                    v-if="referral"
                    class="rounded-full border border-gold/40 bg-gold/[0.06] px-5 py-2 text-sm text-cream"
                >
                    ✦ Você foi convidado por <span class="text-gold">{{ referral.name }}</span>
                </div>

                <PortalLogo :size="104" />

                <div class="space-y-5">
                    <h1 class="font-serif text-5xl leading-tight text-cream md:text-7xl">
                        O portal do desejo,<br />
                        <em class="not-italic text-gold">verificado e real.</em>
                    </h1>
                    <p class="mx-auto max-w-lg text-lg leading-relaxed text-muted">
                        A plataforma premium de conteúdo adulto do Brasil. Performers verificados,
                        pagamentos por PIX e privacidade total — dos dois lados.
                    </p>
                </div>

                <div class="flex w-full flex-col gap-4 sm:w-auto sm:flex-row">
                    <Button variant="primary" size="lg" class="w-full sm:w-auto" @click="scrollToForm('performer')">
                        Quero ser Performer
                    </Button>
                    <Button variant="ghost" size="lg" class="w-full sm:w-auto" @click="scrollToForm('member')">
                        Quero entrar
                    </Button>
                </div>

                <p class="text-xs uppercase tracking-widest text-muted/70">
                    Lançamento em breve · Entre na lista de espera
                </p>
            </div>

            <div class="pointer-events-none absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-background to-transparent" />
        </section>

        <!-- ── Como funciona ────────────────────────────────────────────── -->
        <section class="px-6 py-24">
            <div class="mx-auto max-w-5xl">
                <h2 v-reveal class="mb-4 text-center font-serif text-4xl text-cream">Como funciona</h2>
                <p v-reveal class="mx-auto mb-16 max-w-xl text-center text-muted">
                    Simples para quem assiste. Justo para quem cria.
                </p>

                <div class="grid grid-cols-1 gap-12 md:grid-cols-2">
                    <!-- Membro -->
                    <div v-reveal class="space-y-6">
                        <h3 class="flex items-center gap-3 font-serif text-2xl text-cream">
                            <span class="text-2xl">👤</span> Para membros
                        </h3>
                        <ol class="space-y-5">
                            <li v-for="step in memberSteps" :key="step.n" class="flex gap-4">
                                <span class="font-serif text-xl text-gold/60">{{ step.n }}</span>
                                <div>
                                    <p class="font-medium text-cream">{{ step.title }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ step.text }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- Performer -->
                    <div v-reveal class="space-y-6 rounded-2xl border border-gold/20 bg-gold/[0.03] p-8">
                        <h3 class="flex items-center gap-3 font-serif text-2xl text-cream">
                            <span class="text-2xl">🌟</span> Para performers
                        </h3>
                        <ol class="space-y-5">
                            <li v-for="step in performerSteps" :key="step.n" class="flex gap-4">
                                <span class="font-serif text-xl text-gold/60">{{ step.n }}</span>
                                <div>
                                    <p class="font-medium text-cream">{{ step.title }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ step.text }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Por que Limen ────────────────────────────────────────────── -->
        <section class="border-y border-frame/40 bg-surface/40 px-6 py-24">
            <div class="mx-auto max-w-5xl">
                <h2 v-reveal class="mb-16 text-center font-serif text-4xl text-cream">
                    Por que <span class="text-gold">Limen</span>
                </h2>
                <div class="grid grid-cols-1 gap-8 md:grid-cols-3">
                    <div
                        v-for="item in differentials"
                        :key="item.title"
                        v-reveal
                        class="space-y-3 rounded-xl border border-frame bg-surface p-7"
                    >
                        <div class="text-3xl text-gold">{{ item.icon }}</div>
                        <h3 class="font-serif text-xl text-cream">{{ item.title }}</h3>
                        <p class="text-sm leading-relaxed text-muted">{{ item.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Mundos ───────────────────────────────────────────────────── -->
        <section class="px-6 py-24">
            <div class="mx-auto max-w-5xl">
                <h2 v-reveal class="mb-4 text-center font-serif text-4xl text-cream">Explore os mundos</h2>
                <p v-reveal class="mx-auto mb-16 max-w-xl text-center text-muted">
                    Escolha o seu. Diga o que te interessa e avisamos você primeiro.
                </p>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <button
                        v-for="world in worlds"
                        :key="world.value"
                        v-reveal
                        type="button"
                        class="group relative flex aspect-[3/4] flex-col items-center justify-end overflow-hidden rounded-2xl border p-5 text-center transition-all"
                        :class="form.world === world.value
                            ? 'border-gold ring-1 ring-gold'
                            : 'border-frame hover:border-gold/60'"
                        @click="pickWorld(world.value)"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-t to-transparent opacity-70 transition-opacity group-hover:opacity-100"
                            :class="world.accent"
                        />
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent" />
                        <span class="relative z-10 mb-2 text-4xl text-cream/90">{{ world.glyph }}</span>
                        <span class="relative z-10 font-serif text-xl text-cream">{{ world.label }}</span>
                        <span
                            class="relative z-10 mt-1 text-xs uppercase tracking-widest transition-colors"
                            :class="form.world === world.value ? 'text-gold' : 'text-muted group-hover:text-gold'"
                        >
                            {{ form.world === world.value ? 'Selecionado' : 'Tenho interesse' }}
                        </span>
                    </button>
                </div>
            </div>
        </section>

        <!-- ── CTA final / lista de espera ──────────────────────────────── -->
        <section id="lista-de-espera" class="scroll-mt-24 px-6 py-24">
            <div class="mx-auto max-w-lg">
                <div v-reveal class="rounded-3xl border border-frame bg-surface p-8 md:p-10">
                    <template v-if="submitted">
                        <div class="py-8 text-center">
                            <div class="mb-4 text-4xl text-gold">✓</div>
                            <h2 class="font-serif text-3xl text-cream">Você está na lista</h2>
                            <p class="mt-3 text-muted">
                                Avisaremos você assim que o Limen abrir. Enquanto isso, mantenha o segredo. 🤫
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <div class="mb-8 text-center">
                            <h2 class="font-serif text-3xl text-cream md:text-4xl">Entre na lista de espera</h2>
                            <p class="mt-3 text-muted">
                                Seja um dos primeiros. Sem spam — só o convite quando abrirmos.
                            </p>
                        </div>

                        <form class="space-y-5" novalidate @submit.prevent="submit">
                            <!-- Papel -->
                            <div>
                                <label class="text-sm font-medium text-cream">Eu quero entrar como</label>
                                <div class="mt-2 grid grid-cols-2 gap-3">
                                    <button
                                        type="button"
                                        class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                        :class="form.role === 'member'
                                            ? 'border-gold bg-gold/10 text-gold'
                                            : 'border-frame text-muted hover:border-gold/50'"
                                        @click="form.role = 'member'"
                                    >
                                        👤 Membro
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                        :class="form.role === 'performer'
                                            ? 'border-gold bg-gold/10 text-gold'
                                            : 'border-frame text-muted hover:border-gold/50'"
                                        @click="form.role = 'performer'"
                                    >
                                        🌟 Performer
                                    </button>
                                </div>
                                <p v-if="form.errors.role" class="mt-1 text-xs text-danger">{{ form.errors.role }}</p>
                            </div>

                            <!-- Nome -->
                            <div>
                                <label for="wl-name" class="text-sm font-medium text-cream">Nome</label>
                                <input
                                    id="wl-name"
                                    v-model="form.name"
                                    type="text"
                                    autocomplete="name"
                                    placeholder="Como podemos te chamar"
                                    class="mt-2 w-full rounded-lg border border-frame bg-background px-4 py-3 text-cream placeholder:text-muted/50 focus:border-gold focus:outline-none"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</p>
                            </div>

                            <!-- Email -->
                            <div>
                                <label for="wl-email" class="text-sm font-medium text-cream">E-mail</label>
                                <input
                                    id="wl-email"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    placeholder="voce@email.com"
                                    class="mt-2 w-full rounded-lg border border-frame bg-background px-4 py-3 text-cream placeholder:text-muted/50 focus:border-gold focus:outline-none"
                                />
                                <p v-if="form.errors.email" class="mt-1 text-xs text-danger">{{ form.errors.email }}</p>
                            </div>

                            <p v-if="form.world" class="text-xs text-muted">
                                Mundo de interesse:
                                <span class="text-gold capitalize">{{ form.world }}</span>
                                · <button type="button" class="underline hover:text-cream" @click="form.world = null">remover</button>
                            </p>

                            <!-- Honeypot: hidden from humans, catches bots. -->
                            <div class="sr-only" aria-hidden="true">
                                <label>Não preencha este campo
                                    <input v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                                </label>
                            </div>

                            <!-- 18+ consent (captured server-side). -->
                            <div>
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input
                                        v-model="form.age_confirmed"
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 rounded border-frame bg-background accent-gold"
                                    />
                                    <span class="text-sm text-muted">
                                        Confirmo que tenho <span class="text-cream">18 anos ou mais</span> e concordo
                                        em receber o convite de lançamento por e-mail.
                                    </span>
                                </label>
                                <p v-if="form.errors.age_confirmed" class="mt-1 text-xs text-danger">{{ form.errors.age_confirmed }}</p>
                            </div>

                            <Button type="submit" variant="primary" size="lg" class="w-full" :loading="form.processing">
                                Entrar na lista de espera
                            </Button>
                        </form>
                    </template>
                </div>
            </div>
        </section>
    </GuestLayout>
</template>

<style scoped>
@keyframes fade-in {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
    animation: fade-in 0.9s ease-out both;
}

/* Reveal-on-scroll (see v-reveal directive). */
.reveal {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity 0.6s ease-out, transform 0.6s ease-out;
}
.reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

@media (prefers-reduced-motion: reduce) {
    .animate-fade-in,
    .reveal {
        animation: none;
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
