<script setup lang="ts">
/**
 * Marmara AI fork: one design in a drawer — large preview, metadata, "Drive'da
 * aç", versions strip with side-by-side compare, status and tags, votes,
 * comments and activity (app/Marmara/README.md, Designs).
 */
import { router, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconColumns,
    IconDownload,
    IconExternalLink,
    IconFileTypePdf,
    IconLink,
    IconPhoto,
    IconPhotoOff,
    IconSend,
    IconThumbUp,
    IconUnlink,
    IconVideo,
    IconX,
} from '@tabler/icons-vue';
import { computed, ref, watch, type Component } from 'vue';

import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';

import DesignStatusBadge from './DesignStatusBadge.vue';

import {
    fill,
    formatBytes,
    formatDateTime,
    type DesignVersion,
    type LinkCandidate,
    type SelectedDesign,
    type Translate,
} from './types';

const props = defineProps<{
    t: Translate;
    design: SelectedDesign;
    linkCandidates: LinkCandidate[];
    canApprove: boolean;
    urls: Record<string, string>;
}>();

const emit = defineEmits<{ close: []; createPost: [] }>();

const url = (name: string, extra: Record<string, string> = {}): string => fill(props.urls[name] ?? '', props.design.id, extra);

const current = computed<DesignVersion | undefined>(() => props.design.versions.find((version) => version.is_current));
const shown = ref<DesignVersion | undefined>(current.value);
const comparing = ref(false);
const compared = ref<DesignVersion[]>([]);

// Pictures that did not load (a fresh video has no Drive thumbnail yet) and
// a video the browser could not play: both fall back to the kind placeholder.
const failedImages = ref<Set<string>>(new Set());
const videoFailed = ref(false);
const imageShown = (src: string | null): boolean => src !== null && src !== '' && !failedImages.value.has(src);
const imageFailed = (src: string | null): void => {
    if (src) {
        failedImages.value = new Set([...failedImages.value, src]);
    }
};

watch(
    () => props.design.id,
    () => {
        shown.value = current.value;
        comparing.value = false;
        compared.value = [];
        failedImages.value = new Set();
        videoFailed.value = false;
    },
);

const pickVersion = (version: DesignVersion): void => {
    if (!comparing.value) {
        shown.value = version;

        return;
    }

    const already = compared.value.findIndex((item) => item.key === version.key);

    if (already >= 0) {
        compared.value.splice(already, 1);
    } else {
        compared.value = [...compared.value.slice(-1), version];
    }
};

const toggleCompare = (): void => {
    comparing.value = !comparing.value;
    compared.value = comparing.value
        ? props.design.versions.length >= 2
            ? props.design.versions.slice(-2)
            : [...props.design.versions]
        : [];
};

const previewUrl = computed(() => shown.value?.preview_url ?? props.design.preview_url);

// The current content of a video plays here, streamed from Drive in ranges
// (DesignMediaController::play); older revisions only have the placeholder.
const playUrl = computed(() => (props.urls.play ? url('play') : ''));
const playsVideo = computed(
    () =>
        props.design.kind === 'video' &&
        !props.design.removed &&
        playUrl.value !== '' &&
        !videoFailed.value &&
        (shown.value === undefined || (shown.value.design_id === props.design.id && shown.value.revision_id === null)),
);

const visit = (method: 'post' | 'put' | 'patch' | 'delete', target: string, data: Record<string, unknown> = {}): void => {
    router.visit(target, { method, data, preserveScroll: true, preserveState: true });
};

const statusOptions = computed(() => {
    const allowed = new Set([...props.design.can.statuses, props.design.status]);

    return ['draft', 'in_review', 'approved', 'used', 'archived'].filter((status) => allowed.has(status as SelectedDesign['status']));
});

const setStatus = (status: unknown): void => {
    if (typeof status === 'string' && status !== props.design.status) {
        visit('patch', url('status'), { status });
    }
};

const tags = ref<string[]>([...props.design.tags]);
const tagDraft = ref('');

watch(
    () => props.design.tags,
    (value) => {
        tags.value = [...value];
    },
);

