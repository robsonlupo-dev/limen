<script setup>
// Posicionador do oval de rosto borrado (Onda B, v2 — pedido do UAT: arrastar e
// redimensionar em vez de clicar + presets). Interações, todas por pointer events
// (mouse E toque):
//   · arrastar DENTRO do oval  → move;
//   · arrastar pela BORDA      → redimensiona (o oval passa a cruzar o dedo);
//   · tocar FORA               → recentra no ponto;
//   · presets P/M/G            → atalho de tamanho.
// Coordenadas normalizadas 0..1 sobre a imagem exibida; ry = rx × 1,3 (rosto é
// mais alto que largo) — o MESMO contrato que o servidor clampa e queima.
import { computed, ref } from 'vue'

const props = defineProps({
    previewUrl: { type: String, required: true },
    // { cx, cy, rx } normalizados 0..1
    modelValue: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue'])

const RX_MIN = 0.06
const RX_MAX = 0.49
const RATIO = 1.3
const PRESETS = [
    ['P', 0.12],
    ['M', 0.18],
    ['G', 0.26],
]

const region = computed(() => props.modelValue)
const ry = computed(() => Math.min(0.49, region.value.rx * RATIO))

function patch(partial) {
    emit('update:modelValue', { ...region.value, ...partial })
}

const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v))

const ovalStyle = computed(() => ({
    left: `${region.value.cx * 100}%`,
    top: `${region.value.cy * 100}%`,
    width: `${region.value.rx * 200}%`,
    height: `${ry.value * 200}%`,
    transform: 'translate(-50%, -50%)',
}))

// null | { mode: 'move'|'resize', offX, offY }
const drag = ref(null)

function norm(event) {
    const rect = event.currentTarget.getBoundingClientRect()
    if (!rect.width || !rect.height) return null
    return {
        x: clamp((event.clientX - rect.left) / rect.width),
        y: clamp((event.clientY - rect.top) / rect.height),
    }
}

function onDown(event) {
    const p = norm(event)
    if (!p) return
    event.preventDefault()
    event.currentTarget.setPointerCapture?.(event.pointerId)

    const { cx, cy, rx } = region.value
    const dx = (p.x - cx) / rx
    const dy = (p.y - cy) / ry.value
    const d2 = dx * dx + dy * dy

    if (d2 <= 0.65) {
        // Centro: arrasta mantendo o ponto de pega.
        drag.value = { mode: 'move', offX: p.x - cx, offY: p.y - cy }
    } else if (d2 <= 1.6) {
        // Borda (com folga pra dedo): redimensiona.
        drag.value = { mode: 'resize' }
        resizeTo(p)
    } else {
        // Fora: recentra e já segue movendo.
        patch({ cx: p.x, cy: p.y })
        drag.value = { mode: 'move', offX: 0, offY: 0 }
    }
}

function onMove(event) {
    if (!drag.value) return
    const p = norm(event)
    if (!p) return
    event.preventDefault()

    if (drag.value.mode === 'move') {
        patch({ cx: clamp(p.x - drag.value.offX), cy: clamp(p.y - drag.value.offY) })
    } else {
        resizeTo(p)
    }
}

/** Redimensiona para o oval passar pelo ponteiro (pega a borda e puxa). */
function resizeTo(p) {
    const { cx, cy } = region.value
    const dx = p.x - cx
    const dy = (p.y - cy) / RATIO
    patch({ rx: clamp(Math.sqrt(dx * dx + dy * dy), RX_MIN, RX_MAX) })
}

function onUp(event) {
    drag.value = null
    event.currentTarget.releasePointerCapture?.(event.pointerId)
}
</script>

<template>
    <div class="space-y-3 rounded-lg border border-gold/30 bg-gold/5 p-3">
        <p class="text-xs text-muted">
            Arraste o oval até o rosto. Puxe pela <span class="text-cream">borda</span> para
            aumentar ou diminuir; toque fora para reposicionar.
        </p>
        <div
            class="relative inline-block max-w-xs select-none overflow-hidden rounded-lg"
            style="touch-action: none"
            @pointerdown="onDown"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="onUp"
        >
            <img :src="previewUrl" alt="Posicionar o borrão" class="block w-full" draggable="false" />
            <div
                class="pointer-events-none absolute rounded-full border-2 border-gold/80 bg-background/30 backdrop-blur-md"
                :style="ovalStyle"
            >
                <!-- Alça visual de redimensionar (a pega real é a borda inteira). -->
                <span
                    class="absolute left-1/2 top-full h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border border-background/60 bg-gold"
                />
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-muted">Tamanho:</span>
            <button
                v-for="[label, rx] in PRESETS"
                :key="label"
                type="button"
                class="rounded-md border px-2.5 py-1 text-xs transition-colors"
                :class="Math.abs(region.rx - rx) < 0.015 ? 'border-gold text-gold bg-gold/10' : 'border-frame text-muted hover:text-cream'"
                @click="patch({ rx })"
            >{{ label }}</button>
        </div>
    </div>
</template>
