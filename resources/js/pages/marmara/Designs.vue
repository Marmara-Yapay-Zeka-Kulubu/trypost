<script setup lang="ts">
// Marmara AI fork: Inertia resolves pages from this folder only; the page
// itself lives in resources/js/marmara/designs (app/Marmara/README.md, Designs).
import DesignsPage from '@/marmara/designs/DesignsPage.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <DesignsPage v-bind="$attrs" />
</template>
