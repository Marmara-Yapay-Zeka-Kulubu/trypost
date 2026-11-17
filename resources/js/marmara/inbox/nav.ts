/**
 * Marmara AI fork: the inbox's sidebar entry (app/Marmara/README.md, Inbox).
 * It comes from the shared `marmaraInbox` prop set by InboxServiceProvider;
 * without it (module off or not registered) there is no entry.
 */
import { IconInbox } from '@tabler/icons-vue';

import type { NavItem } from '@/types';

interface InboxShare {
    href: string;
    label: string;
    unread: number;
}

export const marmaraInboxNavItems = (
    props: Record<string, unknown>,
): NavItem[] => {
    const inbox = props.marmaraInbox as InboxShare | null | undefined;

    if (!inbox) {
        return [];
    }

    return [
        {
            title: inbox.label,
            href: inbox.href,
            icon: IconInbox,
            activePattern: '/inbox',
            ...(inbox.unread > 0
                ? { count: inbox.unread, countTestId: 'sidebar-inbox-count' }
                : {}),
        },
    ];
};
