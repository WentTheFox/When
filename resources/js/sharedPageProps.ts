import type { ColorSlot, ColorSwatch } from './free/color-palette';

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
  auth: {
    user: {
      name: string;
      avatarUrl: string;
      accentColorKey: string | null;
      secondaryColorKey: string | null;
      sleepColorKey: string | null;
      busyColorKey: string | null;
      workColorKey: string | null;
      freeColorKey: string | null;
    } | null;
  };
  flash: {
    status: string | null;
    recoveryCodes: string[] | null;
  };
}