const addTag = (): void => {
    const tag = tagDraft.value.trim().replace(/^#/, '').toLocaleLowerCase();

    if (tag !== '' && !tags.value.includes(tag)) {
        tags.value = [...tags.value, tag];
    }

    tagDraft.value = '';
};

const tagsChanged = computed(() => [...tags.value].sort().join('|') !== [...props.design.tags].sort().join('|'));

const saveTags = (): void => visit('put', url('tags'), { tags: tags.value });

const toggleVote = (): void => visit(props.design.voted ? 'delete' : 'post', url('vote'));

const commentForm = useForm({ body: '' });

const sendComment = (): void => {
    commentForm.post(url('comments'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => commentForm.reset(),
    });
};

const editing = ref<string | null>(null);
const editBody = ref('');

const startEdit = (id: string, body: string): void => {
    editing.value = id;
    editBody.value = body;
};

const saveEdit = (id: string): void => {
    router.patch(url('comment', { __COMMENT__: id }), { body: editBody.value }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};

const deleteComment = (id: string): void => {
    if (window.confirm(props.t('comments.delete_confirm'))) {
        visit('delete', url('comment', { __COMMENT__: id }));
    }
};

const linkTarget = ref('');

const linkOptions = computed(() =>
    props.linkCandidates.map((candidate) => ({
        value: candidate.id,
        label: candidate.folder ? `${candidate.title} — ${candidate.folder}` : candidate.title,
    })),
);

const linkVersion = (): void => {
    if (linkTarget.value !== '') {
        visit('post', url('versions'), { previous_id: linkTarget.value });
        linkTarget.value = '';
    }
};

const kindIcons: Record<string, Component> = { pdf: IconFileTypePdf, video: IconVideo };
const kindIcon = computed<Component>(() => kindIcons[props.design.kind] ?? IconPhoto);

const activityText = (type: string, data: Record<string, unknown>): string => {
    if (type === 'status_changed') {
        return props.t('activity.status_changed', {
            from: props.t(`statuses.${String(data.from ?? '')}`),
            to: props.t(`statuses.${String(data.to ?? '')}`),
        });
    }

    return props.t(`activity.${type}`, { name: String(data.name ?? '') });
};
</script>

<template>
    <Sheet :open="true" @update:open="(open) => !open && emit('close')">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto p-0 sm:max-w-3xl"
            :show-close-button="false"
            data-testid="marmara-designs-drawer"
        >
            <SheetHeader class="sticky top-0 z-10 flex flex-row items-start gap-2 border-b border-border-strong bg-background p-4 md:px-6">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <SheetTitle class="truncate font-heading text-lg font-medium" data-testid="marmara-designs-drawer-title">{{ design.title }}</SheetTitle>
                    <SheetDescription class="flex flex-wrap items-center gap-2 text-xs">
                        <DesignStatusBadge :status="design.status" :label="t(`statuses.${design.status}`)" compact />
                        <span class="truncate">{{ design.folder || t('filters.root_folder') }}</span>
                    </SheetDescription>
                </div>
                <Button variant="ghost" size="icon" :aria-label="t('detail.close')" @click="emit('close')">
                    <IconX class="size-4" />
                </Button>
            </SheetHeader>

            <div class="flex flex-col gap-6 p-4 md:px-6">
                <p v-if="design.removed" class="flex items-start gap-2 rounded-lg bg-muted px-4 py-3 text-sm text-muted-foreground" role="status">
                    <IconPhotoOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ t('detail.removed') }}
                </p>
                <p
                    v-if="design.status === 'in_review' && design.status_reason === 'content_changed'"
                    class="flex items-start gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-950 dark:text-amber-400"
                    role="status"
                    data-testid="marmara-designs-content-changed"
                >
                    <IconAlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ t('detail.content_changed') }}
                </p>
                <p
                    v-if="design.status === 'used' && design.changed_after_use_at"
                    class="flex items-start gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-950 dark:text-amber-400"
                    role="status"
                    data-testid="marmara-designs-changed-after-use"
                >
                    <IconAlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ t('detail.changed_after_use') }} ({{ formatDateTime(design.changed_after_use_at) }})
                </p>

                <!-- Preview, or two versions side by side -->
                <div v-if="comparing && compared.length > 0" class="grid grid-cols-2 gap-2" data-testid="marmara-designs-compare">
                    <figure v-for="version in compared" :key="version.key" class="flex flex-col gap-1">
                        <div class="flex aspect-square items-center justify-center overflow-hidden rounded-xl border border-border bg-muted">
                            <img
                                v-if="imageShown(version.preview_url)"
                                :src="version.preview_url ?? undefined"
                                alt=""
                                class="max-h-full max-w-full object-contain"
                                @error="imageFailed(version.preview_url)"
                            />
                            <IconPhotoOff v-else class="size-8 text-muted-foreground" :aria-label="t('versions.no_preview')" />
                        </div>
                        <figcaption class="text-xs text-muted-foreground">
                            <span class="font-medium text-foreground">{{ version.label }}</span>
                            · {{ formatDateTime(version.modified_at) }}<template v-if="version.author.name"> · {{ version.author.name }}</template>
                        </figcaption>
                    </figure>
                </div>
                <div v-else class="flex min-h-64 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted">
                    <video
                        v-if="playsVideo"
                        :key="design.id"
                        :src="playUrl"
                        :poster="imageShown(previewUrl) ? (previewUrl ?? undefined) : undefined"
                        controls
                        preload="metadata"
                        playsinline
                        class="max-h-[70vh] w-full bg-black"
                        :aria-label="design.title"
                        data-testid="marmara-designs-video"
                        @error="videoFailed = true"
                    />
                    <img
                        v-else-if="imageShown(previewUrl)"
                        :src="previewUrl ?? undefined"
                        alt=""
                        class="max-h-[70vh] max-w-full object-contain"
                        data-testid="marmara-designs-preview"
                        @error="imageFailed(previewUrl)"
                    />
                    <div v-else class="flex max-w-sm flex-col items-center gap-2 p-6 text-center text-muted-foreground" data-testid="marmara-designs-preview-placeholder">
                        <component :is="kindIcon" class="size-10 shrink-0" stroke-width="1.5" aria-hidden="true" />
                        <span class="text-sm font-medium">{{ t(`kinds.${design.kind}`) }}</span>
                        <span v-if="design.kind === 'video' && !design.removed" class="text-xs">
                            {{ videoFailed ? t('detail.video_unavailable') : t('detail.preview_pending') }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        :variant="design.voted ? 'default' : 'outline'"
                        :aria-pressed="design.voted"
                        :aria-label="design.voted ? t('votes.unvote') : t('votes.vote')"
                        data-testid="marmara-designs-vote"
                        @click="toggleVote"
                    >
                        <IconThumbUp class="size-4" />
                        {{ design.votes }}
                    </Button>
                    <Button v-if="design.drive_url" as="a" :href="design.drive_url" target="_blank" rel="noopener noreferrer" variant="outline">
                        <IconExternalLink class="size-4" />
                        {{ t('detail.open_in_drive') }}
                    </Button>
                    <Button v-if="!design.removed" as="a" :href="url('original')" variant="outline">
                        <IconDownload class="size-4" />
                        {{ t('detail.download') }}
                    </Button>
                    <slot name="actions" />
                </div>

                <!-- Versions strip -->
                <section v-if="design.versions.length > 1" class="flex flex-col gap-2" data-testid="marmara-designs-versions">
                    <div class="flex items-center gap-2">
                        <h3 class="font-heading text-base font-medium">{{ t('versions.title') }}</h3>
                        <Badge variant="secondary" class="h-5 px-1.5">{{ design.versions.length }}</Badge>
                        <Button variant="outline" size="sm" class="ms-auto" :aria-pressed="comparing" data-testid="marmara-designs-compare-toggle" @click="toggleCompare">
                            <IconColumns class="size-4" />
                            {{ comparing ? t('versions.close_compare') : t('versions.compare') }}
                        </Button>
                    </div>
                    <p v-if="comparing" class="text-xs text-muted-foreground">{{ t('versions.compare_hint') }}</p>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="version in design.versions"
                            :key="version.key"
                            type="button"
                            class="flex w-24 shrink-0 cursor-pointer flex-col gap-1 rounded-lg border bg-card p-1 text-start text-xs transition-colors hover:border-border-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :class="(comparing ? compared.some((item) => item.key === version.key) : shown?.key === version.key) ? 'border-primary' : 'border-border'"
                            :aria-pressed="comparing ? compared.some((item) => item.key === version.key) : shown?.key === version.key"
                            :data-testid="`marmara-designs-version-${version.label}`"
                            @click="pickVersion(version)"
                        >
                            <span class="flex aspect-square items-center justify-center overflow-hidden rounded-md bg-muted">
                                <img
                                    v-if="imageShown(version.thumbnail_url)"
                                    :src="version.thumbnail_url ?? undefined"
                                    alt=""
                                    loading="lazy"
                                    class="h-full w-full object-cover"
                                    @error="imageFailed(version.thumbnail_url)"
                                />
                                <IconPhotoOff v-else class="size-5 text-muted-foreground" :aria-label="t('versions.no_preview')" />
                            </span>
                            <span class="font-medium">{{ version.label }}<template v-if="version.is_current"> · {{ t('versions.current') }}</template></span>
                            <span class="truncate text-muted-foreground">{{ formatDateTime(version.modified_at) }}</span>
                        </button>
                    </div>
                </section>

                <!-- Status and tags -->
                <section class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="marmara-designs-status">{{ t('detail.status') }}</Label>
                        <Select :model-value="design.status" @update:model-value="setStatus">
                            <SelectTrigger id="marmara-designs-status" class="w-full" data-testid="marmara-designs-status">
                                <SelectValue>{{ t(`statuses.${design.status}`) }}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="status in statusOptions"
                                    :key="status"
                                    :value="status"
                                    :data-testid="`marmara-designs-status-option-${status}`"
                                >
                                    {{ t(`statuses.${status}`) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="!canApprove" class="text-xs text-muted-foreground">{{ t('detail.status_needs_approver') }}</p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="marmara-designs-tag-input">{{ t('detail.tags') }}</Label>
                        <div class="flex flex-wrap gap-1">
                            <Badge v-for="tag in tags" :key="tag" variant="secondary" class="h-6 gap-1 px-2">
                                #{{ tag }}
                                <button type="button" class="cursor-pointer rounded-sm text-muted-foreground hover:text-foreground" :aria-label="`${tag} ×`" @click="tags = tags.filter((item) => item !== tag)">
                                    <IconX class="size-3" />
                                </button>
                            </Badge>
                        </div>
                        <div class="flex gap-2">
                            <Input
                                id="marmara-designs-tag-input"
                                v-model="tagDraft"
                                maxlength="50"
                                :placeholder="t('detail.tags_placeholder')"
                                data-testid="marmara-designs-tag-input"
                                @keydown.enter.prevent="addTag"
                            />
                            <Button v-if="tagsChanged" variant="outline" data-testid="marmara-designs-tags-save" @click="saveTags">
                                {{ t('detail.save_tags') }}
                            </Button>
                        </div>
                    </div>
                </section>

                <!-- Metadata -->
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 rounded-xl border border-border p-4 text-sm">
                    <dt class="text-muted-foreground">{{ t('detail.uploader') }}</dt>
                    <dd class="truncate">{{ design.uploader.name ?? '—' }}<span v-if="design.owner_email" class="text-muted-foreground"> · {{ design.owner_email }}</span></dd>
                    <template v-if="design.modifier.name">
                        <dt class="text-muted-foreground">{{ t('detail.last_editor') }}</dt>
                        <dd class="truncate">{{ design.modifier.name }}</dd>
                    </template>
                    <dt class="text-muted-foreground">{{ t('detail.created') }}</dt>
                    <dd>{{ formatDateTime(design.created_at) }}</dd>
                    <dt class="text-muted-foreground">{{ t('detail.modified') }}</dt>
                    <dd>{{ formatDateTime(design.modified_at) }}</dd>
                    <dt class="text-muted-foreground">{{ t('detail.type') }}</dt>
                    <dd>{{ t(`kinds.${design.kind}`) }} · {{ design.mime_type }}</dd>
                    <template v-if="design.width && design.height">
                        <dt class="text-muted-foreground">{{ t('detail.dimensions') }}</dt>
                        <dd>{{ design.width }} × {{ design.height }}</dd>
                    </template>
                    <template v-if="design.size">
                        <dt class="text-muted-foreground">{{ t('detail.size') }}</dt>
                        <dd>{{ formatBytes(design.size) }}</dd>
                    </template>
                </dl>

                <!-- "Bu tasarım, X'in yeni sürümü" -->
                <section class="flex flex-col gap-1.5">
                    <Label>{{ t('versions.link_label') }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <div class="min-w-48 flex-1" data-testid="marmara-designs-link-select">
                            <SearchableSelect
                                v-model="linkTarget"
                                :options="linkOptions"
                                :placeholder="t('versions.link_placeholder')"
                                :search-placeholder="t('filters.search_options')"
                                :empty-text="t('filters.no_options')"
                            />
                        </div>
                        <Button variant="outline" :disabled="linkTarget === ''" data-testid="marmara-designs-link-save" @click="linkVersion">
                            <IconLink class="size-4" />
                            {{ t('versions.link_save') }}
                        </Button>
                        <Button v-if="design.family_id" variant="ghost" @click="visit('delete', url('versions'))">
                            <IconUnlink class="size-4" />
                            {{ t('versions.unlink') }}
                        </Button>
                    </div>
                </section>

                <!-- Comments -->
                <section class="flex flex-col gap-2" data-testid="marmara-designs-comments">
                    <h3 class="flex items-center gap-2 font-heading text-base font-medium">
                        {{ t('comments.title') }}
                        <Badge v-if="design.comments_list.length > 0" variant="secondary" class="h-5 px-1.5">{{ design.comments_list.length }}</Badge>
                    </h3>
                    <p v-if="design.comments_list.length === 0" class="text-sm text-muted-foreground">{{ t('comments.empty') }}</p>
                    <div v-for="comment in design.comments_list" :key="comment.id" class="rounded-xl border border-border bg-card p-3 text-sm">
                        <p class="mb-1 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            <span class="font-medium text-foreground">{{ comment.author?.name ?? t('comments.unknown_author') }}</span>
                            <span>{{ formatDateTime(comment.created_at) }}</span>
                            <span v-if="comment.edited_at">({{ t('comments.edited') }})</span>
                            <span v-if="comment.mine && editing !== comment.id" class="ml-auto flex gap-1">
                                <Button size="sm" variant="ghost" @click="startEdit(comment.id, comment.body)">{{ t('comments.edit') }}</Button>
                                <Button size="sm" variant="ghost" @click="deleteComment(comment.id)">{{ t('comments.delete') }}</Button>
                            </span>
                        </p>
                        <div v-if="editing === comment.id" class="flex flex-col gap-2">
                            <Textarea v-model="editBody" rows="2" />
                            <div class="flex justify-end gap-2">
                                <Button size="sm" variant="ghost" @click="editing = null">{{ t('comments.cancel') }}</Button>
                                <Button size="sm" :disabled="editBody.trim() === ''" @click="saveEdit(comment.id)">{{ t('comments.save') }}</Button>
                            </div>
                        </div>
                        <p v-else class="whitespace-pre-line">{{ comment.body }}</p>
                    </div>
                    <form class="flex flex-col gap-2" @submit.prevent="sendComment">
                        <Textarea v-model="commentForm.body" rows="2" :placeholder="t('comments.placeholder')" data-testid="marmara-designs-comment-text" />
                        <InputError :message="commentForm.errors.body" />
                        <Button type="submit" class="self-end" :disabled="commentForm.processing || commentForm.body.trim() === ''" data-testid="marmara-designs-comment-send">
                            <IconSend class="size-4" />
                            {{ t('comments.send') }}
                        </Button>
                    </form>
                </section>

                <!-- Activity -->
                <section v-if="design.activity.length > 0" class="flex flex-col gap-1">
                    <h3 class="font-heading text-base font-medium">{{ t('detail.activity') }}</h3>
                    <ul class="flex flex-col gap-1 text-xs text-muted-foreground">
                        <li v-for="entry in design.activity" :key="entry.id">
                            <span class="font-medium text-foreground">{{ entry.user?.name ?? (['added', 'content_changed', 'changed_after_use'].includes(entry.type) ? t('activity.drive') : t('activity.someone')) }}</span>
                            · {{ activityText(entry.type, entry.data) }} · {{ formatDateTime(entry.created_at) }}
                        </li>
                    </ul>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
