<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed, onMounted, ref, useTemplateRef } from "vue"
import { AvatarRoot } from "reka-ui"
import { cn } from "@/lib/utils"
import { getInitials } from "@/composables/useInitials"

const props = defineProps<{
  class?: HTMLAttributes["class"]
  src?: string | null
  name?: string
  fallbackClass?: HTMLAttributes["class"]
}>()

const hasProps = computed(() => props.src !== undefined || props.name !== undefined)

/** The src that failed to load, so a broken image shows the initials and a new src is tried again. */
const failedSrc = ref<string | null>(null)
const showImage = computed(() => !!props.src && failedSrc.value !== props.src)
const image = useTemplateRef<HTMLImageElement>("image")

const markFailed = () => {
  failedSrc.value = props.src ?? null
}

// An image that already failed before hydration fired its error before the listener existed.
onMounted(() => {
  if (image.value?.complete && image.value.naturalWidth === 0) {
    markFailed()
  }
})
</script>

<template>
  <AvatarRoot
    data-slot="avatar"
    :class="cn('relative flex size-8 shrink-0 overflow-hidden rounded-full', props.class)"
  >
    <template v-if="hasProps">
      <img
        v-if="showImage"
        ref="image"
        :src="src"
        :alt="name"
        class="aspect-square size-full object-cover"
        @error="markFailed"
      />
      <div
        v-else
        :class="cn('flex size-full items-center justify-center bg-muted font-medium', props.fallbackClass)"
      >
        {{ name ? getInitials(name) : '' }}
      </div>
    </template>
    <slot v-else />
  </AvatarRoot>
</template>
