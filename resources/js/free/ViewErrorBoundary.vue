<script setup lang="ts">
import { computed, onErrorCaptured, ref } from 'vue';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';

/**
 * Wraps the availability views (week/month/agenda). A render/setup/watcher
 * error inside any descendant would otherwise leave the slot silently blank
 * (Vue unmounts the failed subtree), which is undiagnosable from a screenshot
 * on someone else's device — so show the error, its stack, the failing
 * component and the browser environment instead. Only technical detail is
 * shown; nothing here comes from the owner's calendar data beyond what the
 * error message itself may contain.
 */
const props = defineProps<{ label: string }>();

const error = ref<{ name: string; message: string; stack: string; info: string; component: string } | null>(null);
const copied = ref(false);

onErrorCaptured((err, instance, info) => {
  const e = err instanceof Error ? err : new Error(String(err));
  error.value = {
    name: e.name,
    message: e.message,
    stack: e.stack ?? '(no stack)',
    info,
    component: instance?.$options?.__name ?? instance?.$options?.name ?? '(unknown)',
  };
  console.error(`[${props.label}] render error`, err);
  return false;
});

const environment = computed(() => {
  const nav = typeof navigator !== 'undefined' ? navigator : undefined;
  let tz = '?';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch { /* ignore */ }
  return [
    `URL: ${typeof location !== 'undefined' ? location.href.replace(/\/free\/[^/?#]+/, '/free/<token>') : '?'}`,
    `Time: ${new Date().toISOString()}`,
    `User agent: ${nav?.userAgent ?? '?'}`,
    `Language: ${nav?.language ?? '?'}`,
    `Timezone: ${tz}`,
    `Viewport: ${typeof window !== 'undefined' ? `${window.innerWidth}x${window.innerHeight} @${window.devicePixelRatio}x` : '?'}`,
    `color-mix: ${typeof CSS !== 'undefined' && CSS.supports?.('color', 'color-mix(in srgb, red, blue)')}`,
    `Array.at: ${typeof [].at === 'function'}`,
  ].join('\n');
});

const report = computed(() => {
  const e = error.value;
  if (!e) return '';
  return [
    `View: ${props.label}`,
    `Component: ${e.component}`,
    `Hook: ${e.info}`,
    `Error: ${e.name}: ${e.message}`,
    '',
    'Stack:',
    e.stack,
    '',
    environment.value,
  ].join('\n');
});

async function copy() {
  try {
    await navigator.clipboard.writeText(report.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch {
    /* clipboard unavailable — the text is selectable below */
  }
}
</script>

<template>
  <div v-if="error" class="alert alert-danger" role="alert">
    <h2 class="h5 mb-2">
      <FontAwesomeIcon :icon="faTriangleExclamation" class="me-2" />{{ $t('free.viewError.title') }}
    </h2>
    <p class="mb-2">{{ $t('free.viewError.intro') }}</p>
    <pre class="small mb-2 p-2 rounded border" style="white-space: pre-wrap; word-break: break-word; user-select: all; max-height: 24rem; overflow: auto; color: inherit; background: transparent;" dir="ltr">{{ report }}</pre>
    <button type="button" class="btn btn-sm btn-outline-danger" @click="copy">
      {{ copied ? $t('free.viewError.copied') : $t('free.viewError.copy') }}
    </button>
  </div>
  <slot v-else />
</template>
