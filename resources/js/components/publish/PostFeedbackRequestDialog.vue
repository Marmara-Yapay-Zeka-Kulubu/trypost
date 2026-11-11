<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import {
    recipients as recipientsRoute,
    store as storeFeedbackRequest,
} from '@/actions/App/Http/Controllers/App/PostFeedbackRequestController';
import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { blank, filled } from '@/lib/helpers';
import type { PostNote, PostNoteAuthor } from '@/types/post-note';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const recipientsHttp = useHttp<Record<string, never>, PostNoteAuthor[]>({});
const http = useHttp<{ user_ids: string[]; note: string }, PostNote>({
    user_ids: [],
    note: '',
});

const recipients = ref<PostNoteAuthor[] | null>(null);
const loadFailed = ref(false);
const sendFailed = ref(false);

const loadRecipients = async (): Promise<void> => {
    loadFailed.value = false;
    recipients.value = null;

    try {
        recipients.value =
            (await recipientsHttp.get(recipientsRoute.url(props.post.id))) ??
            [];
    } catch {
        loadFailed.value = true;
    }
};

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    http.user_ids = [];
    http.note = '';
    http.clearErrors();
    sendFailed.value = false;
    void loadRecipients();
});

const isSelected = (id: string): boolean => http.user_ids.includes(id);

const toggleRecipient = (id: string): void => {
    http.user_ids = isSelected(id)
        ? http.user_ids.filter((selectedId) => selectedId !== id)
        : [...http.user_ids, id];
};

const canSend = computed(() => filled(http.user_ids) && !http.processing);

const errorMessage = computed(
    () =>
        Object.entries(http.errors as Record<string, string | undefined>).find(
            ([field]) => field.startsWith('user_ids'),
        )?.[1] ??
        (http.errors as Record<string, string | undefined>).note ??
        null,
);

const closeDialog = (): void => {
    open.value = false;
};

const send = async (): Promise<void> => {
    if (!canSend.value) {
        return;
    }

    sendFailed.value = false;

    try {
        const note = await http.post(storeFeedbackRequest.url(props.post.id));

        if (!note) {
            return;
        }

        open.value = false;
        toast.success(trans('posts.feedback.sent'), {
            testId: 'post-feedback-sent-toast',
        });
    } catch {
        sendFailed.value = true;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-md"
            :data-testid="`post-feedback-dialog-${testKey}`"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('posts.feedback.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('posts.feedback.description') }}
                </DialogDescription>
            </DialogHeader>

            <form
                :id="`post-feedback-form-${testKey}`"
                class="flex flex-col gap-4"
                @submit.prevent="send"
            >
                <div class="grid gap-2">
                    <Label :id="`post-feedback-recipients-label-${testKey}`">
                        {{ $t('posts.feedback.recipients') }}
                    </Label>

                    <div
                        v-if="recipients === null && !loadFailed"
                        class="grid gap-2"
                        :data-testid="`post-feedback-loading-${testKey}`"
                    >
                        <Skeleton
                            v-for="row in 3"
                            :key="row"
                            class="h-9 w-full"
                        />
                    </div>

                    <p
                        v-else-if="loadFailed"
                        role="alert"
                        class="text-sm text-destructive"
                        :data-testid="`post-feedback-load-error-${testKey}`"
                    >
                        {{ $t('posts.feedback.errors.load_failed') }}
                    </p>

                    <p
                        v-else-if="blank(recipients)"
                        class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground"
                        :data-testid="`post-feedback-empty-${testKey}`"
                    >
                        {{ $t('posts.feedback.empty') }}
                    </p>

                    <div
                        v-else
                        role="group"
                        :aria-labelledby="`post-feedback-recipients-label-${testKey}`"
                        class="max-h-64 overflow-y-auto rounded-xl border border-border p-1.5"
                        :data-testid="`post-feedback-recipients-${testKey}`"
                    >
                        <label
                            v-for="recipient in recipients"
                            :key="recipient.id"
                            :for="`post-feedback-recipient-${testKey}-${recipient.id}`"
                            class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 transition-control hover:bg-accent"
                            :data-testid="`post-feedback-recipient-${recipient.id}`"
                        >
                            <Checkbox
                                :id="`post-feedback-recipient-${testKey}-${recipient.id}`"
                                :model-value="isSelected(recipient.id)"
                                :data-testid="`post-feedback-recipient-checkbox-${recipient.id}`"
                                @update:model-value="toggleRecipient(recipient.id)"
                            />
                            <Avatar
                                :src="recipient.photo_url"
                                :name="recipient.name"
                                class="size-7 text-xs"
                                fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
                            />
                            <span class="min-w-0 flex-1 truncate text-sm">
                                {{ recipient.name }}
                            </span>
                        </label>
                    </div>
                </div>

                <Textarea
                    v-if="filled(recipients)"
                    v-model="http.note"
                    class="min-h-20"
                    :placeholder="$t('posts.feedback.note_placeholder')"
                    :aria-label="$t('posts.feedback.note_placeholder')"
                    :disabled="http.processing"
                    :data-testid="`post-feedback-note-${testKey}`"
                />

                <InputError :message="errorMessage ?? undefined" />

                <p
                    v-if="sendFailed"
                    role="alert"
                    class="text-sm text-destructive"
                    :data-testid="`post-feedback-send-error-${testKey}`"
                >
                    {{ $t('posts.feedback.errors.send_failed') }}
                </p>
            </form>

            <DialogFooter>
                <Button
                    variant="ghost"
                    :data-testid="`post-feedback-cancel-${testKey}`"
                    @click="closeDialog"
                >
                    {{ $t('common.cancel') }}
                </Button>
                <Button
                    v-if="filled(recipients)"
                    type="submit"
                    :form="`post-feedback-form-${testKey}`"
                    :disabled="!canSend"
                    :loading="http.processing"
                    :data-testid="`post-feedback-send-${testKey}`"
                >
                    {{ $t('posts.feedback.send') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
