import { createInertiaApp } from '@inertiajs/vue3';
import { BApp, createBootstrap } from 'bootstrap-vue-next';
import { i18nVue } from 'laravel-vue-i18n';
import { createApp, h, type DefineComponent } from 'vue';
import './bootstrap';
import './icons';
import { setColorPalette } from './free/color-palette';
import { setIconPalette } from './free/icon-palette';
import { setNowColorPresets } from './free/now-color-presets';

// bootstrap-vue-next ships components, not CSS — resources/css/app.css's own
// bootstrap/dist/css/bootstrap.min.css import still supplies all the actual
// styling, this plugin just renders the same classes from Vue components.

const langJsonImporters = import.meta.glob<{ default: Record<string, string> }>('../../lang/*.json');

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue', { eager: true });
    const page = pages[`./Pages/${name}.vue`];
    if (!page) {
      throw new Error(`Page not found: ./Pages/${name}.vue`);
    }
    return page.default;
  },
  setup({ el, App, props, plugin }) {
    // Seeded once here, synchronously, before anything mounts — every page
    // that resolves a color-palette key to a hex (Settings.vue's swatch
    // picker, DashboardLayout.vue, Free/Show.vue) reads it back out of
    // color-palette.ts's own module state rather than each threading the
    // shared prop through themselves. The palette itself never changes
    // per-navigation (it's not per-user data), so a single seed at boot is
    // enough — no need to re-sync on every subsequent Inertia visit.
    const colorPalette = props.initialPage.props.colorPalette as
      { swatches: Parameters<typeof setColorPalette>[0]; defaults: Parameters<typeof setColorPalette>[1] }
      | undefined;
    if (colorPalette) {
      setColorPalette(colorPalette.swatches, colorPalette.defaults);
    }

    // Same seed-once-at-boot reasoning as colorPalette above.
    const iconPalette = props.initialPage.props.iconPalette as
      { icons: Parameters<typeof setIconPalette>[0]; defaults: Parameters<typeof setIconPalette>[1] }
      | undefined;
    if (iconPalette) {
      setIconPalette(iconPalette.icons, iconPalette.defaults);
    }

    // Same seed-once-at-boot reasoning as colorPalette above.
    const nowColorPresets = props.initialPage.props.nowColorPresets as
      { presets: Parameters<typeof setNowColorPresets>[0]; defaultKey: string }
      | undefined;
    if (nowColorPresets) {
      setNowColorPresets(nowColorPresets.presets, nowColorPresets.defaultKey);
    }

    // The initial page's own `locale` prop (e.g. /hu/free/{token} sends
    // 'hu') — read synchronously here instead of always booting 'en' and
    // relying on a page's post-mount loadLanguageAsync() call to correct
    // it. That async correction still exists (Free/Show.vue calls it for
    // client-side navigations within the SPA), but boot-time i18n install
    // was hardcoded to 'en' regardless, so the very first paint of a
    // non-English page briefly rendered (and could stay, if something
    // downstream missed the reactive update) in English.
    const initialLocale = (props.initialPage.props.locale as string | undefined) ?? 'en';

    // BApp is bootstrap-vue-next's recommended root wrapper — it hosts the
    // teleport targets BModal/BToast etc. render into, so it needs to wrap
    // the whole tree even for pages that don't use them yet.
    const app = createApp({ render: () => h(BApp, null, () => h(App, props)) });
    app
      .use(plugin)
      .use(createBootstrap())
      .use(i18nVue, {
        lang: initialLocale,
        fallbackLang: 'en',
        resolve: async (lang: string) => {
          const path = `../../lang/${lang}.json`;
          const importer = langJsonImporters[path];
          if (!importer) {
            throw new Error(`Could not find lang json path for lang ${lang}`);
          }
          return importer();
        },
      });
    app.mount(el);
  },
});
