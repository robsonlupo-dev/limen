<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Button from '@/Components/Button.vue'

// ── Landing cinematográfica — foco em lista de espera ("feat/landing-waitlist-focus") ─
// O site está em PRÉ-LANÇAMENTO: a landing NÃO oferece cadastro ainda. As 5 cenas
// (porta → arco → verificação → mistério → convite) convergem para UM único CTA —
// "Entre na lista de espera" — que rola até o formulário de captura de e-mail. O
// backend de /cadastro segue intacto; só saiu da landing (volta no lançamento).
//
// Toda a mídia é SELF-HOST (public/landing/*, WebP + um MP4 mudo) — nenhum asset de
// terceiro, então ExternalAssetPolicyTest segue verde. As imagens têm variante
// -mobile.webp (~800px) servida por <picture>; o vídeo de abertura só carrega no
// desktop (mobile e reduced-motion caem na porta.webp estática).

// `referral` chega pelo /convite/{code} (ConviteController): alimenta o selo
// "convidado por X" e sugere o papel na lista de espera (atribuição é via sessão).
const props = defineProps({
    referral: { type: Object, default: null },
})

// Pré-lançamento (flag global `features.landing_prelaunch`, default true): a
// landing esconde os botões de conta do header (só o logo) e o único caminho é a
// lista de espera. No lançamento, `LANDING_PRELAUNCH=false` traz os botões — só o
// .env muda, sem rebuild. Só a Landing consome a flag; as demais telas guest não.
const prelaunch = computed(() => Boolean(usePage().props.features?.landing_prelaunch))

// ── Camada cinematográfica ───────────────────────────────────────────────────
// Resolvidos no cliente (Inertia SSR está off): desktop ganha o vídeo, o
// parallax e o texto em derivada; mobile e quem pede menos movimento ficam na
// versão estática. O Ken Burns e o véu de luz são CSS por TEMPO (independentes
// do scroll) e o reveal em cascata segue nos dois — o CSS os desliga sob
// prefers-reduced-motion.
const isDesktop = ref(false)
const motionOk = ref(true)
const showOpeningVideo = ref(false)

let revealIo = null // reveal-on-scroll: uma passada, depois solta
let stageIo = null // presença em cena: gate de will-change + processamento por-frame
let rafId = null

// Descritores de cena, montados UMA vez: cada um cacheia os elementos que o laço
// de scroll toca, para não pagar querySelector por frame.
let sceneList = []
// Só as cenas EM CENA entram no laço — e só nelas o will-change vive. Cinco
// camadas full-screen promovidas ao mesmo tempo estouram a GPU do celular.
const onstage = new Set()

// Parallax: a mídia sobe mais devagar que o scroll; o texto deriva no sentido
// oposto, mais devagar ainda — as duas velocidades separam os planos. Fatores
// pequenos e com TETO para a mídia (130% de altura, inset -15%) nunca revelar borda.
const MEDIA_SHIFT = -0.1
const MEDIA_CAP = 0.12
const TEXT_SHIFT = 0.05
const TEXT_CAP = 0.06

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

// UM único laço de scroll (rAF): lê o scroll uma vez por frame e distribui para
// tudo que depende dele — parallax da mídia e do texto, fade dirigido por scroll
// da cena 2 e o cross-dissolve (o fundo da cena que sai/entra escurece).
function runScroll() {
    const vh = window.innerHeight

    for (const s of onstage) {
        const rect = s.el.getBoundingClientRect()
        const offset = rect.top + rect.height / 2 - vh / 2

        if (isDesktop.value) {
            if (s.media) {
                const y = clamp(offset * MEDIA_SHIFT, -MEDIA_CAP * vh, MEDIA_CAP * vh)
                s.media.style.transform = `translate3d(0, ${y.toFixed(1)}px, 0)`
            }
            for (const t of s.texts) {
                const y = clamp(offset * TEXT_SHIFT, -TEXT_CAP * vh, TEXT_CAP * vh)
                t.style.transform = `translate3d(0, ${y.toFixed(1)}px, 0)`
            }
        }

        if (s.fade) {
            // Cena 2: o texto ganha opacidade conforme a CENA sobe — quase
            // invisível ao entrar, pleno quando ela preenche a tela.
            const p = 1 - rect.top / (vh * 0.85)
            s.fade.style.opacity = clamp(p, 0.06, 1).toFixed(2)
        }

        if (s.dissolve) {
            // Cross-dissolve: 0 no centro, escurece à medida que a cena sai (para
            // cima ou baixo). Fica em ~0 na banda central e vive ABAIXO do texto
            // (z-index), então não reduz a legibilidade do que se está lendo.
            const d = Math.abs(offset) / (vh * 0.5)
            s.dissolve.style.opacity = (clamp((d - 0.5) / 0.5, 0, 1) * 0.5).toFixed(2)
        }
    }

    rafId = null
}

