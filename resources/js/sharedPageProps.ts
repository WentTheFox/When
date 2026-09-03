import type { ColorSlot, ColorSwatch } from './free/color-palette';
import type { IconOption, IconSlot } from './free/icon-palette';
import type { NowColorPreset } from './free/now-color-presets';

/** One row of App\Support\Locales::forFrontend(). */
export interface LocaleOption {
  code: string;
  native: string;
}

/**
 * Every prop HandleInertiaRequests::share() sends on every request. Pass
 * this explicitly to usePage<SharedPageProps>() at each call site — trying
 * to get it applied automatically via @inertiajs/core's documented
 * `declare module '@inertiajs/core' { interface InertiaConfig {...} }`
 * merge point didn't actually flow through into usePage()'s return type
 * under this project's TS/vue-tsc versions (verified: with the merge in
 * place, `usePage().props.auth` still typechecked as `unknown`), so this
 * takes the always-correct explicit-generic route instead.
 */
export interface SharedPageProps {
  // Satisfies usePage<T extends PageProps>()'s own PageProps constraint
  // ({ [key: string]: unknown }) — every named field below is still
  // narrowly typed for actual access, this only affects unlisted keys.
  [key: string]: unknown;
  appName: string;
  isFirstUser: boolean;
  colorPalette: {
    swatches: ColorSwatch[];
    defaults: Record<ColorSlot, string>;
  };
  iconPalette: {
    icons: IconOption[];
    defaults: Record<IconSlot, string>;
  };
  nowColorPresets: {
    presets: NowColorPreset[];
    defaultKey: string;
  };
  locales: LocaleOption[];
  auth: {
    user: {
      name: string;
      avatarUrl: string;
      accentColorKey: string | null;
      secondaryColorKey: string | null;
      sleepColorKey: string | null;
      busyColorKey: string | null;
      workColorKey: string | null;
      schoolColorKey: string | null;
      freeColorKey: string | null;
    } | null;
  };
  flash: {
    status: string | null;
    recoveryCodes: string[] | null;
  };
}
