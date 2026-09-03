<script setup lang="ts">
/**
 * Rendered directly by bootstrap/app.php's withExceptions() callback for
 * every 503 raised by `php artisan down` — not a normal Inertia navigation
 * target, so it never receives the usual shared props (auth, colorPalette,
 * appName from HandleInertiaRequests, ...) since that middleware hasn't run
 * yet at that point in the request lifecycle. Everything this page needs is
 * passed explicitly by that callback instead, and it deliberately avoids
 * SiteHeader/SiteFooter/PublicLayout for the same reason — those read
 * shared props (and isFirstUser is a DB query besides, unwise to depend on
 * while the app might be mid-migration).
 */
import { faLanguage, faRotate } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { Head } from '@inertiajs/vue3';
import { BButton, BCard, BDropdown, BDropdownItem } from 'bootstrap-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import logoUrl from '../../../img/When.svg';
import { useTheme } from '../../composables/useTheme';

const props = defineProps<{
  appName: string;
  locale: string;
  textDirection: 'ltr' | 'rtl';
  locales: { code: string; native: string }[];
}>();

// Unlike LanguageSwitcher.vue (which only ever runs on /free/{token} and
// rewrites that fixed path shape), this page can be showing for literally
// any URL — maintenance mode intercepts every route — so there's no
// "/free/" segment to anchor on. Reuses the same locale-prefix convention
// instead (props.locale is exactly what bootstrap/app.php derived from the
// URL's first segment), just stripping/prepending whichever prefix that
// was. A plain full-navigation <a>, not an Inertia visit: the whole point
// is picking up the new lang/{code}.json at boot (app.ts's initialLocale),
// which only happens on a fresh page load.
const currentLocale = computed(() => (
  props.locales.find((l) => l.code === props.locale) ?? props.locales[0]
));

function hrefFor(code: string): string {
  const { pathname, search, hash } = window.location;
  const rest = props.locale === 'en' ? pathname : (pathname.replace(`/${props.locale}`, '') || '/');
  return (code === 'en' ? rest : `/${code}${rest}`) + search + hash;
}

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

  <div class="wtf-maintenance-backdrop" :dir="props.textDirection">
    <div class="container py-5">
      <div class="row justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-5">
          <div class="d-flex justify-content-end mb-2">
            <BDropdown variant="link" toggle-class="text-body-secondary" no-caret size="sm">
              <template #button-content>
                <FontAwesomeIcon :icon="faLanguage" class="me-1" />{{ currentLocale?.native }}
              </template>
              <BDropdownItem
                v-for="l in props.locales"
                :key="l.code"
                :href="hrefFor(l.code)"
                :active="l.code === props.locale"
                :disabled="l.code === props.locale"
              >
                {{ l.native }}
              </BDropdownItem>
            </BDropdown>
          </div>

          <div class="text-center mb-4">
            <img :src="logoUrl" alt="" width="40" height="40" class="mb-2">
            <div class="fs-5 fw-semibold">{{ props.appName }}</div>
          </div>

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
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wtf-maintenance-backdrop {
  min-height: 100vh;
  display: flex;
  align-items: center;
}
</style>
