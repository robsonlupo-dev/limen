<script setup>
import { useForm } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'

const props = defineProps({
    slug: { type: String, required: true },
    following: { type: Boolean, required: true },
    reloadOnly: { type: Array, default: undefined },
    size: { type: String, default: 'md' },
})

const form = useForm({})

function toggle() {
    const options = {
        preserveScroll: true,
        only: props.reloadOnly,
    }

    if (props.following) {
        form.delete(route('catalog.unfollow', props.slug), options)
    } else {
        form.post(route('catalog.follow', props.slug), options)
    }
}
</script>

<template>
    <Button
        :variant="following ? 'ghost' : 'primary'"
        :size="size"
        :loading="form.processing"
        @click="toggle"
    >
        {{ following ? 'Deixar de seguir' : 'Seguir' }}
    </Button>
</template>
