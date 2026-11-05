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

  it('is a single card, not a tabbed one', async () => {
    const html = await render();
    expect(html).not.toContain('role="tablist"');
    expect(html).not.toContain('nav-tabs');
  });

  it('points at the edit form for the share link, and keeps the form closed until Edit is pressed', async () => {
    const html = await render();
    expect(html).toContain('Shared — manage the link under Edit.');
    expect(html).not.toMatch(/>\s*Save\s*</);
    expect(await render({ connection: { ...connection, share_link: null } })).not.toContain('Shared — manage');
  });

  it('lists the people it knows in the summary', async () => {
    const html = await render({
      edges: [{ id: 'e1', from_connection_id: connection.id, to_connection_id: 'other' }],
      connectionOptions: [{ id: 'other', label: 'Alice' }],
    });
    expect(html).toContain('Knows: Alice');
  });
});
