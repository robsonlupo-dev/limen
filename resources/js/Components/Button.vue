<script setup>
defineProps({
    variant: { type: String, default: 'primary' }, // 'primary' | 'ghost' | 'danger'
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
})
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center font-sans font-medium tracking-wide transition-all duration-200 rounded-lg focus-visible:outline-2 focus-visible:outline-gold focus-visible:outline-offset-2',
            size === 'sm' && 'px-4 py-2 text-sm',
            size === 'md' && 'px-6 py-3 text-sm',
            size === 'lg' && 'px-8 py-4 text-base',
            variant === 'primary' && 'bg-gold text-background hover:bg-gold-light disabled:opacity-50 disabled:cursor-not-allowed',
            variant === 'ghost' && 'border border-gold text-gold hover:bg-gold/10 disabled:opacity-50 disabled:cursor-not-allowed',
            variant === 'danger' && 'bg-danger text-cream hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed',
        ]"
    >
        <span v-if="loading" class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
        <slot />
    </button>
</template>
