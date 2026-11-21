<script setup lang="ts">
/**
 * Marmara AI fork: "Drive'a yükleme izni" for workspace admins (the club
 * lead): connect the lead's Google account (drive.file), then pick the shared
 * folder once in the Google Picker. See app/Marmara/README.md, Designs.
 */
import { router, usePage } from '@inertiajs/vue3';
import { IconBrandGoogleDrive, IconCheck, IconFolderPlus, IconUnlink } from '@tabler/icons-vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';

import { pickDriveFolder, type PickerToken } from './drivePicker';
import type { DriveUpload, Translate } from './types';

const props = defineProps<{
    t: Translate;
    drive: DriveUpload;
    urls: Record<string, string>;
    highlightPick: boolean;
}>();

const page = usePage();
const picking = ref(false);

const grantFolder = async (): Promise<void> => {
    picking.value = true;

    try {
        const response = await fetch(props.urls.drive_picker_token, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const token = (await response.json()) as PickerToken & { message?: string };

        if (!response.ok) {
            toast.error(token.message ?? props.t('drive.folder_failed'));

            return;
        }

        const folderId = await pickDriveFolder(
            token,
            String(page.props.locale ?? 'tr'),
            props.t('drive.grant_folder'),
        );

        if (folderId) {
            router.post(props.urls.drive_folder, { folder_id: folderId }, {
                preserveScroll: true,
                onError: (errors) => toast.error(errors.folder_id ?? props.t('drive.folder_failed')),
            });
        }
    } catch {
        toast.error(props.t('drive.folder_failed'));
    } finally {
        picking.value = false;
    }
};

const disconnect = (): void => {
    router.delete(props.urls.drive_disconnect, { preserveScroll: true });
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-lg border border-border-strong bg-card p-3 text-sm"
        data-testid="marmara-designs-drive-panel"
    >
        <IconBrandGoogleDrive class="size-4 shrink-0 text-muted-foreground" />
        <span class="font-medium">{{ t('drive.section') }}</span>

        <span v-if="!drive.configured" class="text-muted-foreground">{{ t('drive.not_configured') }}</span>

        <template v-else-if="!drive.connected">
            <Button as="a" :href="urls.drive_connect" variant="outline" class="ml-auto" data-testid="marmara-designs-drive-connect">
                {{ t('drive.connect') }}
            </Button>
        </template>

        <template v-else>
            <span class="text-muted-foreground">{{ t('drive.connected_as', { email: drive.email ?? '' }) }}</span>
            <span v-if="drive.folder_granted" class="inline-flex items-center gap-1 text-muted-foreground">
                <IconCheck class="size-4" />{{ t('drive.folder_granted') }}
            </span>
            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    v-if="!drive.folder_granted"
                    :variant="highlightPick ? 'default' : 'outline'"
                    :disabled="picking"
                    data-testid="marmara-designs-drive-grant"
                    @click="grantFolder"
                >
                    <IconFolderPlus class="size-4" />
                    {{ t('drive.grant_folder') }}
                </Button>
                <Button variant="ghost" data-testid="marmara-designs-drive-disconnect" @click="disconnect">
                    <IconUnlink class="size-4" />
                    {{ t('drive.disconnect') }}
                </Button>
            </div>
        </template>
    </div>
</template>
