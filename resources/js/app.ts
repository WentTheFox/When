import { createInertiaApp } from '@inertiajs/vue3';
import { BApp, createBootstrap } from 'bootstrap-vue-next';
import { i18nVue } from 'laravel-vue-i18n';
import { createApp, h, type DefineComponent } from 'vue';
import './bootstrap';
import './icons';
import { setColorPalette } from './free/color-palette';

// bootstrap-vue-next ships components, not CSS — resources/css/app.css's own
// bootstrap/dist/css/bootstrap.min.css import still supplies all the actual
// styling, this plugin just renders the same classes from Vue components.

// English only for now — Hungarian (lang/hu.json) is ready but there's no
// locale switcher yet to reach it; wiring one is a separate follow-up.
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

    // BApp is bootstrap-vue-next's recommended root wrapper — it hosts the
    // teleport targets BModal/BToast etc. render into, so it needs to wrap
    // the whole tree even for pages that don't use them yet.
    const app = createApp({ render: () => h(BApp, null, () => h(App, props)) });
    app
      .use(plugin)
      .use(createBootstrap())
      .use(i18nVue, {
        lang: 'en',
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
