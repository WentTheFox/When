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

/** The "link_list" type: one http(s) link per line, stored like a "list" (see encryptedList.ts). */

/** The non-blank lines of a link list's text, trimmed. */
function linkLines(text: string | null | undefined): string[] {
  return (text ?? '').split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '');
}

/** The lines that are not http(s) links; empty when the list is fine to save. */
export function invalidLinkLines(text: string | null | undefined): string[] {
  return linkLines(text).filter((line) => parseWebUrl(line) === null);
}

/** The valid links in a link list's text, normalized, repeats dropped. */
export function validLinks(text: string | null | undefined): string[] {
  return [...new Set(linkLines(text).map((line) => parseWebUrl(line)).filter((href): href is string => href !== null))];
}
