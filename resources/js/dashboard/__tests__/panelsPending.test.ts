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

/** Whether the button with this text exists and is disabled (checked per button, so neighbours can't leak into each other). */
function buttonState(html: string, text: string): 'disabled' | 'enabled' | 'missing' {
  const buttons = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)];
  const match = buttons.find(([, , inner]) => inner.replace(/<!--[\s\S]*?-->|<[^>]*>/g, '').trim() === text);
  if (!match) return 'missing';
  return /\bdisabled\b/.test(match[1]) ? 'disabled' : 'enabled';
}

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
    expect(buttonState(html, 'Save')).toBe('disabled');
    expect(buttonState(html, 'Delete')).toBe('disabled');
    expect(html).toContain('Vault locked');
  });

  it('CategoriesPanel shows the raw name, disabled, with Save and Delete off', async () => {
    const html = await render(CategoriesPanel, {
      selectedId: ID,
      categories: [{ id: ID, color_key: null, name_ciphertext: RAW }],
    });
    expect(html).toMatch(new RegExp(`<input[^>]*value="${RAW}"[^>]*\\bdisabled\\b`));
    expect(buttonState(html, 'Save')).toBe('disabled');
  });

  it('AttributesPanel shows the raw label, disabled, with Save and Delete off', async () => {
    const html = await render(AttributesPanel, {
      selectedId: ID,
      definitions: [{ id: ID, type: 'text', label_ciphertext: RAW, options: [] }],
      usage: {},
      fieldFeatures: {},
      copyStatus: null,
      copyBusy: false,
    });
    expect(html).toMatch(new RegExp(`<input[^>]*value="${RAW}"[^>]*\\bdisabled\\b`));
    expect(buttonState(html, 'Delete')).toBe('disabled');
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

describe('a custom field that a site feature uses', () => {
  const definition = { id: ID, type: 'identifier_list', label_ciphertext: RAW, label: 'Highlight words', options: [] };
  const base = { selectedId: ID, definitions: [definition], usage: {}, copyStatus: null, copyBusy: false };

  it('has its Delete button disabled and says why, with a way to Settings', async () => {
    const html = await render(AttributesPanel, { ...base, fieldFeatures: { [ID]: ['Highlight tokens'] } });
    expect(buttonState(html, 'Delete')).toBe('disabled');
    expect(html).toContain('Highlight tokens');
    expect(html).toMatch(/alert alert-info show/);
    expect(html).toContain('can&#39;t be deleted while a site feature uses it');
  });

  it('can be deleted when no feature uses it', async () => {
    const html = await render(AttributesPanel, { ...base, fieldFeatures: {} });
    expect(buttonState(html, 'Delete')).toBe('enabled');
    expect(html).not.toMatch(/alert alert-info show/); // the note is rendered hidden
  });

  it("shows the server's refusal if a delete is rejected anyway", async () => {
    const html = await render(AttributesPanel, { ...base, fieldFeatures: {}, removeError: 'This field is used by: Bypass DND.' });
    expect(html).toContain('This field is used by: Bypass DND.');
  });
});

/** The stored order is what renders — names being known must never re-sort a list. */
describe('list order', () => {
  const ID2 = '44444444-4444-4444-8444-444444444444';

  function order(html: string, ...names: string[]): string[] {
    return names.map((name) => [name, html.indexOf(name)] as const).filter(([, at]) => at >= 0).sort((a, b) => a[1] - b[1]).map(([name]) => name);
  }

  it('SourcesPanel renders sources in the order given, not alphabetically', async () => {
    const html = await render(SourcesPanel, {
      sources: [
        { id: ID, category_id: null, name_ciphertext: RAW, label: 'Zulu conference' },
        { id: ID2, category_id: null, name_ciphertext: RAW, label: 'Alpha meetup' },
      ],
      categories: [],
    });
    expect(order(html, 'Zulu conference', 'Alpha meetup')).toEqual(['Zulu conference', 'Alpha meetup']);
  });

  it('CategoriesPanel renders categories in the order given', async () => {
    const html = await render(CategoriesPanel, {
      categories: [
        { id: ID, color_key: null, name_ciphertext: RAW, label: 'Zulu' },
        { id: ID2, color_key: null, name_ciphertext: RAW, label: 'Alpha' },
      ],
    });
    expect(order(html, 'Zulu', 'Alpha')).toEqual(['Zulu', 'Alpha']);
  });

  it('AttributesPanel renders fields in the order given', async () => {
    const html = await render(AttributesPanel, {
      definitions: [
        { id: ID, type: 'text', label_ciphertext: RAW, label: 'Zulu field', options: [] },
        { id: ID2, type: 'text', label_ciphertext: RAW, label: 'Alpha field', options: [] },
      ],
      usage: {},
      fieldFeatures: {},
      copyStatus: null,
      copyBusy: false,
    });
    expect(order(html, 'Zulu field', 'Alpha field')).toEqual(['Zulu field', 'Alpha field']);
  });
});

describe('the custom attributes list', () => {
  const ID2 = '44444444-4444-4444-8444-444444444444';
  const base = {
    definitions: [
      { id: ID, type: 'identifier_list', label_ciphertext: RAW, label: 'Highlight words', options: [] },
      { id: ID2, type: 'text', label_ciphertext: RAW, label: 'Nickname', options: [] },
    ],
    usage: {},
    copyStatus: null,
    copyBusy: false,
  };

  /** The markup of just the left-hand list. */
  function listOf(html: string): string {
    return html.slice(html.indexOf('wtf-master-list'), html.indexOf('col-md-8'));
  }

  it('shows only each field\'s name, not its type', async () => {
    const list = listOf(await render(AttributesPanel, { ...base, fieldFeatures: {} }));
    expect(list).toContain('Highlight words');
    expect(list).toContain('Nickname');
    expect(list).not.toMatch(/Identifier list|\(Text\)|Multi-line|Yes\/No/);
  });

  it('puts a blue lock next to a field that a site feature uses, and only that one', async () => {
    const list = listOf(await render(AttributesPanel, { ...base, fieldFeatures: { [ID]: ['Highlight tokens'] } }));
    expect(list.match(/field-protected/g)).toHaveLength(1);
    expect(list).toMatch(/text-info[^>]*title="Used by Highlight tokens — can&#39;t be deleted"/);
    // The lock follows the protected field's name, not the other one's.
    expect(list.indexOf('Highlight words')).toBeLessThan(list.indexOf('field-protected'));
    expect(list.indexOf('field-protected')).toBeLessThan(list.indexOf('Nickname'));
  });

  it('has no lock when nothing is protected', async () => {
    expect(listOf(await render(AttributesPanel, { ...base, fieldFeatures: {} }))).not.toContain('field-protected');
  });

  it('keeps the lock visible on the selected (blue) row by not colouring it blue there', async () => {
    const list = listOf(await render(AttributesPanel, { ...base, selectedId: ID, fieldFeatures: { [ID]: ['Highlight tokens'] } }));
    expect(list).toContain('field-protected');
    expect(list).not.toMatch(/text-info/);
  });

  it('describes the lock for screen readers', async () => {
    const list = listOf(await render(AttributesPanel, { ...base, fieldFeatures: { [ID]: ['Highlight tokens', 'Bypass DND'] } }));
    expect(list).toContain("Used by Highlight tokens, Bypass DND; can&#39;t be deleted");
  });
});

