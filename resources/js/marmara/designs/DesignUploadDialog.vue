<script setup lang="ts">
/**
 * Marmara AI fork: "Yükle" — puts a file into the shared Drive folder through
 * DesignUploader (app/Marmara/README.md, Designs).
 */
import { useForm } from '@inertiajs/vue3';
import { IconUpload } from '@tabler/icons-vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import type { DriveUpload, Translate } from './types';

const props = defineProps<{
    t: Translate;
    drive: DriveUpload;
    folders: string[];
    url: string;
    defaultFolder: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    file: null as File | null,
    folder: props.defaultFolder ?? '',
    title: '',
});

const pick = (event: Event): void => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.file = file;

    if (file && form.title === '') {
        form.title = file.name.replace(/\.[A-Za-z0-9]{2,5}$/, '');
    }
};

const submit = (): void => {
    form.post(props.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-testid="marmara-designs-upload-dialog">
            <DialogHeader>
                <DialogTitle>{{ t('upload.title') }}</DialogTitle>
                <DialogDescription>{{ t('upload.too_large', { size: drive.max_mb }) }}</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-3" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="marmara-designs-upload-file">{{ t('upload.file') }}</Label>
                    <input
                        id="marmara-designs-upload-file"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf,video/*"
                        class="text-sm file:mr-3 file:h-8 file:rounded-md file:border file:border-border-strong file:bg-transparent file:px-3 file:text-sm"
                        data-testid="marmara-designs-upload-file"
                        @change="pick"
                    />
                    <InputError :message="form.errors.file" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="marmara-designs-upload-title">{{ t('upload.name') }}</Label>
                    <Input id="marmara-designs-upload-title" v-model="form.title" maxlength="200" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="marmara-designs-upload-folder">{{ t('upload.folder') }}</Label>
                    <Input id="marmara-designs-upload-folder" v-model="form.folder" list="marmara-designs-folders" maxlength="500" />
                    <datalist id="marmara-designs-folders">
                        <option v-for="folder in folders" :key="folder" :value="folder" />
                    </datalist>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing || !form.file" data-testid="marmara-designs-upload-submit">
                        <IconUpload class="size-4" />
                        {{ t('upload.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
