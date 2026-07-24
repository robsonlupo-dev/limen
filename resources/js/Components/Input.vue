<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    id: String,
    type: { type: String, default: 'text' },
    modelValue: [String, Number],
    label: String,
    error: String,
    placeholder: String,
    required: Boolean,
    autocomplete: String,
})
defineEmits(['update:modelValue'])

const isPassword = computed(() => props.type === 'password')
const revealed = ref(false)

// O type do campo só é dinâmico quando há botão de olho; para os demais
// tipos (text, email…) segue exatamente a prop.
const inputType = computed(() =>
    isPassword.value && revealed.value ? 'text' : props.type,
)
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label
            v-if="label"
            :for="id"
            class="text-sm font-medium text-cream"
        >
            {{ label }}
            <span v-if="required" class="text-gold ml-0.5">*</span>
        </label>
        <div class="relative">
            <input
                :id="id"
                :type="inputType"
                :value="modelValue"
                :placeholder="placeholder"
                :required="required"
                :autocomplete="autocomplete"
                :class="[
                    'w-full rounded-lg border bg-surface px-4 py-3 text-sm text-cream placeholder:text-muted',
                    'transition-colors duration-150',
                    'focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold',
                    error ? 'border-danger' : 'border-frame',
                    isPassword ? 'pr-11' : '',
                ]"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <button
                v-if="isPassword"
                type="button"
                tabindex="-1"
                :aria-label="revealed ? 'Ocultar senha' : 'Mostrar senha'"
                :aria-pressed="revealed"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-[#8a8280] transition-colors duration-150 hover:text-[#f3c97e] focus:outline-none focus-visible:text-[#f3c97e]"
                @click="revealed = !revealed"
            >
                <!-- olho aberto: senha oculta, clique para revelar -->
                <svg
                    v-if="!revealed"
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <!-- olho cortado: senha visível, clique para ocultar -->
                <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                    <line x1="3" y1="21" x2="21" y2="3" />
                </svg>
            </button>
        </div>
        <p v-if="error" class="text-xs text-danger">{{ error }}</p>
    </div>
</template>
