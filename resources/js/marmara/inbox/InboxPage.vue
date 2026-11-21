<script setup lang="ts">
/**
 * Marmara AI fork: the inbox page ("Gelen Kutusu"), see app/Marmara/README.md.
 * Copy comes from the `copy` prop (the module's own en/tr lang files), URLs
 * from `urls` (with __ID__ for the item), so the page needs nothing generated.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArchive,
    IconExternalLink,
    IconEyeOff,
    IconInbox,
    IconMail,
    IconMailOpened,
    IconMessageCircle,
    IconSend,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import InputError from '@/components/InputError.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import AppLayout from '@/layouts/AppLayout.vue';

interface InboxItem {
    id: string;
    platform: string;
    kind: 'comment' | 'message';
    author: { name: string | null; handle: string | null };
    text: string | null;
    permalink: string | null;
    post: { id: string; excerpt: string | null } | null;
    received_at: string;
    can_reply: boolean;
    reply_window_ends_at: string | null;
    status: 'unread' | 'read' | 'archived';
    hidden?: boolean;
    channel_name?: string | null;
}

interface InboxReply {
    id: string;
    status: 'sending' | 'sent' | 'failed';
    text: string;
    error: string | null;
    created_at: string | null;
    author: { name: string | null } | null;
}

interface SelectedItem extends InboxItem {
    thread: InboxItem[];
    replies: InboxReply[];
    can_hide: boolean;
}

interface ChannelStatus {
    id: string;
    name: string | null;
    platform: string;
    provider: string;
    kinds: {
        kind: string;
        status: string;
        detail: string | null;
        reply_needs: string | null;
        last_synced_at: string | null;
    }[];
}

type Copy = Record<string, Record<string, unknown> | string>;

const props = defineProps<{
    copy: Copy;
    filters: { status: string; platform: string | null; kind: string | null };
    platforms: string[];
    items: InboxItem[];
    selected: SelectedItem | null;
    canReply: boolean;
    inboxChannels: ChannelStatus[];
    urls: Record<string, string>;
}>();

const t = (key: string, replace: Record<string, string> = {}): string => {
    const value = key
        .split('.')
        .reduce<unknown>(
            (node, part) =>
                node && typeof node === 'object'
                    ? (node as Record<string, unknown>)[part]
                    : undefined,
            props.copy,
        );
    let text = typeof value === 'string' ? value : key;

    for (const [name, replacement] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, replacement);
    }

    return text;
};

const url = (name: string, id: string): string =>
    (props.urls[name] ?? '').replace('__ID__', id);

const formatTime = (iso: string | null): string =>
    iso
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(iso))
        : '';

const authorLabel = (item: InboxItem): string =>
    item.author.name ??
    item.author.handle ??
    (item.platform === 'linkedin-page'
        ? t('list.linkedin_member')
        : t('list.unknown_author'));

const visit = (params: Record<string, string | null>): void => {
    const query = Object.fromEntries(
        Object.entries({ ...props.filters, ...params }).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );

    router.get(props.urls.index, query, {
        preserveState: true,
        preserveScroll: true,
    });
};

const statusTabs = ['open', 'unread', 'archived', 'all'];

const problems = computed(() =>
    props.inboxChannels.flatMap((channel) =>
        channel.kinds
            .filter(
                (kind) =>
                    !['ok', 'pending'].includes(kind.status) ||
                    kind.reply_needs !== null,
            )
            .map((kind) => ({ channel, kind })),
    ),
);

const windowClosed = computed(
    () =>
        props.selected?.reply_window_ends_at != null &&
        new Date(props.selected.reply_window_ends_at).getTime() < Date.now(),
);

const newRequestId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

const form = useForm({
    text: '',
    request_id: newRequestId(),
    acknowledged_reply_id: null as string | null,
});

// Someone else replied since the page loaded: the server refused with
// `already_replied` (naming that reply), so ask before sending anyway, and
// resend with it acknowledged under a new request id.
const sendReply = (acknowledged: string | null = null): void => {
    if (!props.selected) {
        return;
    }

    form.acknowledged_reply_id = acknowledged;

    form.post(url('replies', props.selected.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.request_id = newRequestId();
        },
        onError: (errors: Record<string, string>) => {
            const existing = errors.already_replied;

            if (!existing) {
                return;
            }

            toast.error(errors.text ?? '', {
                testId: 'marmara-inbox-already-replied',
                duration: 15000,
                action: {
                    label: t('reply.send_anyway'),
                    onClick: () => {
                        form.request_id = newRequestId();
                        sendReply(existing);
                    },
                },
            });
        },
    });
};

const act = (name: string): void => {
    if (!props.selected) {
        return;
    }

    router.post(url(name, props.selected.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="t('title')" :icon="IconInbox" />
        </template>

        <div class="flex flex-col gap-4 p-4 md:p-6" data-testid="marmara-inbox">
            <p class="text-sm text-muted-foreground">{{ t('description') }}</p>

            <div
                v-if="problems.length > 0"
                class="flex flex-col gap-2 rounded-lg border border-border-strong bg-card p-3"
                data-testid="marmara-inbox-channel-problems"
            >
                <div
                    v-for="problem in problems"
                    :key="`${problem.channel.id}-${problem.kind.kind}`"
                    class="flex items-start gap-2 text-sm"
                >
                    <IconAlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        <span class="font-medium">{{
                            problem.channel.name ??
                            getPlatformLabel(problem.channel.platform)
                        }}</span>
                        · {{ t(`kinds.${problem.kind.kind}`) }} ·
                        {{
                            t(`channels.statuses.${problem.kind.status}`)
                        }}
                        <span
                            v-if="problem.kind.detail ?? problem.kind.reply_needs"
                            class="text-muted-foreground"
                        >
                            ({{
                                problem.kind.detail ?? problem.kind.reply_needs
                            }})
                        </span>
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-for="tab in statusTabs"
                    :key="tab"
                    :variant="filters.status === tab ? 'default' : 'outline'"
                    :data-testid="`marmara-inbox-status-${tab}`"
                    @click="visit({ status: tab, item: null })"
                >
                    {{ t(`filters.${tab}`) }}
                </Button>

                <NativeSelect
                    :model-value="filters.platform ?? ''"
                    :aria-label="t('filters.platform')"
                    data-testid="marmara-inbox-platform"
                    class="h-8 py-1"
                    @update:model-value="
                        visit({ platform: String($event || '') || null })
                    "
                >
                    <NativeSelectOption value="">
                        {{ t('filters.all_platforms') }}
                    </NativeSelectOption>
                    <NativeSelectOption
                        v-for="platform in platforms"
                        :key="platform"
                        :value="platform"
                    >
                        {{ getPlatformLabel(platform) }}
                    </NativeSelectOption>
                </NativeSelect>

                <NativeSelect
                    :model-value="filters.kind ?? ''"
                    :aria-label="t('filters.kind')"
                    data-testid="marmara-inbox-kind"
                    class="h-8 py-1"
                    @update:model-value="
                        visit({ kind: String($event || '') || null })
                    "
                >
                    <NativeSelectOption value="">
                        {{ t('filters.all_kinds') }}
                    </NativeSelectOption>
                    <NativeSelectOption value="comment">
                        {{ t('kinds.comment') }}
                    </NativeSelectOption>
                    <NativeSelectOption value="message">
                        {{ t('kinds.message') }}
                    </NativeSelectOption>
                </NativeSelect>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <section
                    class="flex flex-col overflow-hidden rounded-lg border border-border-strong bg-card"
                    data-testid="marmara-inbox-list"
                >
                    <div
                        v-if="items.length === 0"
                        class="flex flex-col gap-1 p-6 text-sm"
                    >
                        <template
                            v-if="
                                filters.platform ||
                                filters.kind ||
                                filters.status !== 'open'
                            "
                        >
                            <p>{{ t('list.no_results') }}</p>
                        </template>
                        <template v-else>
                            <p class="font-medium">
                                {{ t('list.empty_title') }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ t('list.empty_body') }}
                            </p>
                        </template>
                    </div>

                    <button
                        v-for="item in items"
                        :key="item.id"
                        type="button"
                        class="flex w-full items-start gap-3 border-b border-border p-3 text-left last:border-b-0 hover:bg-accent"
                        :class="{ 'bg-accent': selected?.id === item.id }"
                        :data-testid="`marmara-inbox-item-${item.id}`"
                        @click="visit({ item: item.id })"
                    >
                        <PlatformLogo :platform="item.platform" size="xs" />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm"
                                    :class="
                                        item.status === 'unread'
                                            ? 'font-semibold'
                                            : 'font-medium'
                                    "
                                    >{{ authorLabel(item) }}</span
                                >
                                <component
                                    :is="
                                        item.kind === 'message'
                                            ? IconMail
                                            : IconMessageCircle
                                    "
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    :aria-label="t(`kinds.${item.kind}`)"
                                />
                                <Badge v-if="item.hidden" variant="secondary">
                                    {{ t('list.hidden') }}
                                </Badge>
                                <span
                                    class="ml-auto shrink-0 text-xs text-muted-foreground"
                                    >{{ formatTime(item.received_at) }}</span
                                >
                            </span>
                            <span
                                class="line-clamp-2 text-sm text-muted-foreground"
                                >{{ item.text }}</span
                            >
                        </span>
                    </button>
                </section>

                <section
                    class="flex flex-col gap-4 rounded-lg border border-border-strong bg-card p-4"
                    data-testid="marmara-inbox-thread"
                >
                    <p v-if="!selected" class="text-sm text-muted-foreground">
                        {{ t('thread.select') }}
                    </p>

                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2">
                            <PlatformLogo
                                :platform="selected.platform"
                                size="xs"
                            />
                            <span class="font-medium">{{
                                authorLabel(selected)
                            }}</span>
                            <span
                                v-if="selected.author.handle"
                                class="text-sm text-muted-foreground"
                                >@{{ selected.author.handle }}</span
                            >
                            <span class="text-sm text-muted-foreground"
                                >· {{ selected.channel_name }} ·
                                {{ formatTime(selected.received_at) }}</span
                            >
                            <a
                                v-if="selected.permalink"
                                :href="selected.permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-auto inline-flex items-center gap-1 text-sm text-primary-text hover:underline"
                            >
                                {{ t('thread.open_on_network') }}
                                <IconExternalLink class="size-3.5" />
                            </a>
                        </div>

                        <blockquote
                            v-if="selected.post?.excerpt"
                            class="border-l-2 border-border-strong pl-3 text-sm text-muted-foreground"
                        >
                            {{ t('thread.on_post') }}: {{ selected.post.excerpt }}
                        </blockquote>

                        <div v-if="selected.thread.length > 0" class="flex flex-col gap-2">
                            <p class="text-xs font-medium text-muted-foreground">
                                {{ t('thread.earlier') }}
                            </p>
                            <div
                                v-for="other in selected.thread"
                                :key="other.id"
                                class="rounded-md bg-muted p-2 text-sm"
                            >
                                <span class="font-medium">{{
                                    authorLabel(other)
                                }}</span>
                                {{ other.text }}
                            </div>
                        </div>

                        <p
                            class="text-base whitespace-pre-line"
                            data-testid="marmara-inbox-text"
                        >
                            {{ selected.text }}
                        </p>

                        <div
                            v-if="selected.replies.length > 0"
                            class="flex flex-col gap-2"
                        >
                            <p class="text-xs font-medium text-muted-foreground">
                                {{ t('thread.your_replies') }}
                            </p>
                            <div
                                v-for="reply in selected.replies"
                                :key="reply.id"
                                class="rounded-md border border-border p-2 text-sm"
                            >
                                <p
                                    v-if="reply.author?.name"
                                    class="mb-1 text-xs font-medium text-muted-foreground"
                                >
                                    {{ reply.author.name }}
                                </p>
                                <p class="whitespace-pre-line">{{ reply.text }}</p>
                                <p
                                    v-if="reply.status !== 'sent'"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{
                                        reply.status === 'failed'
                                            ? t('thread.reply_failed')
                                            : t('thread.reply_sending')
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="selected.status === 'unread'"
                                size="sm"
                                variant="outline"
                                @click="act('read')"
                            >
                                <IconMailOpened class="size-4" />
                                {{ t('actions.mark_read') }}
                            </Button>
                            <Button
                                v-else-if="selected.status === 'read'"
                                size="sm"
                                variant="outline"
                                @click="act('unread')"
                            >
                                <IconMail class="size-4" />
                                {{ t('actions.mark_unread') }}
                            </Button>
                            <Button
                                v-if="selected.status !== 'archived'"
                                size="sm"
                                variant="outline"
                                data-testid="marmara-inbox-archive"
                                @click="act('archive')"
                            >
                                <IconArchive class="size-4" />
                                {{ t('actions.archive') }}
                            </Button>
                            <Button
                                v-else
                                size="sm"
                                variant="outline"
                                @click="act('unarchive')"
                            >
                                <IconInbox class="size-4" />
                                {{ t('actions.unarchive') }}
                            </Button>
                            <Button
                                v-if="canReply && selected.can_hide"
                                size="sm"
                                variant="outline"
                                @click="act('hide')"
                            >
                                <IconEyeOff class="size-4" />
                                {{ t('actions.hide') }}
                            </Button>
                        </div>

                        <form
                            v-if="canReply && selected.can_reply && !windowClosed"
                            class="flex flex-col gap-2"
                            data-testid="marmara-inbox-reply"
                            @submit.prevent="sendReply()"
                        >
                            <Textarea
                                v-model="form.text"
                                :placeholder="t('reply.placeholder')"
                                rows="3"
                                data-testid="marmara-inbox-reply-text"
                            />
                            <InputError :message="form.errors.text" />
                            <div class="flex items-center gap-3">
                                <p
                                    v-if="selected.reply_window_ends_at"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        t('reply.window_ends', {
                                            time: formatTime(
                                                selected.reply_window_ends_at,
                                            ),
                                        })
                                    }}
                                </p>
                                <Button
                                    type="submit"
                                    size="sm"
                                    class="ml-auto"
                                    :disabled="
                                        form.processing ||
                                        form.text.trim() === ''
                                    "
                                    data-testid="marmara-inbox-reply-send"
                                >
                                    <IconSend class="size-4" />
                                    {{ t('reply.send') }}
                                </Button>
                            </div>
                        </form>
                        <p v-else class="text-sm text-muted-foreground">
                            {{
                                !canReply
                                    ? t('reply.no_permission')
                                    : windowClosed
                                      ? t('reply.window_closed')
                                      : t('reply.not_allowed')
                            }}
                        </p>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
