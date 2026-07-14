<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

// When the visitor arrived via an invite link (/convite/{code}), the server
// passes the referrer's first name (for the "convidado por X" banner) and a
// suggestedRole nudging the form toward the referrer's own side of the platform.
// The referral itself is attributed server-side via the session, not this prop.
const props = defineProps({
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

// ── Waitlist form (2-step wizard) ────────────────────────────────────────────
// Step 1 is common (role + email + 18+); step 2 branches by role. A single POST
// at the end — no partial DB writes — and the server re-validates per role, so
// the wizard is a UX convenience, never the source of truth.

const submitted = ref(false)
const step = ref(1)

const form = useForm({
    name: '',
    email: '',
    role: props.referral?.suggestedRole ?? 'member',
    world: null,            // performer: the single world they represent
    world_preferences: [],  // member: the (multiple) worlds they want to hear from
    performer_kind: null,   // performer + casais: 'solo' | 'casal'
    age_confirmed: false,
    website: '', // honeypot — must stay empty
})

function selectRole(role) {
    form.role = role
    // Drop the other role's fields so nothing crosses over on submit.
    if (role === 'performer') {
        form.world_preferences = []
    } else {
        form.world = null
        form.performer_kind = null
    }
}

function scrollToForm(role) {
    if (role) selectRole(role)
    document.getElementById('lista-de-espera')?.scrollIntoView({ behavior: 'smooth' })
}

// Member: toggle a world in/out of the private preferences (multi-select).
function toggleWorldPreference(value) {
    const next = new Set(form.world_preferences)
    next.has(value) ? next.delete(value) : next.add(value)
    form.world_preferences = [...next]
}

// Performer: pick the single world represented; solo/casal only applies to casais.
function pickPerformerWorld(value) {
    form.world = value
    if (value !== 'casais') form.performer_kind = null
}

// Enter/submit routes by step: advance from step 1, POST from step 2.
function onSubmit() {
    if (step.value === 1) {
        step.value = 2
        return
    }
    // Send only the fields relevant to the chosen role (the server enforces this
    // too, but a lean payload keeps the prohibited-field rules unambiguous).
    form
        .transform((data) => {
            const base = {
                name: data.name, email: data.email, role: data.role,
                age_confirmed: data.age_confirmed, website: data.website,
            }
            return data.role === 'performer'
                ? { ...base, world: data.world, performer_kind: data.performer_kind }
                : { ...base, world_preferences: data.world_preferences }
        })
        .post(route('waitlist.store'), {
            preserveScroll: true,
            onSuccess: () => {
                submitted.value = true
                step.value = 1
                form.reset()
            },
            onError: (errors) => {
                // A step-1 field failed → bring the user back to fix it.
                if (errors.email || errors.role || errors.age_confirmed) {
                    step.value = 1
                }
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
                        class="group relative flex aspect-[3/4] flex-col items-center justify-end overflow-hidden rounded-2xl border border-frame p-5 text-center transition-all hover:border-gold/60"
                        @click="scrollToForm()"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-t to-transparent opacity-70 transition-opacity group-hover:opacity-100"
                            :class="world.accent"
                        />
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent" />
                        <span class="relative z-10 mb-2 text-4xl text-cream/90">{{ world.glyph }}</span>
                        <span class="relative z-10 font-serif text-xl text-cream">{{ world.label }}</span>
                        <span class="relative z-10 mt-1 text-xs uppercase tracking-widest text-muted transition-colors group-hover:text-gold">
                            Tenho interesse
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

                        <p class="mb-5 text-center text-xs uppercase tracking-widest text-muted">
                            Passo {{ step }} de 2
                        </p>

                        <form class="space-y-5" novalidate @submit.prevent="onSubmit">
                            <!-- ── Passo 1: papel + e-mail + 18+ (comum) ── -->
                            <template v-if="step === 1">
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
                                            @click="selectRole('member')"
                                        >
                                            👤 Membro
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.role === 'performer'
                                                ? 'border-gold bg-gold/10 text-gold'
                                                : 'border-frame text-muted hover:border-gold/50'"
                                            @click="selectRole('performer')"
                                        >
                                            🌟 Performer
                                        </button>
                                    </div>
                                    <p v-if="form.errors.role" class="mt-1 text-xs text-danger">{{ form.errors.role }}</p>
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

                                <Button type="submit" variant="primary" size="lg" class="w-full">
                                    Continuar
                                </Button>
                            </template>

                            <!-- ── Passo 2: campos por papel ── -->
                            <template v-else>
                                <!-- Nome (artístico para performer) -->
                                <div>
                                    <label for="wl-name" class="text-sm font-medium text-cream">
                                        {{ form.role === 'performer' ? 'Nome artístico' : 'Nome' }}
                                    </label>
                                    <input
                                        id="wl-name"
                                        v-model="form.name"
                                        type="text"
                                        :autocomplete="form.role === 'performer' ? 'off' : 'name'"
                                        :placeholder="form.role === 'performer' ? 'Seu nome artístico' : 'Como podemos te chamar'"
                                        class="mt-2 w-full rounded-lg border border-frame bg-background px-4 py-3 text-cream placeholder:text-muted/50 focus:border-gold focus:outline-none"
                                    />
                                    <p v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</p>
                                </div>

                                <!-- Membro: preferências de mundo (múltiplas, opcionais) -->
                                <div v-if="form.role === 'member'">
                                    <label class="text-sm font-medium text-cream">
                                        Quais mundos te interessam? <span class="text-muted">(opcional)</span>
                                    </label>
                                    <div class="mt-2 grid grid-cols-2 gap-3">
                                        <button
                                            v-for="world in worlds"
                                            :key="world.value"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.world_preferences.includes(world.value)
                                                ? 'border-gold bg-gold/10 text-gold'
                                                : 'border-frame text-muted hover:border-gold/50'"
                                            @click="toggleWorldPreference(world.value)"
                                        >
                                            {{ world.glyph }} {{ world.label }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Performer: mundo representado (único, obrigatório) -->
                                <div v-else>
                                    <label class="text-sm font-medium text-cream">Qual mundo você representa?</label>
                                    <div class="mt-2 grid grid-cols-2 gap-3">
                                        <button
                                            v-for="world in worlds"
                                            :key="world.value"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.world === world.value
                                                ? 'border-gold bg-gold/10 text-gold'
                                                : 'border-frame text-muted hover:border-gold/50'"
                                            @click="pickPerformerWorld(world.value)"
                                        >
                                            {{ world.glyph }} {{ world.label }}
                                        </button>
                                    </div>
                                    <p v-if="form.errors.world" class="mt-1 text-xs text-danger">{{ form.errors.world }}</p>

                                    <!-- Mundo Casais: solo/casal (obrigatório) -->
                                    <div v-if="form.world === 'casais'" class="mt-4">
                                        <label class="text-sm font-medium text-cream">No mundo Casais, você se cadastra como</label>
                                        <div class="mt-2 grid grid-cols-2 gap-3">
                                            <button
                                                type="button"
                                                class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                                :class="form.performer_kind === 'solo'
                                                    ? 'border-gold bg-gold/10 text-gold'
                                                    : 'border-frame text-muted hover:border-gold/50'"
                                                @click="form.performer_kind = 'solo'"
                                            >
                                                Solo
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                                :class="form.performer_kind === 'casal'
                                                    ? 'border-gold bg-gold/10 text-gold'
                                                    : 'border-frame text-muted hover:border-gold/50'"
                                                @click="form.performer_kind = 'casal'"
                                            >
                                                Casal
                                            </button>
                                        </div>
                                        <p v-if="form.errors.performer_kind" class="mt-1 text-xs text-danger">{{ form.errors.performer_kind }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-1">
                                    <button
                                        type="button"
                                        class="text-sm text-muted underline transition-colors hover:text-cream"
                                        @click="step = 1"
                                    >
                                        ← Voltar
                                    </button>
                                    <Button type="submit" variant="primary" size="lg" class="flex-1" :loading="form.processing">
                                        Entrar na lista de espera
                                    </Button>
                                </div>
                            </template>

                            <!-- Honeypot: hidden from humans, catches bots. Always in the DOM. -->
                            <div class="sr-only" aria-hidden="true">
                                <label>Não preencha este campo
                                    <input v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                                </label>
                            </div>
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
