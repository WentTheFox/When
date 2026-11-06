/**
 * The "url" custom field type. The value is an ordinary E2EE value, so the server can't validate it;
 * the browser is the only place that can. Only http(s) links are accepted: anything else
 * (javascript:, data:, file:…) is refused so the "open in a new tab" button can never be made to run one.
 */

/** The normalized link for a typed value, or null when it isn't an http(s) URL. Empty input is not a URL. */
export function parseWebUrl(value: string | null | undefined): string | null {
  const text = (value ?? '').trim();
  if (text === '') return null;

  try {
    const url = new URL(text);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}

/** True when the value is empty (nothing to validate) or a valid http(s) URL. */
export function isValidWebUrl(value: string | null | undefined): boolean {
  return (value ?? '').trim() === '' || parseWebUrl(value) !== null;
}
