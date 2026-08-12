<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'

// ── Landing cinematográfica ("feat/landing-cinematic") ───────────────────────
// A raiz pública deixou de ser hero-maison + lista de espera e passou a ser a
// PORTA do clube: 5 cenas de tela cheia, scroll-storytelling, dourado e
// mistério. O único CTA leva para /cadastro (route('register')).
//
// Toda a mídia é SELF-HOST (public/landing/*, WebP + um MP4 mudo) — nenhum
// asset de terceiro, então ExternalAssetPolicyTest segue verde. As imagens têm
// variante -mobile.webp (~800px) servida por <picture>; o vídeo de abertura só
// carrega no desktop (mobile e reduced-motion caem na porta.webp estática).

// `referral` continua chegando pelo /convite/{code} (ConviteController). Aqui só
// alimenta o selo discreto "convidado por X" — a atribuição em si é via sessão.
defineProps({
    referral: { type: Object, default: null },
})

// Resolvidos no cliente (Inertia SSR está off): desktop ganha o vídeo e o
// parallax; mobile e quem pede menos movimento ficam na versão estática.
const isDesktop = ref(false)
const motionOk = ref(true)
const showOpeningVideo = ref(false)

let io = null
let rafId = null
let parallaxEls = []

// Parallax leve: desloca a camada de mídia por uma fração do offset ao centro
// da viewport. A mídia é 130% da altura (inset -15%) para o deslocamento nunca
// revelar borda. Roda só em desktop com movimento permitido.
function runParallax() {
    const vh = window.innerHeight
    for (const el of parallaxEls) {
        const rect = el.getBoundingClientRect()
        const offset = rect.top + rect.height / 2 - vh / 2
        el.style.transform = `translate3d(0, ${(offset * -0.08).toFixed(1)}px, 0)`
    }
    rafId = null
}

function onScroll() {
    if (rafId === null) rafId = requestAnimationFrame(runParallax)
}

onMounted(() => {
    isDesktop.value = window.matchMedia('(min-width: 768px) and (pointer: fine)').matches
    motionOk.value = !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    // Vídeo de abertura: só desktop + movimento permitido (senão porta.webp).
    showOpeningVideo.value = isDesktop.value && motionOk.value

    // Reveal-on-scroll: cada .scene-content aparece (fade + subida) ao entrar na
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

    if (motionOk.value && isDesktop.value) {
        parallaxEls = Array.from(document.querySelectorAll('[data-parallax]'))
        window.addEventListener('scroll', onScroll, { passive: true })
        runParallax()
    }
})

onBeforeUnmount(() => {
    io?.disconnect()
    window.removeEventListener('scroll', onScroll)
    if (rafId !== null) cancelAnimationFrame(rafId)
})
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

            <!-- ── Cena 2 · O PORTAL ─────────────────────────────────────── -->
            <section class="scene">
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
                <div class="scene-veil scene-veil--center" aria-hidden="true" />
                <div class="scene-content scene-content--center" data-reveal>
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
                 Wordmark LIMEN dourado, tagline e o único CTA → /cadastro. -->
            <section class="scene scene--dark scene--invite">
                <div class="scene-media scene-media--contain" data-parallax>
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/moldura-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img scene-img--contain scene-img--moldura"
                            :src="'/landing/moldura.webp'"
                            alt="A palavra LIMEN em letras douradas tridimensionais."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <div class="scene-content scene-content--center scene-content--invite" data-reveal>
                    <p class="invite-tagline">O portal do desejo, verificado e real.</p>
                    <a :href="route('register')" class="invite-cta">Solicitar convite</a>
                    <p class="invite-note">Entrada por verificação. Discrição de ponta a ponta.</p>
                </div>
            </section>
        </div>
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
/* Cenas em que a imagem é o "objeto" (digital, moldura): centralizada e contida,
   não recortada. */
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
.scene-img--moldura {
    max-width: min(680px, 88vw);
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
    will-change: transform;
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

.scroll-hint {
    margin-top: 2.5rem;
    font-size: 0.7rem;
    letter-spacing: 0.35em;
    text-transform: uppercase;
    color: var(--color-limen-ink-mute, #8a8175);
    animation: hint-pulse 2.4s ease-in-out 2.6s infinite;
}

/* Cena do convite. */
.scene-content--invite {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.75rem;
    padding-bottom: 10svh;
}
.invite-tagline {
    font-family: var(--font-serif, 'Cormorant Garamond', Georgia, serif);
    font-size: clamp(1.4rem, 4.5vw, 2.25rem);
    letter-spacing: 0.04em;
    color: var(--color-limen-ink, #f0e9dc);
}
.invite-cta {
    display: inline-block;
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
}

/* ── Reveal-on-scroll ─────────────────────────────────────────────────────── */
.scene-content[data-reveal] {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity 0.9s ease-out, transform 0.9s ease-out;
}
.scene-content[data-reveal].is-visible {
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
}

/* Menos movimento: desliga parallax (via JS), animações e reveal. */
@media (prefers-reduced-motion: reduce) {
    .hero-line,
    .scroll-hint {
        animation: none;
    }
    .scene-content[data-reveal] {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .invite-cta:hover {
        transform: none;
    }
}
</style>
