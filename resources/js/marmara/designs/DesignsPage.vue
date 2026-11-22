<script setup lang="ts">
/**
 * Marmara AI fork: the "Tasarımlar" gallery (app/Marmara/README.md, Designs).
 * Copy comes from the `copy` prop (the module's own en/tr lang files), URLs
 * from `urls` (with __ID__ for the design). No prop shares a name with a
 * shared prop (see DesignsPageController::PAGE_PROPS).
 *
 * The layout follows TryPost's own list pages: HeaderTitle with the actions
 * on the right of the app header (as Templates), a toolbar row under a
 * border-strong line (as Publish and Insights) with ghost dropdown filters
 * (DesignsFilterSelect, as CalendarStatusFilter / MultiSelectFilter), the
 * shared DateRangePicker (as Insights) and a search input (as
 * TemplateSearch), and EmptyState for an empty gallery.
 */
import { Head, router } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowsSort,
    IconCalendar,
    IconChevronDown,
    IconDotsVertical,
    IconFileText,
    IconFileTypePdf,
    IconFolder,
    IconLayersSubtract,
    IconMessageCircle,
    IconPhoto,
    IconSearch,
    IconSend,
    IconTag,
    IconThumbUp,
    IconUnlink,
    IconUpload,
    IconUser,
    IconVideo,
    IconX,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, watch, type Component } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import date from '@/date';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';

import CreatePostDialog from './CreatePostDialog.vue';
import DesignDrawer from './DesignDrawer.vue';
import DesignsFilterSelect from './DesignsFilterSelect.vue';
import DesignStatusBadge from './DesignStatusBadge.vue';
import DesignUploadDialog from './DesignUploadDialog.vue';
import DriveUploadPanel from './DriveUploadPanel.vue';
import {
    fill,
    formatDateTime,
    makeTranslator,
    type Copy,
    type DesignItem,
    type DriveUpload,
    type FilterOption,
    type LinkCandidate,
    type PostChannel,
    type SelectedDesign,
} from './types';

type FilterKey = 'q' | 'folder' | 'uploader' | 'status' | 'tag' | 'kind' | 'from' | 'to' | 'sort';
type Filters = Partial<Record<FilterKey, string>>;

const props = defineProps<{
    copy: Copy;
    filters: Filters;
    filterOptions: {
        folders: string[];
        uploaders: string[];
        tags: string[];
        statuses: string[];
        kinds: string[];
        sorts: string[];
    };
    designs: DesignItem[];
    pagination: { page: number; has_more: boolean; per_page: number };
    selected: SelectedDesign | null;
    permissions: { canApprove: boolean; canCreatePost: boolean; canManageDrive: boolean };
    driveUpload: DriveUpload;
    syncInfo: { configured: boolean; status: string | null; detail: string | null; last_synced_at: string | null };
    postChannels: PostChannel[];
    linkCandidates: LinkCandidate[];
    urls: Record<string, string>;
}>();

const t = makeTranslator(() => props.copy);

const visit = (params: Record<string, string | number | null>, options: { keepPage?: boolean } = {}): void => {
    const query = Object.fromEntries(
        Object.entries({
            ...props.filters,
            ...(options.keepPage ? { page: props.pagination.page } : {}),
            design: props.selected?.id ?? null,
            ...params,
        }).filter(([, value]) => value !== null && value !== '' && value !== undefined),
    );

    router.get(props.urls.index, query, { preserveState: true, preserveScroll: true });
};

// Search: debounced like TemplateSearch, and only when it differs from the URL.
const search = ref(props.filters.q ?? '');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (value.trim() !== (props.filters.q ?? '')) {
            visit({ q: value.trim() || null, page: null, design: null });
        }
    }, 350);
});

const hasFilters = computed(() =>
    (['q', 'folder', 'uploader', 'status', 'tag', 'kind', 'from', 'to'] as FilterKey[]).some((key) => Boolean(props.filters[key])),
);

const clearFilters = (): void => {
    search.value = '';
    dateArmed.value = false;
    router.get(props.urls.index, props.filters.sort ? { sort: props.filters.sort } : {}, { preserveState: true });
};

