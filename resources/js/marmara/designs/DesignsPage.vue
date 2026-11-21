<script setup lang="ts">
/**
 * Marmara AI fork: the "Tasarımlar" gallery (app/Marmara/README.md, Designs).
 * Copy comes from the `copy` prop (the module's own en/tr lang files), URLs
 * from `urls` (with __ID__ for the design). No prop shares a name with a
 * shared prop (see DesignsPageController::PAGE_PROPS).
 */
import { Head, router } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconFileTypePdf,
    IconMessageCircle,
    IconPhoto,
    IconSearch,
    IconSend,
    IconThumbUp,
    IconUpload,
    IconVideo,
} from '@tabler/icons-vue';
import { computed, ref, watch, type Component } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import AppLayout from '@/layouts/AppLayout.vue';

import CreatePostDialog from './CreatePostDialog.vue';
import DesignDrawer from './DesignDrawer.vue';
import DesignUploadDialog from './DesignUploadDialog.vue';
import DriveUploadPanel from './DriveUploadPanel.vue';
import {
    fill,
    formatDateTime,
    makeTranslator,
    type Copy,
    type DesignItem,
    type DriveUpload,
    type LinkCandidate,
    type PostChannel,
    type SelectedDesign,
} from './types';

type Filters = Partial<Record<'q' | 'folder' | 'uploader' | 'status' | 'tag' | 'kind' | 'from' | 'to' | 'sort', string>>;

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

const search = ref(props.filters.q ?? '');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit({ q: value.trim() || null, page: null, design: null }), 350);
});

const hasFilters = computed(() =>
    ['q', 'folder', 'uploader', 'status', 'tag', 'kind', 'from', 'to'].some((key) => Boolean(props.filters[key as keyof Filters])),
);

const clearFilters = (): void => {
    search.value = '';
    router.get(props.urls.index, props.filters.sort ? { sort: props.filters.sort } : {}, { preserveState: true });
};

const open = (design: DesignItem): void => visit({ design: design.id }, { keepPage: true });
const close = (): void => visit({ design: null }, { keepPage: true });

const kindIcons: Record<string, Component> = { pdf: IconFileTypePdf, video: IconVideo };

const uploadOpen = ref(false);
const postOpen = ref(false);
const highlightPick = computed(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('drive') === 'pick');

const select = (key: keyof Filters) => (value: unknown) => visit({ [key]: String(value ?? '') || null, page: null, design: null });
</script>

