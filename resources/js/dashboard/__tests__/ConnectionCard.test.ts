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

  it('renders the four tabs, with the connection shown as raw ciphertext until decrypted', async () => {
    const html = await render();
    for (const title of ['Overview', 'Edit', 'Relationships', 'Sharing']) {
      expect(html).toContain(title);
    }
    expect(html).toContain(RAW_NAME);
  });

  it('marks Sharing as on when the connection has a share link', async () => {
    expect(await render()).toMatch(/Sharing.*On/s);
    expect(await render({ connection: { ...connection, share_link: null } })).not.toMatch(/Sharing<span[^>]*>On/);
  });

  it('counts relationships in the tab title and lists the other connection', async () => {
    const html = await render({
      edges: [{ id: 'e1', from_connection_id: connection.id, to_connection_id: 'other' }],
      connectionOptions: [{ id: 'other', label: 'Alice' }],
    });
    expect(html).toContain('Relationships');
    expect(html).toContain('(1)');
    expect(html).toContain('Alice');
  });

  it('opens on the tab it is given (so the URL hash can restore it)', async () => {
    const html = await render({ tab: 'connection-tab-edit' });
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connection-tab-edit"/);
    expect(html).toMatch(/id="connection-tab-edit" class="tab-pane active/);
  });

  it('keeps Save disabled while the encrypted fields are still pending', async () => {
    const html = await render();
    // Vue's server output puts <!--[--> markers between the tag and its text.
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*>(?:<!--.*?-->)*\s*Save/);
    // The name field shows the raw ciphertext, disabled, instead of an editable value.
    expect(html).toMatch(/<input[^>]*encrypted-pending[^>]*\bdisabled\b/);
  });
});
