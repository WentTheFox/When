import { describe, expect, it } from 'vitest';
import { invalidLinkLines, isValidWebUrl, parseWebUrl, validLinks } from '../urlField';

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

describe('link lists', () => {
  it('reports the lines that are not http(s) links, ignoring blanks', () => {
    expect(invalidLinkLines('https://a.example\n\n javascript:alert(1) \nexample.com\n')).toEqual(['javascript:alert(1)', 'example.com']);
    expect(invalidLinkLines('')).toEqual([]);
    expect(invalidLinkLines(null)).toEqual([]);
  });

  it('lists the valid links once each, normalized', () => {
    expect(validLinks('https://a.example\nnope\nhttps://a.example\nhttp://b.example')).toEqual(['https://a.example/', 'http://b.example/']);
  });
});