<template>
    <Head :title="t('title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="t('title')" :icon="IconPhoto" />
        </template>

        <div class="flex flex-col gap-4 p-4 md:p-6" data-testid="marmara-designs">
            <div class="flex flex-wrap items-start gap-2">
                <p class="flex-1 text-sm text-muted-foreground">{{ t('description') }}</p>
                <Button v-if="permissions.canCreatePost" variant="outline" data-testid="marmara-designs-upload" @click="uploadOpen = true">
                    <IconUpload class="size-4" />
                    {{ t('upload.button') }}
                </Button>
            </div>

            <div
                v-if="!syncInfo.configured || syncInfo.detail"
                class="flex items-start gap-2 rounded-lg border border-border-strong bg-card p-3 text-sm"
                data-testid="marmara-designs-sync-problem"
            >
                <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <span>{{ !syncInfo.configured ? t('gallery.not_configured') : t('gallery.sync_problem', { detail: syncInfo.detail ?? '' }) }}</span>
            </div>

            <DriveUploadPanel v-if="permissions.canManageDrive" :t="t" :drive="driveUpload" :urls="urls" :highlight-pick="highlightPick" />

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2" data-testid="marmara-designs-filters">
                <div class="relative w-full sm:w-56">
                    <IconSearch class="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" class="pl-7" :placeholder="t('filters.search')" :aria-label="t('filters.search')" data-testid="marmara-designs-search" />
                </div>

                <NativeSelect :model-value="filters.folder ?? ''" :aria-label="t('filters.folder')" class="h-8 py-1" data-testid="marmara-designs-folder" @update:model-value="select('folder')($event)">
                    <NativeSelectOption value="">{{ t('filters.all_folders') }}</NativeSelectOption>
                    <NativeSelectOption value="/">{{ t('filters.root_folder') }}</NativeSelectOption>
                    <NativeSelectOption v-for="folder in filterOptions.folders" :key="folder" :value="folder">{{ folder }}</NativeSelectOption>
                </NativeSelect>

                <NativeSelect :model-value="filters.uploader ?? ''" :aria-label="t('filters.uploader')" class="h-8 py-1" data-testid="marmara-designs-uploader" @update:model-value="select('uploader')($event)">
                    <NativeSelectOption value="">{{ t('filters.all_uploaders') }}</NativeSelectOption>
                    <NativeSelectOption v-for="uploader in filterOptions.uploaders" :key="uploader" :value="uploader">{{ uploader }}</NativeSelectOption>
                </NativeSelect>

                <NativeSelect :model-value="filters.status ?? ''" :aria-label="t('filters.status')" class="h-8 py-1" data-testid="marmara-designs-status-filter" @update:model-value="select('status')($event)">
                    <NativeSelectOption value="">{{ t('filters.all_statuses') }}</NativeSelectOption>
                    <NativeSelectOption v-for="status in filterOptions.statuses" :key="status" :value="status">{{ t(`statuses.${status}`) }}</NativeSelectOption>
                </NativeSelect>

                <NativeSelect v-if="filterOptions.tags.length > 0" :model-value="filters.tag ?? ''" :aria-label="t('filters.tag')" class="h-8 py-1" data-testid="marmara-designs-tag" @update:model-value="select('tag')($event)">
                    <NativeSelectOption value="">{{ t('filters.all_tags') }}</NativeSelectOption>
                    <NativeSelectOption v-for="tag in filterOptions.tags" :key="tag" :value="tag">#{{ tag }}</NativeSelectOption>
                </NativeSelect>

                <NativeSelect :model-value="filters.kind ?? ''" :aria-label="t('filters.kind')" class="h-8 py-1" data-testid="marmara-designs-kind" @update:model-value="select('kind')($event)">
                    <NativeSelectOption value="">{{ t('filters.all_kinds') }}</NativeSelectOption>
                    <NativeSelectOption v-for="kind in filterOptions.kinds" :key="kind" :value="kind">{{ t(`kinds.${kind}`) }}</NativeSelectOption>
                </NativeSelect>

                <Input :model-value="filters.from ?? ''" type="date" class="w-auto" :aria-label="t('filters.from')" :title="t('filters.from')" data-testid="marmara-designs-from" @change="select('from')(($event.target as HTMLInputElement).value)" />
                <Input :model-value="filters.to ?? ''" type="date" class="w-auto" :aria-label="t('filters.to')" :title="t('filters.to')" data-testid="marmara-designs-to" @change="select('to')(($event.target as HTMLInputElement).value)" />

                <NativeSelect :model-value="filters.sort ?? 'newest'" :aria-label="t('filters.sort')" class="h-8 py-1" data-testid="marmara-designs-sort" @update:model-value="select('sort')($event)">
                    <NativeSelectOption v-for="sort in filterOptions.sorts" :key="sort" :value="sort">{{ t(`sorts.${sort}`) }}</NativeSelectOption>
                </NativeSelect>

                <Button v-if="hasFilters" variant="ghost" data-testid="marmara-designs-clear" @click="clearFilters">{{ t('filters.clear') }}</Button>

                <span v-if="syncInfo.last_synced_at" class="ml-auto text-xs text-muted-foreground">{{ t('gallery.last_sync', { time: formatDateTime(syncInfo.last_synced_at) }) }}</span>
            </div>

            <!-- Gallery -->
            <div v-if="designs.length === 0" class="rounded-lg border border-border-strong bg-card p-6 text-sm" data-testid="marmara-designs-empty">
                <p v-if="hasFilters">{{ t('gallery.no_results') }}</p>
                <template v-else>
                    <p class="font-medium">{{ t('gallery.empty_title') }}</p>
                    <p class="text-muted-foreground">{{ t('gallery.empty_body') }}</p>
                </template>
            </div>

            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" data-testid="marmara-designs-grid">
                <button
                    v-for="design in designs"
                    :key="design.id"
                    type="button"
                    class="group flex flex-col overflow-hidden rounded-lg border bg-card text-left transition-colors hover:border-border-strong focus-visible:outline-2 focus-visible:outline-ring"
                    :class="selected?.id === design.id ? 'border-primary' : 'border-border'"
                    :data-testid="`marmara-designs-card-${design.id}`"
                    @click="open(design)"
                >
                    <span class="relative flex aspect-square items-center justify-center overflow-hidden bg-muted">
                        <img v-if="design.thumbnail_url" :src="design.thumbnail_url" :alt="design.title" loading="lazy" class="h-full w-full object-cover" />
                        <component :is="kindIcons[design.kind] ?? IconPhoto" v-else class="size-8 text-muted-foreground" />
                        <Badge variant="secondary" class="absolute top-1.5 left-1.5">{{ t(`statuses.${design.status}`) }}</Badge>
                        <Badge
                            v-if="design.status === 'in_review' && design.status_reason === 'content_changed'"
                            variant="secondary"
                            class="absolute bottom-1.5 left-1.5"
                            :data-testid="`marmara-designs-content-changed-${design.id}`"
                        >
                            {{ t('gallery.content_changed') }}
                        </Badge>
                        <Badge
                            v-else-if="design.status === 'used' && design.changed_after_use_at"
                            variant="secondary"
                            class="absolute bottom-1.5 left-1.5"
                        >
                            {{ t('gallery.changed_after_use') }}
                        </Badge>
                        <Badge v-if="(design.versions_count ?? 1) > 1" variant="secondary" class="absolute top-1.5 right-1.5">
                            {{ t('gallery.versions', { count: design.versions_count ?? 1 }) }}
                        </Badge>
                    </span>
                    <span class="flex flex-col gap-0.5 p-2">
                        <span class="truncate text-sm font-medium">{{ design.title }}</span>
                        <span class="truncate text-xs text-muted-foreground">{{ design.folder || t('filters.root_folder') }}</span>
                        <span class="flex items-center gap-2 text-xs text-muted-foreground">
                            <span class="truncate">{{ design.uploader.name }}</span>
                            <span class="ml-auto inline-flex items-center gap-0.5" :class="design.voted ? 'text-foreground' : ''">
                                <IconThumbUp class="size-3.5" />{{ design.votes }}
                            </span>
                            <span class="inline-flex items-center gap-0.5"><IconMessageCircle class="size-3.5" />{{ design.comments }}</span>
                        </span>
                    </span>
                </button>
            </div>

            <Button v-if="pagination.has_more" variant="outline" class="self-center" data-testid="marmara-designs-more" @click="visit({ page: pagination.page + 1 })">
                {{ t('gallery.load_more') }}
            </Button>
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
            v-model:open="uploadOpen"
            :t="t"
            :drive="driveUpload"
            :folders="filterOptions.folders"
            :url="urls.upload"
            :default-folder="filters.folder && filters.folder !== '/' ? filters.folder : null"
        />
    </AppLayout>
</template>
