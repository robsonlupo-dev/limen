<script setup>
defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: 'md' },
})
defineEmits(['close'])
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <!-- Overlay -->
            <div
                class="absolute inset-0 bg-background/90 backdrop-blur-sm"
                @click="$emit('close')"
            />
            <!-- Panel -->
            <div
                :class="[
                    'relative z-10 w-full rounded-2xl border border-frame bg-surface p-8 shadow-2xl',
                    maxWidth === 'sm' && 'max-w-sm',
                    maxWidth === 'md' && 'max-w-md',
                    maxWidth === 'lg' && 'max-w-lg',
                ]"
            >
                <slot />
            </div>
        </div>
    </Transition>
</template>
