<script setup lang="ts">
import { IconUser } from '@tabler/icons-vue';
import { computed } from 'vue';

import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import type { WorkspaceMemberOption } from '@/marmara/members/types';

/**
 * Marmara AI fork: the Publish page's "Created by" filter (app/Marmara/README.md, Members).
 * A post Hermes created for a WhatsApp sender is credited to the sender.
 */
const props = withDefaults(
    defineProps<{
        members: WorkspaceMemberOption[];
        testId?: string;
        sheetBelowSm?: boolean;
    }>(),
    { testId: 'posts-creator', sheetBelowSm: false },
);
const selectedIds = defineModel<string[]>({ required: true });

const options = computed(() =>
    props.members.map((member) => ({ id: member.id, label: member.name })),
);

const membersById = computed(
    () => new Map(props.members.map((member) => [member.id, member])),
);

const photoFor = (id: string): string | undefined =>
    membersById.value.get(id)?.photo_url ?? undefined;
</script>

<template>
    <MultiSelectFilter
        v-model="selectedIds"
        :options="options"
        :label="$t('posts.members.filter')"
        :search-placeholder="$t('posts.members.search_placeholder')"
        :empty-message="$t('posts.members.no_members')"
        :select-all-label="$t('posts.composer.select_all')"
        :deselect-all-label="$t('posts.composer.deselect_all')"
        :test-id="testId"
        content-class="w-72"
        icon-only-on-mobile
        :sheet-below-sm="sheetBelowSm"
    >
        <template #icon>
            <IconUser class="size-4" />
        </template>
        <template #option="{ option }">
            <span class="flex min-w-0 items-center gap-3">
                <Avatar class="size-6 shrink-0">
                    <AvatarImage
                        v-if="photoFor(option.id)"
                        :src="photoFor(option.id)!"
                        :alt="option.label"
                    />
                    <AvatarFallback class="text-[10px]">
                        {{ getInitials(option.label) }}
                    </AvatarFallback>
                </Avatar>
                <span class="min-w-0 truncate">{{ option.label }}</span>
            </span>
        </template>
    </MultiSelectFilter>
</template>
