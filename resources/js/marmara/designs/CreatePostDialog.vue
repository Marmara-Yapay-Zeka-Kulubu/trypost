<script setup lang="ts">
/**
 * Marmara AI fork: "Gönderi oluştur" — a draft with the design's original
 * attached (JPEG as is, PNG/WebP/HEIC as q100 JPEG) for the channels picked here (app/Marmara/README.md,
 * Designs). The server downloads the original from Drive. With `designIds`
 * (several designs picked on the page, or a series) one post per channel
 * gets all of them as media, in that order.
 */
import { useForm } from '@inertiajs/vue3';
import { IconSend } from '@tabler/icons-vue';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { getPlatformLabel } from '@/composables/usePlatformLogo';

import type { PostChannel, Translate } from './types';

const props = defineProps<{
    t: Translate;
    channels: PostChannel[];
    url: string;
    designIds?: string[];
}>();

const many = computed(() => (props.designIds?.length ?? 0) > 0);

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    social_account_ids: [] as string[],
    content: '',
});

const toggle = (id: string, checked: boolean): void => {
    form.social_account_ids = checked
        ? [...form.social_account_ids, id]
        : form.social_account_ids.filter((item) => item !== id);
};

const submit = (): void => {
    form.transform((data) => (many.value ? { ...data, design_ids: props.designIds } : data)).post(props.url, { preserveScroll: true });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-testid="marmara-designs-post-dialog">
            <DialogHeader>
                <DialogTitle>{{ many ? t('post.title_many') : t('post.title') }}</DialogTitle>
                <DialogDescription>{{ many ? t('post.description_many', { count: designIds?.length ?? 0 }) : t('post.description') }}</DialogDescription>
            </DialogHeader>

            <p v-if="channels.length === 0" class="text-sm text-muted-foreground">{{ t('post.no_channels') }}</p>

            <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                <fieldset class="flex flex-col gap-1.5">
                    <legend class="mb-1 text-sm font-medium">{{ t('post.channels') }}</legend>
                    <label
                        v-for="channel in channels"
                        :key="channel.id"
                        class="flex min-h-8 cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm transition-control hover:bg-accent"
                        :class="{ 'opacity-60': !channel.connected }"
                    >
                        <Checkbox
                            :model-value="form.social_account_ids.includes(channel.id)"
                            :data-testid="`marmara-designs-post-channel-${channel.id}`"
                            @update:model-value="(checked) => toggle(channel.id, checked === true)"
                        />
                        <PlatformLogo :platform="channel.platform" size="xs" />
                        <span class="truncate">{{ channel.name ?? getPlatformLabel(channel.platform) }}</span>
                    </label>
                    <!-- Channel rules (media type, size) come back under several keys. -->
                    <InputError :message="Object.values(form.errors)[0]" />
                </fieldset>

                <div class="flex flex-col gap-1.5">
                    <Label for="marmara-designs-post-content">{{ t('post.content') }}</Label>
                    <Textarea id="marmara-designs-post-content" v-model="form.content" rows="4" />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.social_account_ids.length === 0"
                        data-testid="marmara-designs-post-submit"
                    >
                        <IconSend class="size-4" />
                        {{ t('post.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
