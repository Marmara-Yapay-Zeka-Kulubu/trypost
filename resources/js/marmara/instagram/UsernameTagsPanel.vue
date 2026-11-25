<script setup lang="ts">
/**
 * Marmara AI fork: people tags of a story or reel, usernames without a
 * position (app/Marmara/README.md, Instagram people tags). The feed image
 * panel (MediaEditorTagsPanel) places each tag on the image instead.
 */
import { IconAt, IconExternalLink, IconX } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { instagramProfileUrl } from '@/lib/instagram';
import {
    isValidInstagramUsername,
    type MediaEdit,
    normalizeUsername,
    USER_TAGS_MAX,
} from '@/lib/mediaEditor';

const edit = defineModel<MediaEdit>('edit', { required: true });

const username = ref('');
const invalid = ref(false);

const atLimit = computed(() => edit.value.userTags.length >= USER_TAGS_MAX);

const clearInvalid = (): void => {
    invalid.value = false;
};

const addTag = (): void => {
    if (atLimit.value) return;
    if (!isValidInstagramUsername(username.value)) {
        invalid.value = true;
        return;
    }

    const value = normalizeUsername(username.value);

    if (!edit.value.userTags.some((tag) => tag.username === value)) {
        edit.value.userTags = [...edit.value.userTags, { username: value }];
    }

    username.value = '';
};

const removeTag = (index: number): void => {
    edit.value.userTags = edit.value.userTags.filter(
        (_, tagIndex) => tagIndex !== index,
    );
};
</script>

<template>
    <div class="space-y-4" data-testid="media-editor-username-tags">
        <div class="space-y-3">
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">
                    {{ $t('posts.composer.media_editor.tags_heading') }}
                </h3>
                <span
                    class="text-xs text-muted-foreground tabular-nums"
                    data-testid="media-editor-tags-count"
                    >{{ edit.userTags.length }} / {{ USER_TAGS_MAX }}</span
                >
            </div>

            <form
                v-if="!atLimit"
                class="space-y-2"
                data-testid="media-editor-tag-form"
                @submit.prevent="addTag"
            >
                <div
                    class="flex items-center gap-2 rounded-lg border border-border bg-background px-3"
                    :class="invalid ? 'border-destructive' : ''"
                >
                    <IconAt class="size-4 shrink-0 text-muted-foreground" />
                    <input
                        v-model="username"
                        data-testid="media-editor-tag-username"
                        class="h-9 w-full bg-transparent text-sm outline-none"
                        autocomplete="off"
                        :placeholder="
                            $t('posts.composer.media_editor.tags_placeholder')
                        "
                        :aria-invalid="invalid"
                        @input="clearInvalid"
                    />
                </div>
                <p v-if="invalid" class="text-xs text-destructive">
                    {{ $t('posts.composer.media_editor.tags_invalid') }}
                </p>
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        data-testid="media-editor-tag-save"
                    >
                        {{ $t('posts.composer.media_editor.tags_save') }}
                    </Button>
                </div>
            </form>
            <p v-else class="text-xs text-muted-foreground">
                {{
                    $t('posts.composer.media_editor.tags_limit', {
                        max: String(USER_TAGS_MAX),
                    })
                }}
            </p>

            <ul
                v-if="edit.userTags.length"
                class="flex flex-wrap gap-2"
                data-testid="media-editor-username-tag-list"
            >
                <li
                    v-for="(tag, index) in edit.userTags"
                    :key="`${tag.username}-${index}`"
                    class="inline-flex max-w-full items-center gap-1 rounded-full border border-border bg-muted/40 py-0.5 pr-0.5 pl-2.5 text-sm"
                    :data-testid="`media-editor-tag-${index}`"
                >
                    <a
                        :href="instagramProfileUrl(tag.username)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-w-0 items-center gap-1 font-medium underline-offset-2 hover:underline"
                    >
                        <span class="truncate">@{{ tag.username }}</span>
                        <IconExternalLink
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </a>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="size-6 shrink-0 rounded-full text-muted-foreground hover:text-foreground"
                        :data-testid="`media-editor-tag-remove-${index}`"
                        :aria-label="
                            $t('posts.composer.media_editor.tags_remove', {
                                username: tag.username,
                            })
                        "
                        @click="removeTag(index)"
                    >
                        <IconX class="size-3.5" />
                    </Button>
                </li>
            </ul>
        </div>

        <p class="text-xs text-muted-foreground">
            {{ $t('posts.composer.media_editor.tags_limitations') }}
        </p>
    </div>
</template>
