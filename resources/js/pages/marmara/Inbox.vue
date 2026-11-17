<script setup lang="ts">
// Marmara AI fork: Inertia resolves pages from this folder only; the page
// itself lives in resources/js/marmara/inbox (app/Marmara/README.md, Inbox).
import InboxPage from '@/marmara/inbox/InboxPage.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <InboxPage v-bind="$attrs" />
</template>
