import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import ConnectionCard, { type ConnectionRow } from '../ConnectionCard.vue';

const RAW_NAME = 'bmFtZS1jaXBoZXJ0ZXh0LWJsb2I=';

const connection: ConnectionRow = {
  id: '11111111-1111-4111-8111-111111111111',
  source_ids: [],
  name_ciphertext: RAW_NAME,
  notes_ciphertext: null,
  share_link: { id: 'l1', highlight_token: 'tok', archived: false },
  archived: false,
  attribute_values: [],
};

function render(overrides: Record<string, unknown> = {}): Promise<string> {
  const props = {
    connection,
    sources: [],
    attributeDefinitions: [],
    edges: [],
    connectionOptions: [],
    ...overrides,
  };
  return renderToString(createSSRApp({ render: () => h(ConnectionCard, props) }));
}

describe('ConnectionCard', () => {
  // ConnectionSharing builds the link URL from window.location, which doesn't exist when server-rendering under node.
  beforeAll(() => {
    vi.stubGlobal('window', { location: { origin: 'https://when.test' } });
  });

  it('shows the connection as raw ciphertext until it is decrypted', async () => {
    const html = await render();
    expect(html).toContain(RAW_NAME);
    expect(html).toContain('Decrypting…');
  });

  it('opens straight into the form: no separate viewing state to click out of', async () => {
    const html = await render();
    // The form is there, with its name field disabled while undecrypted, and Save present.
    expect(html).toMatch(/<input[^>]*encrypted-pending[^>]*\bdisabled\b/);
    expect(html).toMatch(/>\s*(<!--.*?-->)*\s*Save\s*(<!--.*?-->)*\s*</s);
    // …and no pencil/"Edit" toggle, and no read-only summary repeating the form.
    expect(html).not.toContain('Edit this connection');
    expect(html).not.toContain('manage the link under Edit');
    expect(html).not.toContain('Knows:');
  });

  it('is a single card, not a tabbed one', async () => {
    const html = await render();
    expect(html).not.toContain('role="tablist"');
    expect(html).not.toContain('nav-tabs');
  });

  it('keeps Save disabled until there is something to save', async () => {
    const html = await render();
    const save = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
      .find(([, , inner]) => inner.replace(/<!--[\s\S]*?-->|<[^>]*>/g, '').trim() === 'Save');
    expect(save).toBeDefined();
    expect(save![1]).toMatch(/\bdisabled\b/);
    expect(html).not.toContain('Discard changes');
  });

  it('lists the people it knows with a clearly labelled Remove for each', async () => {
    const html = await render({
      edges: [{ id: 'e1', from_connection_id: connection.id, to_connection_id: 'other' }],
      connectionOptions: [{ id: 'other', label: 'Alice' }],
    });
    expect(html).toContain('Alice');
    expect(html).toContain('aria-label="Remove Alice"');
  });

  it('offers "Enable sharing" as a solid primary button (an outline one is unreadable on the dark theme)', async () => {
    const html = await render({ connection: { ...connection, share_link: null } });
    const button = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
      .find(([, , inner]) => inner.replace(/<!--[\s\S]*?-->|<[^>]*>/g, '').trim() === 'Enable sharing');
    expect(button).toBeDefined();
    expect(button![1]).toContain('btn-primary');
    expect(button![1]).not.toContain('outline');
  });

  describe('fields with no value yet', () => {
    const definitions = [
      { id: 'd-choice', label: 'Tier', type: 'radio', options: ['Gold', 'Silver'] },
      { id: 'd-flag', label: 'VIP', type: 'boolean', options: [] },
      { id: 'd-ref', label: 'Referrer', type: 'connection', options: [] },
    ];

    /** The markup of one field's form group, found by its label. */
    function groupOf(html: string, label: string): string {
      const start = html.indexOf(`>${label}<`);
      expect(start, `field "${label}" is rendered`).toBeGreaterThan(-1);
      return html.slice(start, html.indexOf('</fieldset>', start));
    }

    it('selects "(unset)" on a choice field instead of leaving every option unchecked', async () => {
      const group = groupOf(await render({ attributeDefinitions: definitions }), 'Tier');
      const radios = [...group.matchAll(/<input\b[^>]*type="radio"[^>]*>/g)].map(([tag]) => tag);

      expect(radios).toHaveLength(3); // (unset), Gold, Silver
      expect(radios.filter((tag) => /\bchecked\b/.test(tag))).toHaveLength(1);
      expect(radios[0]).toMatch(/\bchecked\b/);
      // The "(unset)" radio's value really is empty (a casting quirk in the radio component once made it `true`).
      expect(radios.map((tag) => /value="([^"]*)"/.exec(tag)?.[1])).toEqual(['', 'Gold', 'Silver']);
    });

    it('shows a Yes/No field as three radios — (unset), Yes, No — with "(unset)" selected', async () => {
      const group = groupOf(await render({ attributeDefinitions: definitions }), 'VIP');
      const radios = [...group.matchAll(/<input\b[^>]*type="radio"[^>]*>/g)].map(([tag]) => tag);

      expect(radios).toHaveLength(3);
      expect(group).not.toContain('<select');
      expect(radios.filter((tag) => /\bchecked\b/.test(tag))).toEqual([radios[0]]);
      expect(radios.map((tag) => /value="([^"]*)"/.exec(tag)?.[1])).toEqual(['', 'true', 'false']);
      expect(group).toMatch(/Yes[\s\S]*No/);
    });

    it('groups each field\'s radios under one name, so the arrow keys move between them', async () => {
      const html = await render({ attributeDefinitions: definitions });
      for (const label of ['Tier', 'VIP']) {
        const names = new Set([...groupOf(html, label).matchAll(/<input\b[^>]*type="radio"[^>]*\bname="([^"]+)"/g)].map((m) => m[1]));
        expect(names.size, label).toBe(1);
      }
    });

    // A dropdown's selected option is set in the browser, so a server render can't show it; the unset-as-empty
    // binding it relies on is covered in fieldBinding.test.ts. This only checks the control and its "(none)" option exist.
    it('offers "(none)" on a connection field, which is a dropdown', async () => {
      const group = groupOf(await render({
        attributeDefinitions: definitions,
        connectionOptions: [{ id: 'other', label: 'Alice' }],
      }), 'Referrer');
      expect(group).toContain('<select');
      expect(group).toContain('(none)');
      expect(group).toContain('Alice');
    });
  });
});

