<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

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

// ── Sequência cinematográfica empilhada (cross-dissolve dirigido por scroll) ──
// As 5 cenas deixam de ser seções lado a lado e passam a ocupar O MESMO espaço da
// tela, sobrepostas dentro de um "palco" sticky. Um wrapper alto (N × 100svh) dá o
// curso de scroll; o laço rAF ÚNICO lê o progresso do scroll DENTRO do wrapper e,
// por cena, distribui opacidade + brilho: a que sai escurece e some, a que entra
// clareia e aparece. A curva é simétrica e derivada da POSIÇÃO — funciona igual nos
// dois sentidos, sem emenda. Mobile roda o cross-fade (opacity/brightness); parallax
// e derivada do texto ficam só no desktop (fluidez primeiro). Reduced-motion cai no
// empilhamento vertical normal (sem `is-stacked`), cenas estáticas e legíveis.
//
// NOTA de arquitetura: a spec sugeria "cada cena position:sticky". Um palco sticky
// ÚNICO com as cenas absolutas sobrepostas dá o cross-fade PURO que o PO pediu
// ("a de cima escurece, a de baixo clareia, na MESMA posição") — sticky por-cena
// deixaria a cena entrante DESLIZAR de baixo (a emenda que se quer eliminar). Mesmo
// wrapper de N×100svh, mesmo resultado visual, sem o slide.
const isDesktop = ref(false)
const motionOk = ref(true)
const showOpeningVideo = ref(false)
const stackEl = ref(null)

// Tamanho do logo (PortalLogo) na cena do convite. Responsivo, com TETO em px para
// não estourar em monitor grande: ~50% da largura no desktop (cap 320px), ~75% no
// retrato (cap 260px). `size` do PortalLogo comanda o ícone e o wordmark juntos, em
// proporção da marca — nada de fonte/logo improvisado. Fixado uma vez no mount (como
// `isDesktop`, a landing não é reativa a resize).
const logoSize = ref(240)

let revealIo = null // reveal da lista de espera (seção normal, abaixo do palco)
let wlStageIo = null // presença da banda de waitlist: gate de will-change do mármore
let rafId = null
let viewportEl = null // o palco sticky; sua altura real fecha a conta do progresso

// Descritores de cena, montados UMA vez (cacheiam o que o laço toca por frame).
let sceneList = []

// Parallax dentro da cena fixada: a mídia deriva devagar e o TEXTO deriva mais
// RÁPIDO, em sentido oposto — planos separados. Fatores em fração de vh, com teto
// para a mídia (inset -15% de folga) nunca revelar borda. Só no desktop.
const MEDIA_SHIFT = 0.05
const MEDIA_CAP = 0.07
const TEXT_SHIFT = 0.1
const TEXT_CAP = 0.12
// Brilho da imagem que sai/entra: 1 no centro → 0.4 na borda da transição.
const DIM = 0.6
// A cascata de palavras / reveal do texto dispara ao chegar perto do brilho pleno.
const REVEAL_AT = 0.22

// ── Zoom de saída da cena final (o LOGO LIMEN) ────────────────────────────────
// Pedido do PO: "o LIMEN aparece e, conforme rolo pra baixo, vai crescendo até
// desaparecer e dar lugar à próxima seção". O cross-dissolve não cobre isso — a
// última cena não tem sucessora para empurrar `p` além dela, então ela chega em
// brilho pleno exatamente quando o palco solta. Damos ao palco uma CAUDA extra de
// scroll (ZOOM_TAIL viewports) DEPOIS do dissolve terminar: nesse trecho o LOGO
// (PortalLogo) da cena 5 cresce de ~1.0 a ~1.6 e a cena esmaece no fim, revelando a
// banda da lista de espera — a sensação é de ATRAVESSAR o portal (o logo cresce),
// não de a parede inteira crescer (o mármore de fundo fica quieto, só respira). A
// escala é derivada da POSIÇÃO (simétrica: rolar pra cima encolhe de volta) e roda
// no MESMO laço rAF. Só o LOGO cresce — tagline/CTA ficam no tamanho e clicáveis.
const ZOOM_TAIL = 1 // viewports extras de scroll reservados ao zoom da cena final
const INVITE_ZOOM = 0.6 // escala do logo 1.0 → 1.6
const INVITE_FADE_AT = 0.6 // fração da cauda a partir da qual a cena esmaece

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

