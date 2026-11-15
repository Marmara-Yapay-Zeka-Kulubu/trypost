<script setup lang="ts">
/**
 * Marmara AI fork: the club's frame around TryPost's auth pages (login,
 * register, password reset, email verification, invites...). The pages
 * themselves are upstream's and render in the default slot. Used instead of
 * AuthSplitLayout while MARMARA_BRANDING is on (see AuthLayoutSwitch.vue).
 * There is no reviews panel; `panel` is accepted and ignored.
 */
import { usePage } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import LanguageSelect from '@/components/LanguageSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useGuestLocale } from '@/composables/useGuestLocale';
import { activeLocale } from '@/language';
import { portalBranding } from '@/marmara/portal';
import type { Auth } from '@/types';

const widthClass = {
    sm: 'max-w-[420px]',
    md: 'max-w-xl',
} as const;

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        status?: string;
        panel?: boolean;
        width?: keyof typeof widthClass;
    }>(),
    {
        title: undefined,
        description: undefined,
        status: undefined,
        panel: false,
        width: 'sm',
    },
);

const page = usePage();

const isGuest = computed(() => !(page.props.auth as Auth).user);
const { locale, languages } = useGuestLocale();

const branding = computed(() =>
    portalBranding(page.props as Record<string, unknown>),
);

// The module's own two strings; the club's texts come from config.
const STRINGS: Record<string, { poweredBy: string; source: string }> = {
    tr: { poweredBy: 'TryPost ile çalışır', source: 'Kaynak kodu' },
    en: { poweredBy: 'Powered by TryPost', source: 'Source code' },
};

const strings = computed(
    () => STRINGS[activeLocale.value.split('-')[0]] ?? STRINGS.en,
);

// MARMARA_BRAND_ACCENT remaps TryPost's primary tokens only while this frame
// is on screen; leaving it (e.g. after logging in) restores them.
const applyAccent = (accent: string | null | undefined): void => {
    const root = document.documentElement;

    if (accent) {
        root.style.setProperty('--marmara-accent', accent);
        root.classList.add('marmara-accent');
    } else {
        root.style.removeProperty('--marmara-accent');
        root.classList.remove('marmara-accent');
    }
};

onMounted(() => {
    watch(() => branding.value?.accent, applyAccent, { immediate: true });
});

onBeforeUnmount(() => applyAccent(null));
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col bg-muted"
        data-testid="marmara-auth-layout"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-primary-subtle/70 to-transparent dark:from-primary-subtle/30"
        />

        <header
            class="relative flex items-center justify-end px-4 pt-4 sm:px-6 sm:pt-6"
        >
            <LanguageSelect
                v-if="isGuest"
                v-model="locale"
                :languages="languages"
                :label="$t('settings.preferences.language.heading')"
                testid="language-picker"
                trigger-class="bg-card"
            />
        </header>

        <main
            class="relative flex flex-1 flex-col items-center px-4 py-8 sm:px-8 sm:py-12"
        >
            <div
                :class="[
                    'my-auto flex w-full flex-col gap-6',
                    widthClass[width],
                ]"
                data-testid="auth-content"
            >
                <div
                    v-if="branding"
                    class="flex flex-col items-center gap-3 text-center"
                    data-testid="marmara-brand"
                >
                    <template v-if="branding.logo">
                        <img
                            :src="branding.logo"
                            alt=""
                            :class="[
                                'h-16 w-auto max-w-[260px] object-contain',
                                branding.logoDark ? 'dark:hidden' : '',
                            ]"
                            data-testid="marmara-logo"
                        />
                        <img
                            v-if="branding.logoDark"
                            :src="branding.logoDark"
                            alt=""
                            class="hidden h-16 w-auto max-w-[260px] object-contain dark:block"
                        />
                    </template>
                    <div class="flex flex-col items-center gap-1">
                        <p
                            :class="[
                                'font-heading font-semibold tracking-tight text-balance text-foreground',
                                branding.logo
                                    ? 'text-lg'
                                    : 'text-2xl leading-tight sm:text-[28px]',
                            ]"
                            data-testid="marmara-brand-name"
                        >
                            {{ branding.name }}
                        </p>
                        <p
                            v-if="branding.tagline"
                            class="text-sm text-muted-foreground"
                            data-testid="marmara-brand-tagline"
                        >
                            {{ branding.tagline }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-6 rounded-2xl border border-border bg-card p-6 shadow-sm sm:p-8"
                    data-testid="marmara-auth-card"
                >
                    <div
                        v-if="title || description"
                        class="flex flex-col gap-1 text-center"
                    >
                        <h1
                            v-if="title"
                            class="font-heading text-xl font-medium tracking-tight text-balance text-foreground"
                            data-testid="auth-title"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-balance text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <Alert
                        v-if="status"
                        class="[&>svg]:text-success-text"
                        data-testid="auth-status"
                    >
                        <IconCircleCheck />
                        <AlertDescription>{{ status }}</AlertDescription>
                    </Alert>

                    <slot />
                </div>

                <div v-if="$slots.footer" data-testid="auth-footer">
                    <slot name="footer" />
                </div>
            </div>
        </main>

        <footer
            class="relative flex flex-wrap items-center justify-center gap-x-4 gap-y-1 px-4 pb-6 text-xs text-muted-foreground"
            data-testid="marmara-credits"
        >
            <a
                href="https://trypost.it"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1.5 transition-control hover:text-foreground"
            >
                <AppLogo
                    variant="mark"
                    class="text-[13px]"
                    aria-hidden="true"
                />
                <span>{{ strings.poweredBy }}</span>
            </a>
            <a
                v-if="branding?.sourceUrl"
                :href="branding.sourceUrl"
                target="_blank"
                rel="noopener"
                class="underline-offset-4 transition-control hover:text-foreground hover:underline"
                data-testid="marmara-source-link"
            >
                {{ strings.source }}
            </a>
        </footer>
    </div>
</template>

<style>
/*
 * MARMARA_BRAND_ACCENT on the auth screens. Only TryPost's primary tokens are
 * remapped; pick a colour dark enough for white button text in light mode.
 */
html.marmara-accent {
    --primary-strong: var(--marmara-accent);
    --primary-text: var(--marmara-accent);
    --primary-text-hover: color-mix(in oklab, var(--marmara-accent) 78%, black);
    --primary-subtle: color-mix(in oklab, var(--marmara-accent) 10%, white);
    --primary-selected: color-mix(
        in oklab,
        var(--marmara-accent) 20%,
        transparent
    );
}

html.dark.marmara-accent {
    --primary: color-mix(in oklab, var(--marmara-accent) 55%, white);
    --primary-hover: color-mix(in oklab, var(--marmara-accent) 40%, white);
    --primary-strong: color-mix(in oklab, var(--marmara-accent) 70%, white);
    --primary-text: color-mix(in oklab, var(--marmara-accent) 55%, white);
    --primary-text-hover: color-mix(in oklab, var(--marmara-accent) 40%, white);
    --primary-subtle: color-mix(in oklab, var(--marmara-accent) 22%, black);
}
</style>