function onScroll() {
    if (rafId === null) rafId = requestAnimationFrame(runScroll)
}

onMounted(() => {
    isDesktop.value = window.matchMedia('(min-width: 768px) and (pointer: fine)').matches
    motionOk.value = !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    // Vídeo de abertura: só desktop + movimento permitido (senão porta.webp).
    showOpeningVideo.value = isDesktop.value && motionOk.value

    // Reveal-on-scroll: cada [data-reveal] aparece (fade + subida; palavras em
    // cascata via --i no CSS) ao entrar na viewport. Uma passada só; depois solta.
    revealIo = new IntersectionObserver(
        (entries, obs) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible')
                    obs.unobserve(entry.target)
                }
            }
        },
        { threshold: 0.2 },
    )
    document.querySelectorAll('[data-reveal]').forEach((el) => revealIo.observe(el))

    // Sob reduced-motion nada disto liga: sem parallax, sem cross-dissolve, sem
    // presença/will-change. O CSS deixa Ken Burns e véu de luz parados e o texto
    // da cena 2 em opacity:1. O reveal acima segue (o CSS o torna instantâneo).
    if (!motionOk.value) return

    // Descritores por cena — inclui a banda da lista de espera (mármore com Ken
    // Burns). Cacheados uma vez; o laço só lê getBoundingClientRect por frame.
    sceneList = Array.from(document.querySelectorAll('[data-stage]')).map((el) => ({
        el,
        media: el.querySelector('[data-parallax]'),
        texts: isDesktop.value ? Array.from(el.querySelectorAll('[data-parallax-text]')) : [],
        fade: el.querySelector('[data-scroll-fade]'),
        dissolve: el.querySelector('[data-dissolve]'),
    }))

    // Presença em cena: liga o processamento por-frame e o will-change só
    // enquanto a cena está (perto de) visível; solta os dois quando ela sai.
    const byEl = new Map(sceneList.map((s) => [s.el, s]))
    stageIo = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const s = byEl.get(entry.target)
                if (!s) continue
                if (entry.isIntersecting) {
                    onstage.add(s)
                    entry.target.classList.add('is-onstage')
                } else {
                    onstage.delete(s)
                    entry.target.classList.remove('is-onstage')
                }
            }
            onScroll()
        },
        { rootMargin: '20% 0px 20% 0px', threshold: 0 },
    )
    sceneList.forEach((s) => stageIo.observe(s.el))

    window.addEventListener('scroll', onScroll, { passive: true })
    runScroll()
})

onBeforeUnmount(() => {
    revealIo?.disconnect()
    stageIo?.disconnect()
    window.removeEventListener('scroll', onScroll)
    if (rafId !== null) cancelAnimationFrame(rafId)
})

// ── Lista de espera (ÚNICO CTA) ──────────────────────────────────────────────
// Wizard de 2 passos: passo 1 é comum (papel + e-mail + 18+), passo 2 ramifica por
// papel. Um único POST no fim — o servidor re-valida por papel, então o wizard é
// conveniência de UX, não a verdade.
const worlds = [
    { value: 'mulheres', label: 'Mulheres', glyph: '♀' },
    { value: 'homens', label: 'Homens', glyph: '♂' },
    { value: 'casais', label: 'Casais', glyph: '⚭' },
    { value: 'trans', label: 'Trans', glyph: '⚧' },
]

const submitted = ref(false)
const step = ref(1)

const form = useForm({
    name: '',
    email: '',
    role: props.referral?.suggestedRole ?? 'member',
    world: null, // performer: the single world they represent
    world_preferences: [], // member: the (multiple) worlds they want to hear from
    performer_kind: null, // performer + casais: 'solo' | 'casal'
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
    document.getElementById('lista-de-espera')?.scrollIntoView({ behavior: motionOk.value ? 'smooth' : 'auto' })
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
                if (errors.email || errors.role || errors.age_confirmed) {
                    step.value = 1
                }
            },
        })
}
</script>

