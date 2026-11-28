<script setup lang="ts">
/**
 * A small round profile picture of a connection's linked account (Discord / VRChat / Telegram), shown before its name
 * in lists. Draws nothing without a picture, so callers can put it in front of every name without checking.
 */
defineProps<{ src?: string | null; size?: string }>();
</script>

<template>
  <img
    v-if="src"
    :src="src"
    alt=""
    class="wtf-connection-avatar"
    :style="{ width: size ?? '1.5rem', height: size ?? '1.5rem' }"
    loading="lazy"
    referrerpolicy="no-referrer"
    aria-hidden="true"
  >
</template>

<style scoped>
.wtf-connection-avatar {
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  vertical-align: middle;
}
</style>
