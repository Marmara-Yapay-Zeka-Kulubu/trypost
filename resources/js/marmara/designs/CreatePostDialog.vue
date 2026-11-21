<script setup lang="ts">
/**
 * Marmara AI fork: "Gönderi oluştur" — a draft with the design's original
 * attached byte for byte, for the channels picked here (app/Marmara/README.md,
 * Designs). The server downloads the original from Drive.
 */
import { useForm } from '@inertiajs/vue3';
import { IconSend } from '@tabler/icons-vue';

import InputError from '@/components/InputError.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
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
}>();

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
    form.post(props.url, { preserveScroll: true });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-testid="marmara-designs-post-dialog">
            <DialogHeader>
                <DialogTitle>{{ t('post.title') }}</DialogTitle>
                <DialogDescription>{{ t('post.description') }}</DialogDescription>
            </DialogHeader>

            <p v-if="channels.length === 0" class="text-sm text-muted-foreground">{{ t('post.no_channels') }}</p>

            <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                <fieldset class="flex flex-col gap-1.5">
                    <legend class="mb-1 text-sm font-medium">{{ t('post.channels') }}</legend>
                    <label
                        v-for="channel in channels"
                        :key="channel.id"
                        class="flex h-8 cursor-pointer items-center gap-2 rounded-md px-1 text-sm hover:bg-accent"
                        :class="{ 'opacity-60': !channel.connected }"
                    >
                        <input
                            type="checkbox"
                            class="size-4"
                            :checked="form.social_account_ids.includes(channel.id)"
                            :data-testid="`marmara-designs-post-channel-${channel.id}`"
                            @change="toggle(channel.id, ($event.target as HTMLInputElement).checked)"
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
