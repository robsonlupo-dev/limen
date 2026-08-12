<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
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

// ── Camada cinematográfica ───────────────────────────────────────────────────
// Resolvidos no cliente (Inertia SSR está off): desktop ganha o vídeo e o
// parallax; mobile e quem pede menos movimento ficam na versão estática.
const isDesktop = ref(false)
const motionOk = ref(true)
const showOpeningVideo = ref(false)

let io = null
let rafId = null
let parallaxEls = []
let fadeEls = []

// Um único laço de scroll (rAF) faz duas coisas quando o movimento é permitido:
//  • parallax leve da mídia (só desktop) — desloca a camada por uma fração do
//    offset ao centro; a mídia é 130% da altura (inset -15%) p/ nunca revelar borda;
//  • fade dirigido por scroll da cena 2: o texto ganha opacidade conforme a CENA
//    sobe na viewport — quase invisível ao entrar, pleno quando ela preenche a tela.
function runScroll() {
    const vh = window.innerHeight

    if (isDesktop.value) {
        for (const el of parallaxEls) {
            const rect = el.getBoundingClientRect()
            const offset = rect.top + rect.height / 2 - vh / 2
            el.style.transform = `translate3d(0, ${(offset * -0.08).toFixed(1)}px, 0)`
        }
    }

    for (const el of fadeEls) {
        // Progresso da CENA (não do texto, que fica no terço inferior): 0 quando a
        // cena entra pela base, 1 quando quase preenche a viewport.
        const scene = el.closest('.scene')
        const p = 1 - scene.getBoundingClientRect().top / (vh * 0.85)
        el.style.opacity = Math.min(1, Math.max(0.06, p)).toFixed(2)
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

    // Reveal-on-scroll: cada [data-reveal] aparece (fade + subida) ao entrar na
    // viewport. Uma passada só; depois desconecta.
    io = new IntersectionObserver(
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
    document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el))

    if (motionOk.value) {
        // Parallax só no desktop; o fade dirigido por scroll roda nos dois (é só
        // opacidade, barato). Sob reduced-motion nada disso liga — o CSS deixa o
        // texto da cena 2 em opacity:1.
        parallaxEls = isDesktop.value ? Array.from(document.querySelectorAll('[data-parallax]')) : []
        fadeEls = Array.from(document.querySelectorAll('[data-scroll-fade]'))
        window.addEventListener('scroll', onScroll, { passive: true })
        runScroll()
    }
})

onBeforeUnmount(() => {
    io?.disconnect()
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
    <GuestLayout title="Limen — O portal do desejo, verificado e real">
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
            <section class="scene">
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
                <div class="scene-content scene-content--center" data-reveal>
                    <h1 class="scene-line hero-line">Alguns portais não se anunciam.</h1>
                    <p class="scroll-hint" aria-hidden="true">role para descer</p>
                </div>
            </section>

            <!-- ── Cena 2 · O PORTAL ─────────────────────────────────────────
                 O arco (portal.webp) fica em brilho pleno — só um gradiente na
                 base escurece atrás do texto. "Cruze o limiar." aparece no terço
                 inferior, ABAIXO do LIMEN da imagem, com fade dirigido por scroll. -->
            <section class="scene scene--portal">
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
                <div class="scene-content scene-content--lower" data-scroll-fade>
                    <h2 class="scene-line">Cruze o limiar.</h2>
                </div>
            </section>

            <!-- ── Cena 3 · A VERIFICAÇÃO ─────────────────────────────────
                 Impressão digital dourada centralizada em fundo escuro. -->
            <section class="scene scene--dark">
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
                <div class="scene-content scene-content--center scene-content--bottom" data-reveal>
                    <h2 class="scene-line">Verificado. Real. Discreto.</h2>
                </div>
            </section>

            <!-- ── Cena 4 · O MISTÉRIO ───────────────────────────────────
                 Silhueta atrás de vidro fosco + máscara veneziana. Lado a lado
                 no desktop, empilhadas no mobile. -->
            <section class="scene scene--split">
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
                <div class="scene-content scene-content--center" data-reveal>
                    <h2 class="scene-line">Um clube para poucos.</h2>
                </div>
            </section>

            <!-- ── Cena 5 · O CONVITE ────────────────────────────────────
                 Wordmark LIMEN dourado (moldura.webp) full-bleed, tagline e o
                 ÚNICO CTA: "Entre na lista de espera" (rola até o formulário). -->
            <section class="scene scene--dark scene--invite">
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
                <div class="scene-content scene-content--invite" data-reveal>
                    <p class="invite-tagline">O portal do desejo, verificado e real.</p>
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
        <section id="lista-de-espera" class="wl-section">
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
   revelar borda; `will-change` mantém o deslocamento no GPU. */
.scene-media {
    position: absolute;
    inset: -15% 0;
    z-index: 0;
    will-change: transform;
}
.scene-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
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

/* Conteúdo textual — sempre acima da mídia e do véu. */
.scene-content {
    position: relative;
    z-index: 2;
    padding: 2rem 1.5rem;
    text-align: center;
    max-width: 40rem;
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

@keyframes hero-in {
    from { opacity: 0; transform: translateY(18px); letter-spacing: 0.14em; }
    to { opacity: 1; transform: translateY(0); letter-spacing: 0.06em; }
}
@keyframes hint-pulse {
    0%, 100% { opacity: 0.35; transform: translateY(0); }
    50% { opacity: 0.9; transform: translateY(4px); }
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

/* Menos movimento: desliga parallax (via JS), animações e reveal. O texto da
   cena 2 fica em opacity:1 pelo fallback de [data-scroll-fade] acima. */
@media (prefers-reduced-motion: reduce) {
    .hero-line,
    .scroll-hint {
        animation: none;
    }
    [data-reveal] {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .invite-cta:hover {
        transform: none;
    }
}
</style>
