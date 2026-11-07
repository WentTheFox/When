/**
 * The `/free/{token}` link a viewer arrived through is remembered in
 * sessionStorage so SiteFooter.vue's "Create your own calendar" link keeps
 * working even after they've navigated elsewhere in the app. The footer
 * points at `/invite/{token}`, which creates (or reuses) an invite only when
 * actually clicked — merely viewing a calendar never issues one. Session-
 * scoped (not localStorage) deliberately: this is a courtesy for the current
 * visit, not something that should outlive it.
 */
const STORAGE_KEY = 'wtf.shareLinkToken';

export function rememberShareLinkToken(token: string): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, token);
  } catch {
    // Storage disabled (private browsing, etc.) — the link just won't persist.
  }
}

export function getRememberedShareLinkToken(): string | null {
  try {
    return sessionStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}