<template>
    <GuestLayout title="Limen — O portal do desejo, verificado e real" :hide-account-nav="prelaunch">
        <div class="landing-cinematic bg-limen-bg text-limen-ink">
            <!-- Selo de convite (só quando /convite/{code} atribui um referrer). -->
            <div
                v-if="referral"
                class="fixed inset-x-0 top-20 z-30 flex justify-center px-4"
            >
                <div class="rounded-full border border-limen-gold/40 bg-limen-bg/80 px-5 py-2 text-sm text-limen-ink backdrop-blur">
                    Você foi convidado por <span class="text-limen-gold">{{ referral.name }}</span>
                </div>
            </div>

            <!-- ── Cena 1 · ABERTURA ─────────────────────────────────────────
                 Vídeo da câmera cruzando a porta (desktop) ou porta.webp
                 estática (mobile / reduced-motion). Texto surge após ~1.5s. -->
            <section class="scene" data-stage>
                <div class="scene-media" data-parallax>
                    <video
                        v-if="showOpeningVideo"
                        class="scene-img"
                        :src="'/landing/abertura.mp4'"
                        :poster="'/landing/porta.webp'"
                        autoplay
                        muted
                        loop
                        playsinline
                        preload="auto"
                        aria-hidden="true"
                    />
                    <img
                        v-else
                        class="scene-img"
                        :src="'/landing/porta.webp'"
                        alt="Uma porta entreaberta, luz dourada escapando pela fresta."
                        fetchpriority="high"
                    />
                </div>
                <div class="scene-veil scene-veil--center" aria-hidden="true" />
                <div class="scene-dissolve" data-dissolve aria-hidden="true" />
                <div class="scene-content scene-content--center" data-reveal>
                    <h1 class="scene-line hero-line">Alguns portais não se anunciam.</h1>
                    <p class="scroll-hint" aria-hidden="true">role para descer</p>
                </div>
            </section>

            <!-- ── Cena 2 · O PORTAL ─────────────────────────────────────────
                 O arco (portal.webp) fica em brilho pleno — só um gradiente na
                 base escurece atrás do texto. "Cruze o limiar." aparece no terço
                 inferior, ABAIXO do LIMEN da imagem, com fade dirigido por scroll. -->
            <section class="scene scene--portal" data-stage>
                <div class="scene-media" data-parallax>
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/portal-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img"
                            :src="'/landing/portal.webp'"
                            alt="Arco de luz dourada com a palavra LIMEN, refletido no mármore."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="scene-veil scene-veil--bottom" aria-hidden="true" />
                <div class="light-sheen" aria-hidden="true"><span class="light-sheen-band" /></div>
                <div class="scene-dissolve" data-dissolve aria-hidden="true" />
                <div class="scene-content scene-content--lower" data-scroll-fade>
                    <h2 class="scene-line" data-parallax-text>Cruze o limiar.</h2>
                </div>
            </section>

            <!-- ── Cena 3 · A VERIFICAÇÃO ─────────────────────────────────
                 Impressão digital dourada centralizada em fundo escuro. -->
            <section class="scene scene--dark" data-stage>
                <div class="scene-media scene-media--contain" data-parallax>
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/digital-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img scene-img--contain"
                            :src="'/landing/digital.webp'"
                            alt="Uma impressão digital desenhada em traços de luz dourada."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="scene-dissolve" data-dissolve aria-hidden="true" />
                <div class="scene-content scene-content--center scene-content--bottom reveal-stagger" data-reveal>
                    <h2 class="scene-line" data-parallax-text>
                        <span class="reveal-word" :style="{ '--i': 0 }">Verificado.</span>
                        <span class="reveal-word" :style="{ '--i': 1 }">Real.</span>
                        <span class="reveal-word" :style="{ '--i': 2 }">Discreto.</span>
                    </h2>
                </div>
            </section>

            <!-- ── Cena 4 · O MISTÉRIO ───────────────────────────────────
                 Silhueta atrás de vidro fosco + máscara veneziana. Lado a lado
                 no desktop, empilhadas no mobile. -->
            <section class="scene scene--split" data-stage>
                <div class="split-pane">
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/silhueta-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img"
                            :src="'/landing/silhueta.webp'"
                            alt="Silhueta de um corpo atrás de um vidro fosco cor de âmbar."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="split-pane">
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/mascara-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img"
                            :src="'/landing/mascara.webp'"
                            alt="Máscara veneziana preta com detalhes em ouro."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="scene-veil scene-veil--full" aria-hidden="true" />
                <div class="scene-dissolve" data-dissolve aria-hidden="true" />
                <div class="scene-content scene-content--center reveal-stagger" data-reveal>
                    <h2 class="scene-line" data-parallax-text>
                        <span class="reveal-word" :style="{ '--i': 0 }">Um</span>
                        <span class="reveal-word" :style="{ '--i': 1 }">clube</span>
                        <span class="reveal-word" :style="{ '--i': 2 }">para</span>
                        <span class="reveal-word" :style="{ '--i': 3 }">poucos.</span>
                    </h2>
                </div>
            </section>

            <!-- ── Cena 5 · O CONVITE ────────────────────────────────────
                 Wordmark LIMEN dourado (moldura.webp) full-bleed, tagline e o
                 ÚNICO CTA: "Entre na lista de espera" (rola até o formulário). -->
            <section class="scene scene--dark scene--invite" data-stage>
                <div class="scene-media" data-parallax>
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/moldura-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img"
                            :src="'/landing/moldura.webp'"
                            alt="A palavra LIMEN em letras douradas tridimensionais."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="scene-veil scene-veil--bottom" aria-hidden="true" />
                <div class="light-sheen" aria-hidden="true"><span class="light-sheen-band" /></div>
                <div class="scene-dissolve" data-dissolve aria-hidden="true" />
                <div class="scene-content scene-content--invite" data-reveal>
                    <p class="invite-tagline" data-parallax-text>O portal do desejo, verificado e real.</p>
                    <button type="button" class="invite-cta" @click="scrollToForm()">
                        Entre na lista de espera
                    </button>
                    <p class="invite-note">Lançamento em breve · entrada por convite</p>
                </div>
            </section>
        </div>

        <!-- ── Lista de espera (ÚNICO CTA da landing) ────────────────────────
             Mesma identidade das cenas: mármore (moldura.webp) escurecido atrás
             do formulário, que fica centralizado e com respiro. -->
        <section id="lista-de-espera" class="wl-section" data-stage>
            <div class="wl-bg" aria-hidden="true">
                <img class="wl-bg-img" :src="'/landing/moldura.webp'" alt="" loading="lazy" />
                <div class="wl-bg-veil"></div>
            </div>

            <div class="wl-inner">
                <div data-reveal class="wl-card rounded-3xl border border-limen-line bg-limen-surface/95 p-8 shadow-2xl backdrop-blur-sm md:p-10">
                    <template v-if="submitted">
                        <div class="py-6 text-center">
                            <div class="mb-4 text-4xl text-limen-gold">✓</div>
                            <h2 class="font-serif text-3xl text-limen-ink">Pronto! Você está na lista</h2>
                            <p class="mx-auto mt-4 max-w-sm text-limen-ink-soft leading-relaxed">
                                Fique de olho no seu e-mail — e <span class="text-limen-ink">confira a caixa de spam</span>.
                                Marque nossa mensagem como <span class="text-limen-gold">“não é spam”</span> para não
                                perder o aviso de lançamento.
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <div class="mb-8 text-center">
                            <h2 class="font-serif text-3xl text-limen-ink md:text-4xl">Entre na lista de espera</h2>
                            <p class="mx-auto mt-3 max-w-sm text-limen-ink-soft">
                                Deixe seu e-mail e seja avisado no lançamento. Sem spam — só o convite quando abrirmos.
                            </p>
                        </div>

                        <p class="mb-5 text-center text-xs uppercase tracking-widest text-limen-ink-mute">
                            Passo {{ step }} de 2
                        </p>

                        <form class="space-y-5" novalidate @submit.prevent="onSubmit">
                            <!-- ── Passo 1: papel + e-mail + 18+ (comum) ── -->
                            <template v-if="step === 1">
                                <!-- Papel -->
                                <div>
                                    <label class="text-sm font-medium text-limen-ink">Eu quero entrar como</label>
                                    <div class="mt-2 grid grid-cols-2 gap-3">
                                        <button
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.role === 'member'
                                                ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                            @click="selectRole('member')"
                                        >
                                            👤 Membro
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.role === 'performer'
                                                ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                            @click="selectRole('performer')"
                                        >
                                            🌟 Performer
                                        </button>
                                    </div>
                                    <p v-if="form.errors.role" class="mt-1 text-xs text-danger">{{ form.errors.role }}</p>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label for="wl-email" class="text-sm font-medium text-limen-ink">E-mail</label>
                                    <input
                                        id="wl-email"
                                        v-model="form.email"
                                        type="email"
                                        autocomplete="email"
                                        placeholder="voce@email.com"
                                        class="mt-2 w-full rounded-lg border border-limen-line bg-limen-bg px-4 py-3 text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                                    />
                                    <p v-if="form.errors.email" class="mt-1 text-xs text-danger">{{ form.errors.email }}</p>
                                </div>

                                <!-- 18+ consent (captured server-side). -->
                                <div>
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input
                                            v-model="form.age_confirmed"
                                            type="checkbox"
                                            class="mt-0.5 h-4 w-4 rounded border-limen-line bg-limen-bg accent-limen-gold"
                                        />
                                        <span class="text-sm text-limen-ink-soft">
                                            Confirmo que tenho <span class="text-limen-ink">18 anos ou mais</span> e concordo
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
                                    <label for="wl-name" class="text-sm font-medium text-limen-ink">
                                        {{ form.role === 'performer' ? 'Nome artístico' : 'Nome' }}
                                    </label>
                                    <input
                                        id="wl-name"
                                        v-model="form.name"
                                        type="text"
                                        :autocomplete="form.role === 'performer' ? 'off' : 'name'"
                                        :placeholder="form.role === 'performer' ? 'Seu nome artístico' : 'Como podemos te chamar'"
                                        class="mt-2 w-full rounded-lg border border-limen-line bg-limen-bg px-4 py-3 text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                                    />
                                    <p v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</p>
                                </div>

                                <!-- Membro: preferências de mundo (múltiplas, opcionais) -->
                                <div v-if="form.role === 'member'">
                                    <label class="text-sm font-medium text-limen-ink">
                                        Quais mundos te interessam? <span class="text-limen-ink-soft">(opcional)</span>
                                    </label>
                                    <div class="mt-2 grid grid-cols-2 gap-3">
                                        <button
                                            v-for="world in worlds"
                                            :key="world.value"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.world_preferences.includes(world.value)
                                                ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                            @click="toggleWorldPreference(world.value)"
                                        >
                                            {{ world.glyph }} {{ world.label }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Performer: mundo representado (único, obrigatório) -->
                                <div v-else>
                                    <label class="text-sm font-medium text-limen-ink">Qual mundo você representa?</label>
                                    <div class="mt-2 grid grid-cols-2 gap-3">
                                        <button
                                            v-for="world in worlds"
                                            :key="world.value"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.world === world.value
                                                ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                            @click="pickPerformerWorld(world.value)"
                                        >
                                            {{ world.glyph }} {{ world.label }}
                                        </button>
                                    </div>
                                    <p v-if="form.errors.world" class="mt-1 text-xs text-danger">{{ form.errors.world }}</p>

                                    <!-- Mundo Casais: solo/casal (obrigatório) -->
                                    <div v-if="form.world === 'casais'" class="mt-4">
                                        <label class="text-sm font-medium text-limen-ink">No mundo Casais, você se cadastra como</label>
                                        <div class="mt-2 grid grid-cols-2 gap-3">
                                            <button
                                                type="button"
                                                class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                                :class="form.performer_kind === 'solo'
                                                    ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                    : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                                @click="form.performer_kind = 'solo'"
                                            >
                                                Solo
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                                :class="form.performer_kind === 'casal'
                                                    ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                    : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
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
                                        class="text-sm text-limen-ink-soft underline transition-colors hover:text-limen-ink"
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
.landing-cinematic {
    overflow-x: clip;
}

/* Cada cena ocupa a viewport inteira. */
.scene {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100svh;
    overflow: hidden;
}
.scene--dark {
    background-color: #100d0a;
}

/* Camada de mídia (fundo full-bleed). 130% de altura para o parallax nunca
   revelar borda. `will-change` só é promovido quando a cena está em cena
   (`.is-onstage`) — cinco camadas full-screen promovidas de uma vez estouram a
   GPU do celular. */
.scene-media {
    position: absolute;
    inset: -15% 0;
    z-index: 0;
}
[data-stage].is-onstage .scene-media {
    will-change: transform;
}
.scene-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
/* Ken Burns: zoom/pan lento e contínuo, por TEMPO (não pelo scroll). O seletor
   `img` isenta o <video> da cena 1 (ele já tem movimento próprio), mas cobre o
   fallback estático porta.webp. `alternate` volta suave, sem corte. */
img.scene-img {
    transform-origin: center;
    animation: ken-burns-a 24s ease-in-out infinite alternate;
}
[data-stage].is-onstage img.scene-img {
    will-change: transform;
}
/* Direção diferente por cena (o mesmo enquadramento repetido lê como slideshow). */
.scene--portal img.scene-img {
    animation-name: ken-burns-b;
    animation-duration: 22s;
}
.scene--split .split-pane:first-of-type img.scene-img {
    animation-name: ken-burns-a;
}
.scene--split .split-pane:nth-of-type(2) img.scene-img {
    animation-name: ken-burns-c;
}
.scene--invite img.scene-img {
    animation-name: ken-burns-c;
    animation-duration: 26s;
}
/* Cena 3 (impressão digital): é OBJETO contido, não fundo — só respira (escala),
   sem pan, senão sairia do centro. */
img.scene-img--contain {
    animation-name: ken-burns-breathe;
    animation-duration: 18s;
}
/* Cena 3 (digital): a imagem é o "objeto", centralizada e contida — não recortada. */
.scene-media--contain {
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12svh 8vw;
}
.scene-img--contain {
    width: auto;
    height: auto;
    max-width: min(560px, 82vw);
    max-height: 62svh;
    object-fit: contain;
}

/* Cena 4: dois painéis lado a lado (desktop) / empilhados (mobile). */
.scene--split {
    flex-direction: column;
}
.split-pane {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 50%;
    top: auto;
}
.split-pane:first-of-type {
    top: 0;
}
.split-pane:nth-of-type(2) {
    bottom: 0;
}
.split-pane .scene-img {
    height: 100%;
}

/* Véu escuro — garante leitura do texto sobre a imagem. */
.scene-veil {
    position: absolute;
    inset: 0;
    z-index: 1;
}
.scene-veil--center {
    background: radial-gradient(
        ellipse 70% 60% at 50% 55%,
        rgba(10, 8, 6, 0.72),
        rgba(10, 8, 6, 0.42) 60%,
        rgba(10, 8, 6, 0.28)
    );
}
.scene-veil--full {
    background: linear-gradient(
        to bottom,
        rgba(10, 8, 6, 0.55),
        rgba(10, 8, 6, 0.35) 40%,
        rgba(10, 8, 6, 0.7)
    );
}
/* Só a base escurece — a imagem (arco / wordmark) fica em brilho pleno acima. */
.scene-veil--bottom {
    background: linear-gradient(
        to bottom,
        transparent 42%,
        rgba(10, 8, 6, 0.5) 68%,
        rgba(10, 8, 6, 0.85)
    );
}

/* Conteúdo textual — sempre acima da mídia, do véu, do véu de luz e do
   cross-dissolve (z 3, o topo da pilha da cena). O texto nunca escurece. */
.scene-content {
    position: relative;
    z-index: 3;
    padding: 2rem 1.5rem;
    text-align: center;
    max-width: 40rem;
}

/* ── Véu de luz: reflexo dourado que desliza devagar sobre o mármore ─────────
   Uma faixa de ouro TRANSLÚCIDA num container que recorta a cena; anima só por
   `transform` (GPU). Fica ABAIXO do texto (z 2) e é fraca — luxo, não festa —,
   então não clareia os véus de contraste nem atrapalha a leitura. */
.light-sheen {
    position: absolute;
    inset: 0;
    z-index: 2;
    overflow: hidden;
    pointer-events: none;
}
.light-sheen-band {
    position: absolute;
    inset: -20% -45%;
    display: block;
    background: linear-gradient(
        105deg,
        transparent 40%,
        rgba(214, 184, 114, 0.1) 50%,
        rgba(214, 184, 114, 0.03) 57%,
        transparent 64%
    );
    transform: translate3d(-16%, 0, 0);
    animation: sheen-drift 16s ease-in-out infinite alternate;
}
[data-stage].is-onstage .light-sheen-band {
    will-change: transform;
}

/* ── Cross-dissolve: o fundo da cena que sai/entra escurece (opacidade dirigida
   por scroll no laço rAF). Fica ABAIXO do texto (z 2), então a transição entre
   cenas lê como fade-através-do-escuro, sem tocar a legibilidade. */
.scene-dissolve {
    position: absolute;
    inset: 0;
    z-index: 2;
    background: #0a0806;
    opacity: 0;
    pointer-events: none;
}
.scene-content--center {
    margin-inline: auto;
}
/* Nas cenas "contidas", empurra o texto para a base (dá respiro à imagem). */
.scene-content--bottom {
    position: absolute;
    bottom: 8svh;
    left: 0;
    right: 0;
}
/* Terço inferior: texto ABAIXO do LIMEN da imagem, nunca cobrindo-o. */
.scene-content--lower {
    position: absolute;
    bottom: 12svh;
    left: 0;
    right: 0;
    margin-inline: auto;
}

.scene-line {
    font-family: var(--font-serif, 'Cormorant Garamond', Georgia, serif);
    font-weight: 500;
    font-size: clamp(1.9rem, 6vw, 3.75rem);
    line-height: 1.15;
    letter-spacing: 0.06em;
    color: var(--color-limen-gold, #d6b872);
    text-shadow: 0 2px 24px rgba(0, 0, 0, 0.55);
}
.hero-line {
    font-style: italic;
    /* Surge ~1.5s depois da abertura entrar. `both` mantém invisível no atraso. */
    animation: hero-in 1.4s ease-out 1.5s both;
}
/* Cena 2: opacidade inicial é dirigida por scroll no JS; este é o fallback
   (pré-JS e reduced-motion): texto plenamente visível. */
[data-scroll-fade] {
    opacity: 1;
}

.scroll-hint {
    margin-top: 2.5rem;
    font-size: 0.7rem;
    letter-spacing: 0.35em;
    text-transform: uppercase;
    color: var(--color-limen-ink-mute, #8a8175);
    animation: hint-pulse 2.4s ease-in-out 2.6s infinite;
}

/* Cena do convite: tagline + CTA no terço inferior, sobre o wordmark full-bleed. */
.scene-content--invite {
    position: absolute;
    bottom: 8svh;
    left: 0;
    right: 0;
    margin-inline: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.4rem;
}
.invite-tagline {
    font-family: var(--font-serif, 'Cormorant Garamond', Georgia, serif);
    font-size: clamp(1.4rem, 4.5vw, 2.25rem);
    letter-spacing: 0.04em;
    color: var(--color-limen-ink, #f0e9dc);
    text-shadow: 0 2px 20px rgba(0, 0, 0, 0.6);
}
.invite-cta {
    display: inline-block;
    cursor: pointer;
    border: none;
    border-radius: 9999px;
    background: var(--color-limen-gold, #d6b872);
    color: var(--color-limen-bg, #181410);
    padding: 0.9rem 2.6rem;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    text-decoration: none;
    transition: background-color 0.25s ease, transform 0.25s ease, box-shadow 0.25s ease;
}
.invite-cta:hover {
    background: #e3c77a;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(214, 184, 114, 0.28);
}
.invite-cta:focus-visible {
    outline: 2px solid var(--color-limen-gold, #d6b872);
    outline-offset: 4px;
}
.invite-note {
    font-size: 0.75rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--color-limen-ink-mute, #8a8175);
    text-shadow: 0 1px 12px rgba(0, 0, 0, 0.7);
}

/* ── Seção da lista de espera: mesmo mármore das cenas, escurecido ─────────── */
.wl-section {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100svh;
    padding: 8svh 1.5rem;
    overflow: hidden;
    scroll-margin-top: 5rem;
}
.wl-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
}
.wl-bg-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    /* Vida subliminar atrás do mármore escurecido: respira devagar (só escala). */
    transform-origin: center;
    animation: ken-burns-breathe 26s ease-in-out infinite alternate;
}
[data-stage].is-onstage .wl-bg-img {
    will-change: transform;
}
/* Escurece forte: o mármore vira textura, não distrai do formulário. */
.wl-bg-veil {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(10, 8, 6, 0.92),
        rgba(10, 8, 6, 0.88)
    );
}
.wl-inner {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 32rem;
}

/* ── Reveal-on-scroll ─────────────────────────────────────────────────────── */
[data-reveal] {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity 0.9s ease-out, transform 0.9s ease-out;
}
[data-reveal].is-visible {
    opacity: 1;
    transform: translateY(0);
}
/* A cena 1 revela junto com o hero-in (o texto tem seu próprio atraso). */
.scene:first-of-type .scene-content[data-reveal] {
    opacity: 1;
    transform: none;
}

/* Reveal em CASCATA: o container não anima (fica visível); cada palavra entra em
   sequência (fade + subida) pelo atraso escalonado `--i`. Mais específico que
   `[data-reveal]`, então vence sem !important. */
[data-reveal].reveal-stagger {
    opacity: 1;
    transform: none;
    transition: none;
}
[data-reveal] .reveal-word {
    display: inline-block;
    opacity: 0;
    transform: translateY(16px);
    transition:
        opacity 0.6s ease-out,
        transform 0.6s ease-out;
    transition-delay: calc(var(--i, 0) * 110ms);
}
[data-reveal].is-visible .reveal-word {
    opacity: 1;
    transform: none;
}

@keyframes hero-in {
    from { opacity: 0; transform: translateY(18px); letter-spacing: 0.14em; }
    to { opacity: 1; transform: translateY(0); letter-spacing: 0.06em; }
}
@keyframes hint-pulse {
    0%, 100% { opacity: 0.35; transform: translateY(0); }
    50% { opacity: 0.9; transform: translateY(4px); }
}

/* Ken Burns — sempre parte de escala ≥ 1.04 (com o inset -15% da mídia, cobre a
   cena com folga; o pan de ±1–2% nunca revela borda). Direções distintas por cena. */
@keyframes ken-burns-a {
    from { transform: scale(1.04) translate3d(0.6%, 0.6%, 0); }
    to { transform: scale(1.11) translate3d(-1.4%, -1.2%, 0); }
}
@keyframes ken-burns-b {
    from { transform: scale(1.05) translate3d(-1.2%, 0.4%, 0); }
    to { transform: scale(1.1) translate3d(1.5%, -0.7%, 0); }
}
@keyframes ken-burns-c {
    from { transform: scale(1.05) translate3d(0.9%, -1%, 0); }
    to { transform: scale(1.11) translate3d(-0.7%, 1.3%, 0); }
}
@keyframes ken-burns-breathe {
    from { transform: scale(1); }
    to { transform: scale(1.06); }
}
@keyframes sheen-drift {
    from { transform: translate3d(-16%, 0, 0); }
    to { transform: translate3d(16%, 0, 0); }
}

/* Desktop: cena 4 fica lado a lado. */
@media (min-width: 768px) {
    .split-pane {
        width: 50%;
        height: 100%;
        top: 0;
    }
    .split-pane:first-of-type {
        left: 0;
        right: auto;
    }
    .split-pane:nth-of-type(2) {
        left: auto;
        right: 0;
    }
    /* Mais respiro no desktop: texto da cena 2 e CTA da cena 5 sobem um pouco. */
    .scene-content--lower {
        bottom: 16svh;
    }
    .scene-content--invite {
        bottom: 12svh;
    }
}

/* Mobile: fluidez primeiro. O parallax e a derivada do texto já ficam DESLIGADOS
   (JS: só desktop); o Ken Burns fica mais sutil — só respira (escala, sem pan) e
   mais devagar, em toda imagem de cena e no mármore da lista de espera. */
@media (max-width: 767px) {
    /* Casa a especificidade das regras por-cena (com `img`) para de fato vencê-las. */
    img.scene-img,
    .scene--portal img.scene-img,
    .scene--split .split-pane:first-of-type img.scene-img,
    .scene--split .split-pane:nth-of-type(2) img.scene-img,
    .scene--invite img.scene-img {
        animation-name: ken-burns-breathe;
        animation-duration: 30s;
    }
}

/* Menos movimento: desliga parallax e cross-dissolve (via JS), Ken Burns, véu de
   luz, reveal (container e palavras) e as animações de tempo. O texto da cena 2
   fica em opacity:1 pelo fallback de [data-scroll-fade] acima. Bloco ÚNICO.
   `!important` no kill de animação porque as regras por-cena de Ken Burns têm
   especificidade alta — é o override de acessibilidade, o lugar onde ele cabe. */
@media (prefers-reduced-motion: reduce) {
    .hero-line,
    .scroll-hint {
        animation: none;
    }
    img.scene-img,
    .wl-bg-img,
    .light-sheen-band {
        animation: none !important;
    }
    .light-sheen {
        opacity: 0;
    }
    [data-reveal],
    [data-reveal] .reveal-word {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .invite-cta:hover {
        transform: none;
    }
}
</style>