const select = (key: FilterKey) => (value: string) => visit({ [key]: value || null, page: null, design: null });

const folderOptions = computed<FilterOption[]>(() => [
    { value: '/', label: t('filters.root_folder') },
    ...props.filterOptions.folders.map((folder) => ({ value: folder, label: folder })),
]);
const uploaderOptions = computed<FilterOption[]>(() => props.filterOptions.uploaders.map((name) => ({ value: name, label: name })));
const tagOptions = computed<FilterOption[]>(() => props.filterOptions.tags.map((tag) => ({ value: tag, label: `#${tag}` })));
const statusOptions = computed<FilterOption[]>(() => props.filterOptions.statuses.map((status) => ({ value: status, label: t(`statuses.${status}`) })));
const kindOptions = computed<FilterOption[]>(() => props.filterOptions.kinds.map((kind) => ({ value: kind, label: t(`kinds.${kind}`) })));
const sortOptions = computed<FilterOption[]>(() => props.filterOptions.sorts.map((sort) => ({ value: sort, label: t(`sorts.${sort}`) })));

// Date range: the shared DateRangePicker (as Insights) writes `from` / `to`.
// It echoes its model back whenever the model changes, so only a choice made
// while it is open (armed) visits, and only when it differs from the URL.
const today = new Date();
const dateOpen = ref(false);
const dateArmed = ref(false);

watch(dateOpen, (isOpen) => {
    if (isOpen) {
        dateArmed.value = true;

        return;
    }

    // A preset closes the picker in the same tick as it picks: let that emit through first.
    void nextTick(() => {
        dateArmed.value = false;
    });
});

const hasDateRange = computed(() => Boolean(props.filters.from || props.filters.to));

const pickerRange = computed(() => {
    if (!hasDateRange.value) {
        return { start: dayjs().subtract(29, 'day').toDate(), end: dayjs().toDate() };
    }

    const from = props.filters.from ?? props.filters.to;
    const to = props.filters.to ?? props.filters.from;

    return { start: dayjs(from).toDate(), end: dayjs(to).toDate() };
});

const dateLabel = computed(() => {
    if (!hasDateRange.value) {
        return t('filters.date');
    }

    const from = props.filters.from ? date.formatDayMonthYear(props.filters.from) : '…';
    const to = props.filters.to ? date.formatDayMonthYear(props.filters.to) : '…';

    return `${from} – ${to}`;
});

const changeRange = (range: { start: Date; end: Date }): void => {
    const from = dayjs(range.start).format('YYYY-MM-DD');
    const to = dayjs(range.end).format('YYYY-MM-DD');

    if (!dateArmed.value || (from === (props.filters.from ?? '') && to === (props.filters.to ?? ''))) {
        return;
    }

    visit({ from, to, page: null, design: null });
};

const clearDateRange = (): void => visit({ from: null, to: null, page: null, design: null });

const open = (design: DesignItem): void => visit({ design: design.id }, { keepPage: true });
const close = (): void => visit({ design: null }, { keepPage: true });

const kindIcons: Record<string, Component> = { pdf: IconFileTypePdf, video: IconVideo };

const uploadOpen = ref(false);
const postOpen = ref(false);
const highlightPick = computed(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('drive') === 'pick');

// Upload UI (DesignsPageController::driveUpload): nothing while uploads are
// off; admins get a short notice for the missing step; "Yükle" once ready.
const showDriveNotice = computed(
    () => props.driveUpload.can_manage && (props.driveUpload.state === 'needs_connection' || props.driveUpload.state === 'needs_folder'),
);
const showDriveMenu = computed(
    () => props.driveUpload.can_manage && (props.driveUpload.state === 'ready' || props.driveUpload.state === 'needs_folder'),
);

const disconnectDrive = (): void => {
    router.delete(props.urls.drive_disconnect, { preserveScroll: true });
};

