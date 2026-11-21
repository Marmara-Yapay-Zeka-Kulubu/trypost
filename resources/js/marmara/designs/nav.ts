/**
 * Marmara AI fork: the designs gallery's sidebar entry (app/Marmara/README.md,
 * Designs). It comes from the shared `marmaraDesigns` prop set by
 * DesignsServiceProvider; without it (module off or not registered) there is
 * no entry.
 */
import { IconPhoto } from '@tabler/icons-vue';

import type { NavItem } from '@/types';

interface DesignsShare {
    href: string;
    label: string;
}

export const marmaraDesignsNavItems = (
    props: Record<string, unknown>,
): NavItem[] => {
    const designs = props.marmaraDesigns as DesignsShare | null | undefined;

    if (!designs) {
        return [];
    }

    return [
        {
            title: designs.label,
            href: designs.href,
            icon: IconPhoto,
            activePattern: '/designs',
        },
    ];
};
