import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import AttributesPanel from '../AttributesPanel.vue';
import CategoriesPanel from '../CategoriesPanel.vue';
import SourcesPanel from '../SourcesPanel.vue';

// CategoriesPanel pulls in the theme composable, which needs a DOM the moment it is imported.
vi.mock('../../composables/useTheme', () => ({ useResolvedTheme: () => ({ value: 'light' }) }));

const RAW = 'cmF3LWNpcGhlcnRleHQtYmxvYg==';
const ID = '33333333-3333-4333-8333-333333333333';

function render(component: object, props: Record<string, unknown>): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(component, props) }));
}

/** What a deep link to a record looks like before the vault is unlocked: it is open, but nothing is decrypted. */
describe('opening a record before it is decrypted', () => {
  it('SourcesPanel shows the raw name, disabled, with Save and Delete off', async () => {
    const html = await render(SourcesPanel, {
      selectedId: ID,
      sources: [{ id: ID, category_id: null, name_ciphertext: RAW }],
      categories: [],
    });
    expect(html).toMatch(new RegExp(`<input[^>]*value="${RAW}"[^>]*\\bdisabled\\b`));
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*>(?:<!--.*?-->)*\s*Save/);
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*>(?:<!--.*?-->)*\s*Delete/);
    expect(html).toContain('Vault locked');
  });

  it('CategoriesPanel shows the raw name, disabled, with Save and Delete off', async () => {
    const html = await render(CategoriesPanel, {
      selectedId: ID,
      categories: [{ id: ID, color_key: null, name_ciphertext: RAW }],
    });
    expect(html).toMatch(new RegExp(`<input[^>]*value="${RAW}"[^>]*\\bdisabled\\b`));
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*>(?:<!--.*?-->)*\s*Save/);
  });

  it('AttributesPanel shows the raw label, disabled, with Save and Delete off', async () => {
    const html = await render(AttributesPanel, {
      selectedId: ID,
      definitions: [{ id: ID, type: 'text', label_ciphertext: RAW, options: [] }],
      usage: {},
      fieldRoles: { highlight: null, bypass: null },
      copyStatus: null,
      copyBusy: false,
    });
    expect(html).toMatch(new RegExp(`<input[^>]*value="${RAW}"[^>]*\\bdisabled\\b`));
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*>(?:<!--.*?-->)*\s*Delete/);
  });

  it('shows the real name, editable, once the record is decrypted', async () => {
    const html = await render(SourcesPanel, {
      selectedId: ID,
      sources: [{ id: ID, category_id: null, name_ciphertext: RAW, label: 'Conference' }],
      categories: [],
    });
    expect(html).toContain('Conference');
    expect(html).not.toMatch(new RegExp(`<input[^>]*value="${RAW}"`));
  });

  it('puts no overlay over the empty "select something" state — only over an open record', async () => {
    const closed = await render(SourcesPanel, { sources: [{ id: ID, category_id: null, name_ciphertext: RAW }], categories: [] });
    expect(closed).toContain('Select a source on the left');
    // Just the list's own overlay.
    expect(closed.match(/Vault locked/g)).toHaveLength(1);

    const open = await render(SourcesPanel, { selectedId: ID, sources: [{ id: ID, category_id: null, name_ciphertext: RAW }], categories: [] });
    expect(open.match(/Vault locked/g)).toHaveLength(2);
  });
});
