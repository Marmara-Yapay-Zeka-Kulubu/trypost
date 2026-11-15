<script setup lang="ts">
/**
 * Marmara AI fork hook (app/Marmara/README.md, "Portal"). Upstream's
 * layouts/AuthLayout.vue imports this instead of AuthSplitLayout. With
 * MARMARA_BRANDING on it renders the club's own frame, otherwise upstream's
 * AuthSplitLayout untouched. Props (title, description, status, panel, width)
 * and both slots pass through, so the auth pages keep working unchanged.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import MarmaraAuthLayout from '@/marmara/MarmaraAuthLayout.vue';
import { portalBranding } from '@/marmara/portal';

const props = withDefaults(
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

const page = usePage();

const layout = computed(() =>
    portalBranding(page.props as Record<string, unknown>)
        ? MarmaraAuthLayout
        : AuthSplitLayout,
);
</script>

<template>
    <component :is="layout" v-bind="props">
        <slot />
        <template v-if="$slots.footer" #footer>
            <slot name="footer" />
        </template>
    </component>
</template>