// Sync problems are setup work: only admins see them.
const syncNotice = computed((): string | null => {
    if (!props.permissions.canManageDrive) {
        return null;
    }

    if (!props.syncInfo.configured) {
        return t('gallery.not_configured');
    }

    return props.syncInfo.detail ? t('gallery.sync_problem', { detail: props.syncInfo.detail }) : null;
});
</script>

<template>
    <Head :title="t('title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="t('title')" :icon="IconPhoto" />
        </template>

        <template v-if="driveUpload.can_upload || showDriveMenu" #header-actions>
            <Button
                v-if="driveUpload.can_upload"
                variant="outline"
                class="max-sm:w-8 max-sm:px-0"
                :aria-label="t('upload.button')"
                data-testid="marmara-designs-upload"
                @click="uploadOpen = true"
            >
                <IconUpload class="size-4" />
                <span class="max-sm:sr-only">{{ t('upload.button') }}</span>
            </Button>
            <DropdownMenu v-if="showDriveMenu">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="shrink-0 data-[state=open]:bg-accent"
                        :aria-label="t('drive.menu')"
                        data-testid="marmara-designs-drive-menu"
                    >
                        <IconDotsVertical class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-64">
                    <DropdownMenuLabel class="flex flex-col gap-0.5">
                        <span>{{ t('drive.menu') }}</span>
                        <span v-if="driveUpload.email" class="truncate text-xs font-normal text-muted-foreground">
                            {{ t('drive.connected_as', { email: driveUpload.email }) }}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuItem data-testid="marmara-designs-drive-disconnect" @select="disconnectDrive">
                        <IconUnlink class="size-4" />
                        {{ t('drive.disconnect') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <div class="flex min-w-0 flex-1 flex-col" data-testid="marmara-designs">
            <!-- Toolbar: search, filters, date range and sort, like Publish and Insights -->
            <div
                class="mx-4 mt-2 flex min-h-12 min-w-0 flex-wrap items-center gap-2 border-b border-border-strong py-2 md:mx-8"
                data-testid="marmara-designs-filters"
            >
                <div class="relative w-full sm:w-56">
                    <IconSearch class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="search"
                        type="text"
                        class="h-8 ps-8 pe-8"
                        :placeholder="t('filters.search')"
                        :aria-label="t('filters.search')"
                        data-testid="marmara-designs-search"
                        @keydown.esc="search = ''"
                    />
                    <button
                        v-if="search !== ''"
                        type="button"
                        class="absolute end-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
                        :aria-label="t('filters.clear_search')"
                        data-testid="marmara-designs-search-clear"
                        @click="search = ''"
                    >
                        <IconX class="size-4" />
                    </button>
                </div>

                <DesignsFilterSelect
                    :model-value="filters.folder ?? ''"
                    :options="folderOptions"
                    :label="t('filters.folder')"
                    :all-label="t('filters.all_folders')"
                    :icon="IconFolder"
                    searchable
                    :search-placeholder="t('filters.search_options')"
                    :empty-text="t('filters.no_options')"
                    test-id="marmara-designs-folder"
                    @update:model-value="select(\'folder\')($event)"
                />
                <DesignsFilterSelect
                    :model-value="filters.uploader ?? ''"
                    :options="uploaderOptions"
                    :label="t('filters.uploader')"
                    :all-label="t('filters.all_uploaders')"
                    :icon="IconUser"
                    searchable
                    :search-placeholder="t('filters.search_options')"
                    :empty-text="t('filters.no_options')"
                    test-id="marmara-designs-uploader"
                    @update:model-value="select(\'uploader\')($event)"
                />
                <DesignsFilterSelect
                    :model-value="filters.status ?? ''"
                    :options="statusOptions"
                    :label="t('filters.status')"
                    :all-label="t('filters.all_statuses')"
                    :icon="IconLayersSubtract"
                    test-id="marmara-designs-status-filter"
                    @update:model-value="select(\'status\')($event)"
                />
                <DesignsFilterSelect
                    v-if="filterOptions.tags.length > 0"
                    :model-value="filters.tag ?? ''"
                    :options="tagOptions"
                    :label="t('filters.tag')"
                    :all-label="t('filters.all_tags')"
                    :icon="IconTag"
                    searchable
                    :search-placeholder="t('filters.search_options')"
                    :empty-text="t('filters.no_options')"
                    test-id="marmara-designs-tag"
                    @update:model-value="select(\'tag\')($event)"
                />
                <DesignsFilterSelect
                    :model-value="filters.kind ?? ''"
                    :options="kindOptions"
                    :label="t('filters.kind')"
                    :all-label="t('filters.all_kinds')"
                    :icon="IconFileText"
                    test-id="marmara-designs-kind"
                    @update:model-value="select(\'kind\')($event)"
                />

                <div class="flex shrink-0 items-center">
                    <DateRangePicker
                        v-model:open="dateOpen"
                        :model-value="pickerRange"
                        :max-date="today"
                        align="start"
                        sheet-below-sm
                        @update:model-value="changeRange"
                    >
                        <template #trigger>
                            <Button
                                type="button"
                                variant="ghost"
                                class="max-w-64 shrink-0 data-[state=open]:bg-accent max-sm:border max-sm:border-border-strong"
                                :class="{ 'bg-accent': hasDateRange }"
                                :aria-label="hasDateRange ? `${t('filters.date')}: ${dateLabel}` : t('filters.date')"
                                data-testid="marmara-designs-date"
                            >
                                <IconCalendar class="size-4 shrink-0 text-muted-foreground" />
                                <span class="truncate max-sm:hidden">{{ dateLabel }}</span>
                                <IconChevronDown class="size-4 shrink-0 text-muted-foreground" />
                            </Button>
                        </template>
                    </DateRangePicker>
                    <Button
                        v-if="hasDateRange"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground"
                        :aria-label="`${t('filters.date')} ×`"
                        data-testid="marmara-designs-date-clear"
                        @click="clearDateRange"
                    >
                        <IconX class="size-4" />
                    </Button>
                </div>

                <DesignsFilterSelect
                    :model-value="filters.sort ?? 'newest'"
                    :options="sortOptions"
                    :label="t('filters.sort')"
                    :icon="IconArrowsSort"
                    test-id="marmara-designs-sort"
                    @update:model-value="select(\'sort\')($event)"
                />

                <Button v-if="hasFilters" variant="ghost" class="text-muted-foreground" data-testid="marmara-designs-clear" @click="clearFilters">
                    {{ t('filters.clear') }}
                </Button>

                <span v-if="syncInfo.last_synced_at" class="ms-auto text-xs text-muted-foreground max-md:hidden" data-testid="marmara-designs-last-sync">
                    {{ t('gallery.last_sync', { time: formatDateTime(syncInfo.last_synced_at) }) }}
                </span>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-4 px-4 pt-4 pb-10 md:px-8">
                <div
                    v-if="syncNotice"
                    class="flex items-start gap-2 rounded-lg bg-info-subtle px-4 py-3 text-sm text-foreground"
                    role="status"
                    data-testid="marmara-designs-sync-problem"
                >
                    <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-info-text" aria-hidden="true" />
                    <span>{{ syncNotice }}</span>
                </div>

                <DriveUploadPanel v-if="showDriveNotice" :t="t" :drive="driveUpload" :urls="urls" :highlight-pick="highlightPick" />

                <!-- Gallery -->
                <div v-if="designs.length === 0" class="flex flex-1 flex-col" data-testid="marmara-designs-empty">
                    <EmptyState
                        v-if="hasFilters"
                        :icon="IconSearch"
                        :title="t('gallery.no_results_title')"
                        :description="t('gallery.no_results')"
                    >
                        <template #action>
                            <Button variant="outline" @click="clearFilters">{{ t('filters.clear') }}</Button>
                        </template>
                    </EmptyState>
                    <EmptyState v-else :icon="IconPhoto" :title="t('gallery.empty_title')" :description="t('gallery.empty_body')" />
                </div>

                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" data-testid="marmara-designs-grid">
                    <button
                        v-for="design in designs"
                        :key="design.id"
                        type="button"
                        class="group flex min-w-0 cursor-pointer flex-col overflow-hidden rounded-xl border bg-card text-start transition-[border-color,box-shadow] duration-150 hover:border-border-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :class="selected?.id === design.id ? 'border-primary' : 'border-border'"
                        :data-testid="`marmara-designs-card-${design.id}`"
                        @click="open(design)"
                    >
                        <span class="relative flex aspect-square items-center justify-center overflow-hidden bg-muted">
                            <img v-if="design.thumbnail_url" :src="design.thumbnail_url" :alt="design.title" loading="lazy" class="h-full w-full object-cover" />
                            <component :is="kindIcons[design.kind] ?? IconPhoto" v-else class="size-8 text-muted-foreground" stroke-width="1.5" />
                            <DesignStatusBadge :status="design.status" :label="t(`statuses.${design.status}`)" compact class="absolute top-2 start-2" />
                            <Badge
                                v-if="(design.versions_count ?? 1) > 1"
                                variant="secondary"
                                class="absolute top-2 end-2 h-5 px-1.5"
                            >
                                {{ t('gallery.versions', { count: design.versions_count ?? 1 }) }}
                            </Badge>
                            <Badge
                                v-if="design.status === 'in_review' && design.status_reason === 'content_changed'"
                                variant="warning"
                                class="absolute start-2 bottom-2 h-5 gap-1 px-1.5"
                                :data-testid="`marmara-designs-content-changed-${design.id}`"
                            >
                                <IconAlertTriangle aria-hidden="true" />
                                {{ t('gallery.content_changed') }}
                            </Badge>
                            <Badge
                                v-else-if="design.status === 'used' && design.changed_after_use_at"
                                variant="warning"
                                class="absolute start-2 bottom-2 h-5 gap-1 px-1.5"
                            >
                                <IconAlertTriangle aria-hidden="true" />
                                {{ t('gallery.changed_after_use') }}
                            </Badge>
                        </span>
                        <span class="flex min-w-0 flex-col gap-1 p-3">
                            <span class="truncate text-sm font-medium text-foreground">{{ design.title }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{ design.folder || t('filters.root_folder') }}</span>
                            <span class="flex items-center gap-2 text-xs text-muted-foreground">
                                <span class="truncate">{{ design.uploader.name }}</span>
                                <span
                                    class="ms-auto inline-flex shrink-0 items-center gap-0.5"
                                    :class="design.voted ? 'text-primary-text' : ''"
                                    :aria-label="t('votes.count', { count: design.votes })"
                                >
                                    <IconThumbUp class="size-3.5" aria-hidden="true" />{{ design.votes }}
                                </span>
                                <span class="inline-flex shrink-0 items-center gap-0.5">
                                    <IconMessageCircle class="size-3.5" aria-hidden="true" />{{ design.comments }}
                                </span>
                            </span>
                        </span>
                    </button>
                </div>

                <Button v-if="pagination.has_more" variant="outline" class="self-center" data-testid="marmara-designs-more" @click="visit({ page: pagination.page + 1 })">
                    {{ t('gallery.load_more') }}
                </Button>
            </div>
        </div>

        <DesignDrawer
            v-if="selected"
            :t="t"
            :design="selected"
            :link-candidates="linkCandidates"
            :can-approve="permissions.canApprove"
            :urls="urls"
            @close="close"
        >
            <template #actions>
                <Button
                    v-if="selected.can.create_post"
                    data-testid="marmara-designs-create-post"
                    @click="postOpen = true"
                >
                    <IconSend class="size-4" />
                    {{ t('post.create') }}
                </Button>
            </template>
        </DesignDrawer>

        <CreatePostDialog
            v-if="selected"
            v-model:open="postOpen"
            :t="t"
            :channels="postChannels"
            :url="fill(urls.posts ?? '', selected.id)"
        />

        <DesignUploadDialog
            v-if="driveUpload.can_upload"
            v-model:open="uploadOpen"
            :t="t"
            :drive="driveUpload"
            :folders="filterOptions.folders"
            :url="urls.upload"
            :default-folder="filters.folder && filters.folder !== '/' ? filters.folder : null"
        />
    </AppLayout>
</template>
