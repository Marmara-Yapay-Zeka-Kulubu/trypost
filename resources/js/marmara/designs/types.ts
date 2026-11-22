/**
 * Marmara AI fork: the shapes the designs page receives (DesignPayload and
 * DesignsPageController, app/Marmara/README.md, Designs).
 */
export type DesignStatus = 'draft' | 'in_review' | 'approved' | 'used' | 'archived';

export interface DesignItem {
    id: string;
    title: string;
    name: string;
    kind: 'image' | 'pdf' | 'video' | 'drawing';
    mime_type: string;
    status: DesignStatus;
    status_label: string;
    /** content_changed: the approved file changed and is back in review. */
    status_reason: string | null;
    changed_after_use_at: string | null;
    folder: string;
    uploader: { name: string | null };
    tags: string[];
    votes: number;
    comments: number;
    last_commented_at: string | null;
    width: number | null;
    height: number | null;
    size: number | null;
    created_at: string | null;
    modified_at: string | null;
    family_id: string | null;
    removed: boolean;
    drive_url: string | null;
    url: string;
    thumbnail_url: string | null;
    preview_url: string | null;
    voted?: boolean;
    versions_count?: number;
}

export interface DesignComment {
    id: string;
    body: string;
    author: { id: string; name: string } | null;
    created_at: string;
    edited_at: string | null;
    mine: boolean;
}

export interface DesignVersion {
    key: string;
    label: string;
    design_id: string;
    revision_id: string | null;
    title: string;
    modified_at: string | null;
    author: { name: string | null };
    kept: boolean;
    is_current: boolean;
    is_this_file: boolean;
    thumbnail_url: string | null;
    preview_url: string | null;
}

export interface DesignActivity {
    id: number;
    type: string;
    data: Record<string, unknown>;
    user: { name: string } | null;
    created_at: string;
}

export interface SelectedDesign extends DesignItem {
    modifier: { name: string | null };
    owner_email: string | null;
    modifier_email: string | null;
    comments_list: DesignComment[];
    versions: DesignVersion[];
    activity: DesignActivity[];
    voted: boolean;
    can: { statuses: DesignStatus[]; create_post: boolean };
}

export interface PostChannel {
    id: string;
    name: string | null;
    platform: string;
    connected: boolean;
}

export interface LinkCandidate {
    id: string;
    title: string;
    folder: string;
}

/**
 * The upload UI's state (DesignsPageController::driveUpload): `off` shows
 * nothing, `needs_connection` and `needs_folder` show admins a notice, and
 * `ready` shows "Yükle" to members who may create posts (`can_upload`).
 */
export interface DriveUpload {
    state: 'off' | 'needs_connection' | 'needs_folder' | 'ready';
    can_upload: boolean;
    can_manage: boolean;
    email: string | null;
    picker_configured: boolean;
    max_mb: number;
}

export interface FilterOption {
    value: string;
    label: string;
}

export type Copy = Record<string, unknown>;

export type Translate = (key: string, replace?: Record<string, string | number>) => string;

export const makeTranslator =
    (copy: () => Copy): Translate =>
    (key, replace = {}) => {
        const value = key
            .split('.')
            .reduce<unknown>(
                (node, part) =>
                    node && typeof node === 'object'
                        ? (node as Record<string, unknown>)[part]
                        : undefined,
                copy(),
            );
        let text = typeof value === 'string' ? value : key;

        for (const [name, replacement] of Object.entries(replace)) {
            text = text.replaceAll(`:${name}`, String(replacement));
        }

        return text;
    };

export const formatDateTime = (iso: string | null): string =>
    iso
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(iso))
        : '';

export const formatBytes = (bytes: number | null): string => {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

export const fill = (template: string, id: string, extra: Record<string, string> = {}): string =>
    Object.entries(extra).reduce(
        (url, [placeholder, value]) => url.replace(placeholder, value),
        template.replace('__ID__', id),
    );
