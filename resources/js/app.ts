import { createInertiaApp } from '@inertiajs/vue3';
import { BApp, createBootstrap } from 'bootstrap-vue-next';
import { i18nVue } from 'laravel-vue-i18n';
import { createApp, h, type DefineComponent } from 'vue';
import './bootstrap';
import './icons';

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
