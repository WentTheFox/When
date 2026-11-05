import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import LockedOverlay from '../LockedOverlay.vue';

function render(): Promise<string> {
  return renderToString(createSSRApp({
    render: () => h(LockedOverlay, null, { default: () => h('p', { class: 'wrapped' }, 'list content') }),
  }));
}

describe('LockedOverlay', () => {
  // The vault is locked by default (it only unlocks after a real passphrase round-trip).
  it('keeps the content visible but inert, with a click-to-unlock overlay on top, while locked', async () => {
    const html = await render();
    expect(html).toContain('list content');
    expect(html).toContain('locked-content');
    expect(html).toMatch(/\binert\b/);
    expect(html).toContain('aria-hidden="true"');
    expect(html).toMatch(/<button[^>]*class="locked-overlay"/);
    expect(html).toContain('Vault locked');
  });

  it('does not ask for the unlock by itself — only the overlay button does', async () => {
    const html = await render();
    // Nothing in the markup auto-opens anything; the request is bound to the button's click only.
    expect(html).toContain('<button');
    expect(html).not.toMatch(/autofocus/);
  });
});
