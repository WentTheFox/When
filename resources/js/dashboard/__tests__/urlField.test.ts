import { describe, expect, it } from 'vitest';
import { isValidWebUrl, parseWebUrl } from '../urlField';

describe('urlField', () => {
  it('accepts http and https links, trimmed', () => {
    expect(parseWebUrl(' https://example.com/a?b=1 ')).toBe('https://example.com/a?b=1');
    expect(parseWebUrl('http://example.com')).toBe('http://example.com/');
  });

  it('refuses every other scheme, so the open button cannot run script', () => {
    for (const bad of ['javascript:alert(1)', 'data:text/html,hi', 'file:///etc/passwd', 'ftp://example.com', 'mailto:a@b.c']) {
      expect(parseWebUrl(bad)).toBeNull();
      expect(isValidWebUrl(bad)).toBe(false);
    }
  });

  it('refuses text that is not a URL', () => {
    expect(parseWebUrl('example.com')).toBeNull();
    expect(parseWebUrl('not a url')).toBeNull();
  });

  it('treats empty as valid (nothing to check) but not as a link', () => {
    expect(isValidWebUrl('')).toBe(true);
    expect(isValidWebUrl('  ')).toBe(true);
    expect(isValidWebUrl(undefined)).toBe(true);
    expect(parseWebUrl('')).toBeNull();
  });
});