// UM único laço de scroll (rAF): lê o scroll uma vez por frame e distribui para
// tudo — cross-dissolve (opacity+brightness por cena), parallax da mídia e do
// texto, e o gatilho do reveal no brilho pleno.
function runScroll() {
    const stack = stackEl.value
    if (!stack || !viewportEl) {
        rafId = null
        return
    }

    // Progresso 0..1 dentro do wrapper: 0 = topo do palco no topo da tela; 1 = o
    // palco terminou de rolar (exatamente quando o sticky solta). Usar a altura
    // REAL do palco (não innerHeight) alinha progress=1 ao release do sticky mesmo
    // com a barra de endereço do Safari mexendo no viewport.
    const span = stack.offsetHeight - viewportEl.offsetHeight
    const progress = span > 0 ? clamp(-stack.getBoundingClientRect().top / span, 0, 1) : 0
    // O curso do palco vale (N-1) "beats" de cross-dissolve + ZOOM_TAIL de cauda. `p`
    // percorre as cenas [0..N-1] e SATURA em N-1; `zt` (0 durante o dissolve → 1 no
    // fim da cauda) só existe depois que `p` chega na última cena.
    const beats = sceneList.length - 1 + ZOOM_TAIL
    const raw = progress * beats
    const p = clamp(raw, 0, sceneList.length - 1) // posição contínua entre cenas [0..N-1]
    const zt = ZOOM_TAIL > 0 ? clamp((raw - (sceneList.length - 1)) / ZOOM_TAIL, 0, 1) : 0
    const vh = window.innerHeight

    for (const s of sceneList) {
        const local = p - s.i // <0 entrando (de baixo), 0 no centro, >0 saindo (por cima)
        const dist = Math.abs(local)
        let opacity = clamp(1 - dist, 0, 1)

        // Cena do convite (última): zoom de saída dirigido pelo scroll. O LOGO cresce
        // (abaixo) e a cena esmaece no fim da cauda; a escala e o fade derivam de `zt`
        // (posição), então rolar pra cima desfaz na mesma curva. Só o LOGO entra no
        // scale — mármore de fundo, tagline e CTA NÃO crescem.
        if (s.isInvite) {
            const fade = clamp((zt - INVITE_FADE_AT) / (1 - INVITE_FADE_AT), 0, 1)
            opacity *= 1 - fade
        }
        s.el.style.opacity = opacity.toFixed(3)

        const visible = opacity > 0.001
        if (visible !== s.visible) {
            s.visible = visible
            s.el.classList.toggle('is-onstage', visible) // will-change só na cena em cena
        }
        if (!visible) continue

        // Brilho: a imagem escurece conforme a cena sai/entra (o texto some por opacity).
        const brightness = (1 - dist * DIM).toFixed(3)
        for (const d of s.dimmers) d.style.filter = `brightness(${brightness})`

        // Mídia: parallax leve, só desktop. O mármore da cena do convite NÃO recebe
        // escala de scroll (quem cresce é o logo) — fica quieto (só o Ken Burns-breathe
        // por tempo), para a sensação ser de atravessar o portal, não de a parede crescer.
        if (s.media && isDesktop.value) {
            const y = clamp(local * MEDIA_SHIFT * vh, -MEDIA_CAP * vh, MEDIA_CAP * vh)
            s.media.style.transform = `translate3d(0, ${y.toFixed(1)}px, 0)`
        }
        // Zoom de saída da cena final: escala aplicada ao LOGO (todas as telas). Escrito
        // a cada frame visível (mesmo em zt=0 → scale(1)), então rolar pra cima o reseta.
        if (s.isInvite && s.logo) {
            const scale = 1 + INVITE_ZOOM * zt
            s.logo.style.transform = `scale(${scale.toFixed(3)})`
        }
        if (isDesktop.value) {
            for (const t of s.texts) {
                const y = clamp(-local * TEXT_SHIFT * vh, -TEXT_CAP * vh, TEXT_CAP * vh)
                t.style.transform = `translate3d(0, ${y.toFixed(1)}px, 0)`
            }
        }

        // Reveal / cascata de palavras: dispara ao chegar no brilho pleno (dist pequena).
        if (!s.revealed && s.reveal && dist < REVEAL_AT) {
            s.revealed = true
            s.reveal.classList.add('is-visible')
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

    // Logo da cena do convite: ~50% da largura no desktop (cap 320) / ~75% no retrato
    // (cap 260). O cap em px evita o logo gigante em monitor largo.
    const portrait = window.matchMedia('(max-width: 767px), (orientation: portrait)').matches
    logoSize.value = portrait
        ? Math.min(Math.round(window.innerWidth * 0.75), 260)
        : Math.min(Math.round(window.innerWidth * 0.5), 320)

    // Reveal da lista de espera (seção normal, abaixo do palco): fade + subida.
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
    document.querySelectorAll('.wl-section [data-reveal]').forEach((el) => revealIo.observe(el))

    // Sob reduced-motion o palco NÃO empilha: as cenas ficam no fluxo vertical normal
    // (sem `is-stacked`), estáticas e legíveis; o CSS deixa Ken Burns/véu de luz
    // parados e os reveals instantâneos. O observer acima segue valendo.
    if (!motionOk.value) return

    const stack = stackEl.value
    if (!stack) return
    viewportEl = stack.querySelector('.scene-viewport')

    // Descritores por cena. `dimmers` são as camadas de imagem que escurecem;
    // `media` é a que recebe o parallax; `reveal` é o container do texto em cascata.
    sceneList = Array.from(stack.querySelectorAll('.scene')).map((el, i, arr) => ({
        el,
        i,
        isInvite: i === arr.length - 1, // a última cena (convite) ganha o zoom de saída
        media: el.querySelector('[data-parallax]'),
        logo: el.querySelector('[data-invite-logo]'), // o logo que cresce no zoom de saída
        dimmers: Array.from(el.querySelectorAll('.scene-media, .split-pane')),
        texts: isDesktop.value ? Array.from(el.querySelectorAll('[data-parallax-text]')) : [],
        reveal: el.querySelector('[data-reveal]'),
        visible: null,
        revealed: false,
    }))

    // O wrapper ganha o curso de scroll (N × 100svh) e vira palco empilhado. A dupla
    // atribuição é o fallback de unidade: se `svh` não existe, a 2ª é rejeitada e o
    // `vh` fica (Safari antigo). O palco sticky por dentro vem do CSS `.is-stacked`.
    // +ZOOM_TAIL viewports de curso extra: a cauda de scroll onde a cena 5 dá o zoom
    // de saída depois de o cross-dissolve já ter terminado.
    const stackVh = (sceneList.length + ZOOM_TAIL) * 100
    stack.style.height = `${stackVh}vh`
    stack.style.height = `${stackVh}svh`
    stack.classList.add('is-stacked')

    // Presença da banda de waitlist (mármore com Ken Burns): gate de will-change.
    wlStageIo = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) entry.target.classList.toggle('is-onstage', entry.isIntersecting)
        },
        { rootMargin: '10% 0px 10% 0px', threshold: 0 },
    )
    document.querySelectorAll('.wl-section[data-stage]').forEach((el) => wlStageIo.observe(el))

    window.addEventListener('scroll', onScroll, { passive: true })
    runScroll() // pinta o estado inicial antes do primeiro paint (evita flash das cenas)
})

