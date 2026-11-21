/**
 * Marmara AI fork (app/Marmara/README.md, Designs): the one-time Google
 * Picker step of "Drive'a yükleme izni". With the drive.file scope TryPost
 * may only write into folders the user picked in the Picker (or that TryPost
 * created), so the lead picks the shared folder here once; the server checks
 * it is the configured folder.
 */
import { loadScript } from '@/lib/mediaSources/loadScript';

export interface PickerToken {
    access_token: string;
    api_key: string | null;
    app_id: string | null;
    sdk: string;
    folder_id: string | null;
}

interface PickerApi {
    PickerBuilder: new () => PickerBuilder;
    DocsView: new (viewId?: string) => FolderView;
    ViewId: { FOLDERS: string };
    Action: { PICKED: string; CANCEL: string };
    Response: { ACTION: string; DOCUMENTS: string };
    Document: { ID: string };
}

interface FolderView {
    setIncludeFolders: (included: boolean) => FolderView;
    setSelectFolderEnabled: (enabled: boolean) => FolderView;
    setMimeTypes: (mimeTypes: string) => FolderView;
}

interface PickerBuilder {
    setAppId: (appId: string) => PickerBuilder;
    setDeveloperKey: (key: string) => PickerBuilder;
    setOAuthToken: (token: string) => PickerBuilder;
    setLocale: (locale: string) => PickerBuilder;
    setTitle: (title: string) => PickerBuilder;
    addView: (view: FolderView) => PickerBuilder;
    setCallback: (callback: (data: Record<string, unknown>) => void) => PickerBuilder;
    build: () => { setVisible: (visible: boolean) => void; dispose: () => void };
}

interface PickerWindow {
    gapi?: {
        load: (
            libraries: string,
            config: { callback: () => void; onerror: () => void },
        ) => void;
    };
    google?: { picker?: PickerApi };
}

const pickerWindow = (): PickerWindow => window as unknown as PickerWindow;

const loadPicker = async (sdk: string): Promise<PickerApi> => {
    const target = pickerWindow();

    if (!target.google?.picker) {
        if (!target.gapi) {
            await loadScript(sdk);
        }

        await new Promise<void>((resolve, reject) =>
            pickerWindow().gapi?.load('picker', {
                callback: resolve,
                onerror: () => reject(new Error('Failed to load the Google Picker')),
            }),
        );
    }

    const picker = pickerWindow().google?.picker;

    if (!picker) {
        throw new Error('Google Picker is not available');
    }

    return picker;
};

/** Opens the Picker on folders; resolves the picked folder id, or null. */
export const pickDriveFolder = async (
    token: PickerToken,
    locale: string,
    title: string,
): Promise<string | null> => {
    const picker = await loadPicker(token.sdk);

    return new Promise((resolve) => {
        const view = new picker.DocsView(picker.ViewId.FOLDERS)
            .setIncludeFolders(true)
            .setSelectFolderEnabled(true)
            .setMimeTypes('application/vnd.google-apps.folder');

        let builder = new picker.PickerBuilder()
            .setOAuthToken(token.access_token)
            .setLocale(locale)
            .setTitle(title)
            .addView(view);

        if (token.api_key) {
            builder = builder.setDeveloperKey(token.api_key);
        }

        if (token.app_id) {
            builder = builder.setAppId(token.app_id);
        }

        const instance = builder
            .setCallback((data) => {
                const action = data[picker.Response.ACTION];

                if (action !== picker.Action.PICKED && action !== picker.Action.CANCEL) {
                    return;
                }

                instance.dispose();

                const documents = data[picker.Response.DOCUMENTS];
                const first = Array.isArray(documents)
                    ? (documents[0] as Record<string, unknown> | undefined)
                    : undefined;
                const id = first?.[picker.Document.ID];

                resolve(action === picker.Action.PICKED && typeof id === 'string' ? id : null);
            })
            .build();

        instance.setVisible(true);
    });
};
