<script setup lang="ts">
/**
 * Rendered directly by bootstrap/app.php's withExceptions() callback for
 * every 503 raised by `php artisan down` — not a normal Inertia navigation
 * target, so it never receives the usual shared props (auth, colorPalette,
 * appName from HandleInertiaRequests, ...) since that middleware hasn't run
 * yet at that point in the request lifecycle. Everything this page needs is
 * passed explicitly by that callback instead. Uses PublicLayout directly
 * (not the usual `defineOptions({ layout: PublicLayout })` — that form
 * can't forward a prop that changes after the initial render, and `dir`
 * needs to react to the language switcher below) with its `header` slot
 * overridden: SiteHeader (PublicLayout's default header) reads
 * `auth`/`isFirstUser` off SharedPageProps, neither of which this page
 * sends, and would render its full nav-link set, which has nowhere
 * meaningful to go while the app is down. Only BrandMark.vue (logo + app
 * name) is reused there instead. PublicLayout's default footer
 * (SiteFooter) is otherwise left as-is, except its "create your own
 * account" invite CTA and "About this project" link — same reasoning as
 * the missing nav links, /register and /about are both intercepted by
 * maintenance mode too — suppressed via `show-invite-cta`/
 * `show-about-link`.
 */
import { faRotate } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { Head } from '@inertiajs/vue3';
import { BButton, BCard } from 'bootstrap-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BrandMark from '../../Components/BrandMark.vue';
import HeaderBar from '../../Components/HeaderBar.vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';
import { useTheme } from '../../composables/useTheme';

const props = defineProps<{
  appName: string;
  locale: string;
  locales: { code: string; native: string }[];
}>();

// LanguageSwitcher's `navigate: false` mode (maintenance mode intercepts
// every route, so unlike /free/{token} there's nowhere else to navigate
// to — see that component's own doc comment) reports the switch back via
// v-model instead. Mirrors App\Support\Locales::RTL (ar, he) — no
// shared-props channel exists here to read that from the server, and it's
// a two-entry list unlikely to change without this file needing a look
// anyway.
const RTL_CODES = new Set(['ar', 'he']);

const activeLocale = ref(props.locale);
const textDirection = computed(() => (RTL_CODES.has(activeLocale.value) ? 'rtl' : 'ltr'));

// Purely client-side (cookie + prefers-color-scheme), no shared props or
// DB access needed — same composable every other page uses.
useTheme();

const checking = ref(false);
const retrySeconds = ref(0);

// Exponential backoff, capped at 60s: come back on our own once the app is
// reachable again instead of leaving the visitor to keep refreshing by
// hand — this uses a plain HEAD request, not window.location.reload(),
// since a naive reload would just replay the same 503 render loop as long
// as maintenance mode is on.
const MIN_DELAY = 5;
const MAX_DELAY = 60;
let timer: ReturnType<typeof setInterval> | null = null;
let nextDelay = MIN_DELAY;

async function checkIfBackUp(): Promise<boolean> {
  try {
    const response = await fetch(window.location.href, { method: 'HEAD', cache: 'no-store' });
    return response.status !== 503;
  } catch {
    return false;
  }
}

async function attempt(): Promise<void> {
  checking.value = true;
  const backUp = await checkIfBackUp();
  if (backUp) {
    window.location.reload();
    return;
  }
  checking.value = false;
  nextDelay = Math.min(nextDelay * 2, MAX_DELAY);
  scheduleCountdown();
}

function scheduleCountdown(): void {
  retrySeconds.value = nextDelay;
  if (timer !== null) {
    clearInterval(timer);
  }
  timer = setInterval(() => {
    retrySeconds.value -= 1;
    if (retrySeconds.value <= 0) {
      if (timer !== null) {
        clearInterval(timer);
        timer = null;
      }
      void attempt();
    }
  }, 1000);
}

async function manualRetry(): Promise<void> {
  if (timer !== null) {
    clearInterval(timer);
    timer = null;
  }
  await attempt();
}

onMounted(() => {
  scheduleCountdown();
});

onUnmounted(() => {
  if (timer !== null) {
    clearInterval(timer);
  }
});
</script>

<template>
  <Head :title="$t('maintenance.title')" />

  <PublicLayout :dir="textDirection" :show-invite-cta="false" :show-about-link="false">
    <template #header>
      <HeaderBar hide-toggle>
        <BrandMark :app-name="props.appName" href="/" />

        <div class="d-flex align-items-center ms-auto">
          <LanguageSwitcher v-model="activeLocale" :locales="props.locales" :navigate="false" />
        </div>
      </HeaderBar>
    </template>

    <BCard class="text-center shadow-sm">
      <h1 class="h4">{{ $t('maintenance.heading') }}</h1>
      <p class="text-body-secondary">{{ $t('maintenance.body') }}</p>

      <p class="text-body-secondary small mb-3">
        {{ checking ? $t('maintenance.checking') : $t('maintenance.retryingIn', { seconds: String(retrySeconds) }) }}
      </p>

      <BButton variant="primary" :disabled="checking" @click="manualRetry">
        <FontAwesomeIcon :icon="faRotate" :spin="checking" class="me-2" />
        {{ $t('maintenance.retryButton') }}
      </BButton>
    </BCard>
  </PublicLayout>
</template>
