<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getInitials } from '@/composables/useInitials';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type {
    MemberPostStats,
    MemberPostStatsRow,
} from '@/marmara/members/types';
import type { DateRange } from '@/types/analytics';

/**
 * Marmara AI fork: "Posts by member" on the Insights page (app/Marmara/README.md,
 * Members). Read from GET /insights/members for the page's period and channels.
 */
const ENDPOINT = '/insights/members';

const props = defineProps<{
    range: DateRange;
    channels: string[];
}>();

type CountKey =
    | 'published'
    | 'scheduled'
    | 'pending_approval'
    | 'drafts'
    | 'failed';

const COLUMNS: CountKey[] = [
    'published',
    'scheduled',
    'pending_approval',
    'drafts',
    'failed',
];

const rows = ref<MemberPostStatsRow[]>([]);
const loading = ref(true);
const failed = ref(false);
let request = 0;

const load = async (): Promise<void> => {
    const current = ++request;
    const query = new URLSearchParams({
        start: props.range.start,
        end: props.range.end,
    });

    props.channels.forEach((id) => query.append('channels[]', id));

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`${ENDPOINT}?${query.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = (await response.json()) as MemberPostStats;

        if (current === request) {
            rows.value = data.members;
        }
    } catch {
        if (current === request) {
            failed.value = true;
        }
    } finally {
        if (current === request) {
            loading.value = false;
        }
    }
};

watch(
    () => [props.range.start, props.range.end, props.channels.join(',')],
    load,
    { immediate: true },
);

/** Members with at least one post in the period; the others stay out of the table. */
const activeRows = computed((): MemberPostStatsRow[] =>
    rows.value.filter((row) => COLUMNS.some((column) => row[column] > 0)),
);

const platformsOf = (row: MemberPostStatsRow): [string, number][] =>
    Object.entries(row.platforms);
</script>

<template>
    <AnalyticsSection
        :title="$t('posts.members.stats.title')"
        :info="$t('posts.members.stats.description')"
        info-testid="member-posts-about"
        :range="range"
        flush
    >
        <div
            v-if="failed || (!loading && activeRows.length === 0)"
            class="px-4 pb-4 sm:px-5 sm:pb-5"
        >
            <div
                data-testid="member-posts-empty"
                class="rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground"
            >
                {{
                    $t(
                        failed
                            ? 'posts.members.stats.load_failed'
                            : 'posts.members.stats.empty',
                    )
                }}
            </div>
        </div>
        <div
            v-else
            class="min-w-0 border-t border-border"
            :class="{ 'opacity-60': loading }"
            data-testid="member-posts"
        >
            <div class="min-w-0 overflow-x-auto">
                <Table class="min-w-[720px]">
                    <TableHeader>
                        <TableRow
                            class="border-border bg-muted/60 hover:bg-muted/60"
                        >
                            <TableHead
                                class="h-10 px-4 py-2 text-xs font-medium text-muted-foreground sm:ps-5"
                            >
                                {{ $t('posts.members.stats.member') }}
                            </TableHead>
                            <TableHead
                                v-for="column in COLUMNS"
                                :key="column"
                                class="h-10 px-4 py-2 text-end text-xs font-medium text-muted-foreground"
                            >
                                {{ $t(`posts.members.stats.${column}`) }}
                            </TableHead>
                            <TableHead
                                class="h-10 px-4 py-2 text-xs font-medium text-muted-foreground sm:pe-5"
                            >
                                {{ $t('posts.members.stats.platforms') }}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in activeRows"
                            :key="row.user.id"
                            class="border-border"
                            :data-testid="`member-posts-row-${row.user.id}`"
                        >
                            <TableCell class="px-4 py-3 sm:ps-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <Avatar class="size-7 shrink-0">
                                        <AvatarImage
                                            v-if="row.user.photo_url"
                                            :src="row.user.photo_url"
                                            :alt="row.user.name"
                                        />
                                        <AvatarFallback class="text-[11px]">
                                            {{ getInitials(row.user.name) }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div class="flex min-w-0 flex-col">
                                        <span
                                            class="truncate text-sm font-medium text-foreground"
                                            >{{ row.user.name }}</span
                                        >
                                        <span
                                            v-if="row.via_whatsapp > 0"
                                            class="truncate text-xs text-muted-foreground"
                                            :data-testid="`member-posts-whatsapp-${row.user.id}`"
                                            >{{
                                                $t(
                                                    'posts.members.stats.via_whatsapp',
                                                    {
                                                        count: String(
                                                            row.via_whatsapp,
                                                        ),
                                                    },
                                                )
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell
                                v-for="column in COLUMNS"
                                :key="column"
                                class="px-4 py-3 text-end text-sm tabular-nums"
                                :class="
                                    row[column] > 0
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                                :data-testid="`member-posts-${column}-${row.user.id}`"
                            >
                                {{ row[column] }}
                            </TableCell>
                            <TableCell class="px-4 py-3 sm:pe-5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-for="[platform, count] in platformsOf(
                                            row,
                                        )"
                                        :key="platform"
                                        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                        :title="getPlatformLabel(platform)"
                                    >
                                        <PlatformBrandIcon
                                            :platform="platform"
                                        />
                                        {{ count }}
                                    </span>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AnalyticsSection>
</template>
