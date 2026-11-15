/**
 * Marmara AI fork: the club portal module's frontend half (app/Marmara/README.md).
 * Everything comes from the shared `marmara` prop set by PortalServiceProvider;
 * without it (module not registered) every helper answers null and the upstream
 * behaviour is kept.
 */

export interface PortalBranding {
    name: string;
    tagline: string | null;
    logo: string | null;
    logoDark: string | null;
    accent: string | null;
    sourceUrl: string | null;
}

interface PortalProps {
    defaultLocale?: string | null;
    branding?: PortalBranding | null;
}

const portal = (props: Record<string, unknown>): PortalProps =>
    (props.marmara as PortalProps | undefined) ?? {};

/**
 * The club's default language (APP_LOCALE) when it is not TryPost's English.
 * A sign-up that never touched the language picker gets this instead of the
 * browser's language.
 */
export const portalDefaultLocale = (
    props: Record<string, unknown>,
): string | null => portal(props).defaultLocale ?? null;

export const portalBranding = (
    props: Record<string, unknown>,
): PortalBranding | null => portal(props).branding ?? null;
