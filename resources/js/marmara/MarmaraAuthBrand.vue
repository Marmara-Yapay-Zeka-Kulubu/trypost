<script setup lang="ts">
/**
 * Marmara AI fork: the club's header on the guest (auth) screens. With
 * MARMARA_BRANDING off it renders its slot (upstream's TryPost logo) and nothing
 * else. See app/Marmara/README.md.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import { activeLocale } from '@/language';
import { portalBranding } from '@/marmara/portal';

const page = usePage();

const branding = computed(() =>
    portalBranding(page.props as Record<string, unknown>),
);

// Two strings of the module's own; club texts (name, tagline) come from config.
const STRINGS: Record<string, { poweredBy: string; source: string }> = {
    tr: { poweredBy: 'TryPost ile çalışır', source: 'Kaynak kodu' },
    en: { poweredBy: 'Powered by TryPost', source: 'Source code' },
};

const strings = computed(
    () => STRINGS[activeLocale.value.split('-')[0]] ?? STRINGS.en,
);

// The accent only overrides TryPost's primary tokens while a guest screen is
// on display; leaving the layout (e.g. after logging in) removes it again.
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
    <slot v-if="!branding" />
    <template v-else>
        <div
            class="absolute start-4 top-6 flex items-center gap-3 text-xs text-muted-foreground sm:start-6 sm:top-8"
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
                v-if="branding.sourceUrl"
                :href="branding.sourceUrl"
                target="_blank"
                rel="noopener"
                class="hidden underline-offset-4 transition-control hover:text-foreground hover:underline sm:inline"
            >
                {{ strings.source }}
            </a>
        </div>

        <div
            class="flex flex-col items-center gap-3"
            data-testid="marmara-brand"
        >
            <template v-if="branding.logo">
                <img
                    :src="branding.logo"
                    alt=""
                    :class="[
                        'h-14 w-auto max-w-[240px] object-contain',
                        branding.logoDark ? 'dark:hidden' : '',
                    ]"
                    data-testid="marmara-logo"
                />
                <img
                    v-if="branding.logoDark"
                    :src="branding.logoDark"
                    alt=""
                    class="hidden h-14 w-auto max-w-[240px] object-contain dark:block"
                />
            </template>
            <div class="flex flex-col items-center gap-0.5">
                <p
                    :class="[
                        'font-heading font-semibold tracking-tight text-balance text-foreground',
                        branding.logo ? 'text-base' : 'text-xl',
                    ]"
                    data-testid="marmara-brand-name"
                >
                    {{ branding.name }}
                </p>
                <p
                    v-if="branding.tagline"
                    class="text-sm text-muted-foreground"
                >
                    {{ branding.tagline }}
                </p>
            </div>
            <span class="mt-1 h-px w-10 bg-border-strong" aria-hidden="true" />
        </div>
    </template>
</template>

<style>
/*
 * MARMARA_BRAND_ACCENT on the guest screens. Only TryPost's primary tokens are
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
