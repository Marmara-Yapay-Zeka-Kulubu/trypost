<script setup lang="ts">
import AuthLayout from '@/marmara/AuthLayoutSwitch.vue'; // Marmara AI fork hook: upstream is '@/layouts/auth/AuthSplitLayout.vue'

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        status?: string;
        panel?: boolean;
        width?: 'sm' | 'md';
    }>(),
    {
        title: undefined,
        description: undefined,
        status: undefined,
        panel: false,
        width: 'sm',
    },
);
</script>

<template>
    <AuthLayout
        :title="title"
        :description="description"
        :status="status"
        :panel="panel"
        :width="width"
    >
        <slot />
        <template v-if="$slots.footer" #footer>
            <slot name="footer" />
        </template>
    </AuthLayout>
</template>