onBeforeUnmount(() => {
    revealIo?.disconnect()
    wlStageIo?.disconnect()
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

            <!-- ── Palco cinematográfico: as 5 cenas EMPILHADAS no mesmo espaço ──
                 O wrapper alto (N × 100svh, altura fixada no JS) dá o curso de
                 scroll; o palco (.scene-viewport) fica sticky por dentro e as cenas
                 são absolutas, sobrepostas. O laço rAF faz o cross-dissolve
                 (opacity + brightness) dirigido pela POSIÇÃO do scroll. Sem
                 `is-stacked` (reduced-motion / pré-JS) as cenas caem no fluxo
                 vertical normal, estáticas e legíveis. -->
            <div ref="stackEl" class="scene-stack">
              <div class="scene-viewport">

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
                <div class="light-sheen" aria-hidden="true"><span class="light-sheen-band" /></div>
                <!-- O antigo [data-scroll-fade] foi ABSORVIDO pelo cross-dissolve do
                     palco: o texto revela no brilho pleno da cena (como as demais). -->
                <div class="scene-content scene-content--lower" data-reveal>
                    <h2 class="scene-line" data-parallax-text>Cruze o limiar.</h2>
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
                <div class="scene-content scene-content--center scene-content--bottom reveal-stagger" data-reveal>
                    <h2 class="scene-line" data-parallax-text>
                        <span class="reveal-word" :style="{ '--i': 0 }">Verificado,</span>
                        <span class="reveal-word" :style="{ '--i': 1 }">real</span>
                        <span class="reveal-word" :style="{ '--i': 2 }">e</span>
                        <span class="reveal-word" :style="{ '--i': 3 }">discreto.</span>
                    </h2>
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
                 Mármore LIMPO (fundo.webp — vertical próprio no retrato) como
                 fundo, e o LOGO real da marca (PortalLogo, o mesmo do header) no
                 terço superior/central. A tagline e o ÚNICO CTA ("Entre na lista
                 de espera") ficam ABAIXO, com folga — zero sobreposição. O zoom
                 de saída cresce o LOGO; o mármore fica quieto. -->
            <section class="scene scene--dark scene--invite">
                <div class="scene-media" data-parallax>
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/fundo-mobile.webp'" type="image/webp" />
                        <img
                            class="scene-img"
                            :src="'/landing/fundo.webp'"
                            alt="Mármore escuro com veios dourados."
                            loading="lazy"
                        />
                    </picture>
                </div>
                <!-- Véu de contraste (radial): discreto no desktop, FORTE no retrato,
                     onde o mármore de celular tem veios laranja vivos que poderiam
                     apagar o logo dourado. -->
                <div class="scene-veil scene-veil--invite" aria-hidden="true" />
                <div class="scene-veil scene-veil--bottom" aria-hidden="true" />
                <div class="light-sheen" aria-hidden="true"><span class="light-sheen-band" /></div>
                <div class="scene-content scene-content--invite" data-reveal>
                    <PortalLogo class="invite-logo" data-invite-logo :size="logoSize" />
                    <div class="invite-text">
                        <p class="invite-tagline" data-parallax-text>O portal do desejo, verificado e real.</p>
                        <button type="button" class="invite-cta" @click="scrollToForm()">
                            Entre na lista de espera
                        </button>
                        <p class="invite-note">Lançamento em breve · entrada por convite</p>
                    </div>
                </div>
            </section>

              </div><!-- /.scene-viewport -->
            </div><!-- /.scene-stack -->
        </div>

        <!-- ── Lista de espera (ÚNICO CTA da landing) ────────────────────────
             Mesma identidade da cena 5: mármore (fundo.webp) fortemente escurecido
             atrás do formulário, que fica centralizado e com respiro. -->
        <section id="lista-de-espera" class="wl-section" data-stage>
            <div class="wl-bg" aria-hidden="true">
                <picture>
                    <source media="(max-width: 767px)" :srcset="'/landing/fundo-mobile.webp'" type="image/webp" />
                    <img class="wl-bg-img" :src="'/landing/fundo.webp'" alt="" loading="lazy" />
                </picture>
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
                                Ainda não abrimos. Deixe seu e-mail e o convite chega antes do anúncio.
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
                                            👤 Associado
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition-colors"
                                            :class="form.role === 'performer'
                                                ? 'border-limen-gold bg-limen-gold/10 text-limen-gold'
                                                : 'border-limen-line text-limen-ink-soft hover:border-limen-gold/50'"
                                            @click="selectRole('performer')"
                                        >
                                            🌟 Anfitrião
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

/* ── Palco empilhado ──────────────────────────────────────────────────────────
   FALLBACK (sem JS / reduced-motion): o wrapper e o palco não fazem nada; cada
   cena é uma seção normal de 100svh, empilhada verticalmente — legível e estática.
   MODO EMPILHADO (`.is-stacked`, ligado pelo JS só quando há movimento): o wrapper
   ganha altura de N × 100svh (fixada no JS) e o palco fica STICKY por dentro, então
   as cenas absolutas ocupam o MESMO espaço da tela, sobrepostas. O laço rAF pinta
   opacity + brightness por cena a partir da posição do scroll (cross-dissolve). */
.scene-stack {
    position: relative;
}
.scene-viewport {
    position: relative;
}
.scene-stack.is-stacked .scene-viewport {
    position: sticky;
    top: 0;
    /* svh (estável sob a barra de endereço do Safari) com fallback para vh. */
    height: 100vh;
    height: 100svh;
    overflow: hidden;
}
.scene-stack.is-stacked .scene {
    position: absolute;
    inset: 0;
    min-height: 0;
    height: 100%;
    /* O JS pinta as opacidades; começar em 0 evita o flash das 5 cenas de uma vez. */
    opacity: 0;
    will-change: auto;
}
/* will-change vive SÓ na(s) cena(s) em cena (≤2 durante o cross-fade) — cinco
   camadas full-screen promovidas de uma vez estouram a GPU do celular. */
.scene.is-onstage {
    will-change: opacity;
}
.scene.is-onstage .scene-media,
.scene.is-onstage .split-pane,
.scene.is-onstage img.scene-img,
.scene.is-onstage .invite-logo,
.scene.is-onstage .light-sheen-band {
    will-change: transform;
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

/* Camada de mídia (fundo full-bleed). inset -15% dá folga vertical para o parallax
   nunca revelar borda. O brilho (filter) e o translate do parallax são pintados
   pelo laço rAF; will-change:transform vem do `.is-onstage` acima. */
.scene-media {
    position: absolute;
    inset: -15% 0;
    z-index: 0;
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
/* Cena 5: o mármore de fundo só RESPIRA (escala lenta, sem pan) — movimento bem
   sutil, "a parede quase parada". O zoom de saída (scroll) age no LOGO, não aqui,
   então a respiração por tempo não briga com nada. */
.scene--invite img.scene-img {
    animation-name: ken-burns-breathe;
    animation-duration: 30s;
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
    /* Centragem explícita (além do flex do container): a impressão digital é o
       objeto da cena e tem de ficar no eixo. `object-position: center` fixa o
       recorte interno; `margin: auto` a mantém centrada mesmo se algum ancestral
       perder o flex. */
    object-position: center;
    margin: auto;
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
/* Cena 5: véu radial de CONTRASTE atrás do logo/texto. Discreto no desktop (o
   fundo.webp de paisagem já é escuro e calmo); FORTE no retrato (regra abaixo),
   onde o mármore de celular tem veios laranja vivos que apagariam o logo dourado. */
.scene-veil--invite {
    background: radial-gradient(
        ellipse 82% 72% at 50% 46%,
        rgba(10, 8, 6, 0.4),
        rgba(10, 8, 6, 0.18) 62%,
        transparent 80%
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

.scroll-hint {
    margin-top: 2.5rem;
    font-size: 0.7rem;
    letter-spacing: 0.35em;
    text-transform: uppercase;
    color: var(--color-limen-ink-mute, #8a8175);
    animation: hint-pulse 2.4s ease-in-out 2.6s infinite;
}

/* Cena do convite: LOGO no terço superior/central + tagline/CTA ABAIXO, com folga
   REAL (gap generoso) — zero sobreposição, o objetivo do PR. Container ocupa a cena
   inteira e centraliza a coluna; o logo cresce no zoom de saída (transform via JS),
   o texto fica no tamanho normal e clicável. */
.scene-content--invite {
    position: absolute;
    inset: 0;
    margin-inline: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: clamp(2.25rem, 7svh, 5rem);
    padding: 12svh 1.5rem 12svh;
}
/* O logo cresce a partir do centro (zoom de saída). transform pintado pelo laço rAF. */
.invite-logo {
    transform-origin: center;
}
.invite-text {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.4rem;
}
/* Base mais escura e mais alta na cena 5: garante o "chão" legível sob o texto,
   abaixo do centro visual do wordmark. */
.scene--invite .scene-veil--bottom {
    background: linear-gradient(
        to bottom,
        transparent 34%,
        rgba(10, 8, 6, 0.55) 58%,
        rgba(10, 8, 6, 0.92)
    );
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
.wl-section.is-onstage .wl-bg-img {
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
/* A cascata torna cada palavra um <span> inline-block, e o espaço em branco entre
   spans colapsava — renderizava "Verificado,real" colado. O container das palavras
   (a própria linha) vira flex e o espaçamento passa a ser `gap`, independente do
   whitespace do template. Vale para as duas frases em cascata (cenas 3 e 4). */
.reveal-stagger .scene-line {
    display: flex;
    flex-wrap: wrap;
    gap: 0.28em;
    justify-content: center;
    align-items: baseline;
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

/* Ken Burns — parte SEMPRE de escala ≥ 1.06 (a folga base: 3% de cada lado) e o
   deslocamento máximo é ~metade da folga (≤ 1.5%), então em NENHUM frame a imagem
   descobre a borda — inclusive nos painéis da cena 4, que têm inset 0 (sem folga de
   inset, só a da escala). Direções distintas por cena. Validado por bounding box em
   0%/50%/100%: com base 1.06 a folga por lado (1.5%) supera o pan efetivo (≤1.06×1.5%). */
@keyframes ken-burns-a {
    from { transform: scale(1.06) translate3d(0.9%, 0.9%, 0); }
    to { transform: scale(1.12) translate3d(-1.3%, -1.1%, 0); }
}
@keyframes ken-burns-b {
    from { transform: scale(1.06) translate3d(-1.1%, 0.6%, 0); }
    to { transform: scale(1.11) translate3d(1.4%, -0.8%, 0); }
}
@keyframes ken-burns-c {
    from { transform: scale(1.06) translate3d(1%, -1%, 0); }
    to { transform: scale(1.12) translate3d(-0.9%, 1.3%, 0); }
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
        bottom: 9svh;
    }
}

/* Mobile: fluidez primeiro. O parallax e a derivada do texto já ficam DESLIGADOS
   (JS: só desktop); o Ken Burns fica mais sutil — só respira (escala, sem pan) e
   mais devagar, em toda imagem de cena e no mármore da lista de espera. */
@media (max-width: 767px) {
    /* Casa a especificidade das regras por-cena (com `img`) para de fato vencê-las.
       A cena 5 fica FORA — sua regra própria (0,2,1) já a põe em `ken-burns-breathe`
       a 30s (o mesmo "só respira" que este bloco aplica às demais), e vence a bare
       `img.scene-img` (0,1,1). O zoom de saída dela é por scroll (age no LOGO). */
    img.scene-img,
    .scene--portal img.scene-img,
    .scene--split .split-pane:first-of-type img.scene-img,
    .scene--split .split-pane:nth-of-type(2) img.scene-img {
        animation-name: ken-burns-breathe;
        animation-duration: 30s;
    }
}

/* ── Cena 5 no RETRATO: mármore VERTICAL próprio (fundo-mobile.webp) ──────────
   Agora o retrato tem asset dedicado, vertical, então volta a `object-cover` (o
   `contain` de emergência do wordmark-foto foi removido — não há mais corte de
   letras). Só reforçamos o VÉU de contraste: o mármore de celular tem veios
   laranja/dourados vivos e o logo dourado sumiria em cima deles. */
@media (max-width: 767px), (orientation: portrait) {
    .scene-veil--invite {
        background: radial-gradient(
            ellipse 100% 78% at 50% 44%,
            rgba(10, 8, 6, 0.78),
            rgba(10, 8, 6, 0.52) 52%,
            rgba(10, 8, 6, 0.28) 78%
        );
    }
}

/* Menos movimento: o palco NÃO empilha (o JS não adiciona `.is-stacked` nem liga o
   laço de scroll), então as cenas ficam no fluxo vertical normal, em opacity:1 e
   estáticas. Aqui só matamos o que é CSS por tempo — Ken Burns, véu de luz, animações
   de entrada — e forçamos os reveals (container e palavras) visíveis, já que sob
   reduced-motion o gatilho por scroll não roda. Bloco ÚNICO. `!important` no kill de
   Ken Burns porque as regras por-cena têm especificidade alta — é o override de
   acessibilidade, o lugar onde ele cabe. */
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
