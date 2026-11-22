<script setup lang="ts">
/**
 * Marmara AI fork: a design's status as a badge, built like TryPost's
 * PostStatusBadge (a Badge variant and an icon per status, `compact` for a
 * dense card).
 */
import {
    IconArchive,
    IconCircleCheck,
    IconEdit,
    IconHourglass,
    IconSend,
} from '@tabler/icons-vue';
import { computed, type Component } from 'vue';

import { Badge, type BadgeVariants } from '@/components/ui/badge';

import type { DesignStatus } from './types';

const props = withDefaults(
    defineProps<{
        status: DesignStatus;
        label: string;
        compact?: boolean;
    }>(),
    { compact: false },
);

const CONFIGS: Record<
    DesignStatus,
    { variant: NonNullable<BadgeVariants['variant']>; icon: Component }
> = {
    draft: { variant: 'info', icon: IconEdit },
    in_review: { variant: 'warning', icon: IconHourglass },
    approved: { variant: 'success', icon: IconCircleCheck },
    used: { variant: 'default', icon: IconSend },
    archived: { variant: 'secondary', icon: IconArchive },
};

const config = computed(() => CONFIGS[props.status] ?? CONFIGS.draft);
</script>

<template>
    <Badge
        :variant="config.variant"
        :class="
            compact
                ? 'h-5 gap-1 px-1.5 [&>svg]:size-3.5'
                : 'h-6 gap-1 px-2 [&>svg]:size-4'
        "
        :data-status="status"
    >
        <component :is="config.icon" aria-hidden="true" />
        {{ label }}
    </Badge>
</template>
