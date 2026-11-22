<script setup lang="ts">
/**
 * Marmara AI fork: the short notice admins (the club lead) see while uploads
 * from the site still need a step: connect the lead's Google account
 * (drive.file), then pick the shared folder once in the Google Picker. Shown
 * only for `needs_connection` and `needs_folder` (see
 * DesignsPageController::driveUpload); the notice is styled like TryPost's
 * own info notices (ImportCoverage). See app/Marmara/README.md, Designs.
 */
import { router, usePage } from '@inertiajs/vue3';
import { IconBrandGoogleDrive, IconFolderPlus, IconPlugConnected } from '@tabler/icons-vue';
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
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg bg-info-subtle px-4 py-3 text-sm text-foreground"
        role="status"
        data-testid="marmara-designs-drive-panel"
    >
        <IconBrandGoogleDrive class="size-4 shrink-0 text-info-text" aria-hidden="true" />
        <span class="min-w-0 flex-1">
            {{ drive.state === 'needs_folder' ? t('drive.notice_folder') : t('drive.notice_connect') }}
        </span>

        <Button
            v-if="drive.state === 'needs_connection'"
            as="a"
            :href="urls.drive_connect"
            variant="outline"
            size="sm"
            class="bg-card"
            data-testid="marmara-designs-drive-connect"
        >
            <IconPlugConnected class="size-4" />
            {{ t('drive.connect') }}
        </Button>
        <Button
            v-else-if="drive.state === 'needs_folder'"
            :variant="highlightPick ? 'default' : 'outline'"
            size="sm"
            :class="highlightPick ? '' : 'bg-card'"
            :disabled="picking"
            data-testid="marmara-designs-drive-grant"
            @click="grantFolder"
        >
            <IconFolderPlus class="size-4" />
            {{ t('drive.grant_folder') }}
        </Button>
    </div>
</template>
