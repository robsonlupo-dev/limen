<script setup>
defineProps({
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
        <input
            :id="id"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :required="required"
            :autocomplete="autocomplete"
            :class="[
                'w-full rounded-lg border bg-surface px-4 py-3 text-sm text-cream placeholder:text-muted',
                'transition-colors duration-150',
                'focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold',
                error ? 'border-danger' : 'border-frame',
            ]"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <p v-if="error" class="text-xs text-danger">{{ error }}</p>
    </div>
</template>
