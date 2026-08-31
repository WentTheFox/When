/**
 * A viewer's own invite code (seen once on a `/free/{token}` page they were
 * shared, via ShareLinkController's `inviteCode` prop) is remembered in
 * sessionStorage so SiteFooter.vue's "Create your own calendar" link keeps
 * working even after they've navigated elsewhere in the app — not just on
 * the page that happened to hand it to them. Session-scoped (not
 * localStorage) deliberately: this is a courtesy for the current visit, not
 * something that should outlive it.
 */
const STORAGE_KEY = 'wtf.inviteCode';

export function rememberInviteCode(code: string): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, code);
  } catch {
    // Storage disabled (private browsing, etc.) — the link just won't persist.
  }
}

export function getRememberedInviteCode(): string | null {
  try {
    return sessionStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}
