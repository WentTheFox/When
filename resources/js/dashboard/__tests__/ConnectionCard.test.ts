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
});
