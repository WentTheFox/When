import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The page pulls in the theme composable (via CategoriesPanel) and Inertia's <Head>, which need a browser/Inertia app.
vi.mock('../../../composables/useTheme', () => ({ useResolvedTheme: () => ({ value: 'light' }) }));
vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { render: () => null },
  usePage: () => ({ props: {} }),
  router: {},
}));

import Connections from '../Connections.vue';

const CONNECTION = '11111111-1111-4111-8111-111111111111';
const SOURCE = '22222222-2222-4222-8222-222222222222';
const DEFINITION = '33333333-3333-4333-8333-333333333333';

const props = {
  connections: [{
    id: CONNECTION, source_ids: [], name_ciphertext: 'Y29ubmVjdGlvbi1jaXBoZXI=', notes_ciphertext: null,
    share_link: null, archived: false, attribute_values: [],
  }],
  sources: [{ id: SOURCE, category_id: null, name_ciphertext: 'c291cmNlLWNpcGhlcg==' }],
  categories: [],
  attributeDefinitions: [{ id: DEFINITION, label_ciphertext: 'ZmllbGQtY2lwaGVy', type: 'text', options_ciphertext: null }],
  edges: [],
  fieldRoles: { highlight: null, bypass: null },
};

function render(hash: string): Promise<string> {
  vi.stubGlobal('window', { location: { hash, origin: 'https://when.test', pathname: '/dashboard/connections', search: '' } });
  return renderToString(createSSRApp({ render: () => h(Connections as object, props) }));
}

describe('Connections page', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('is one card with four tabs, showing the connections tab by default', async () => {
    const html = await render('');
    for (const title of ['Connections', 'Sources', 'Categories', 'Custom attributes']) {
      expect(html).toContain(title);
    }
    expect(html.match(/role="tab"/g)).toHaveLength(4);
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connections-tab-connections"/);
  });

  it('shows the lists under the locked overlay without asking for the unlock itself', async () => {
    const html = await render('');
    expect(html).toContain('Vault locked');
    expect(html).toContain('Y29ubmVjdGlvbi1jaXBoZXI='); // the connection, still raw
  });

  it('opens the tab named in the hash, with that record open and undecrypted', async () => {
    const html = await render(`#tab=attributes&id=${DEFINITION}`);
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connections-tab-attributes"/);
    expect(html).toMatch(/<input[^>]*value="ZmllbGQtY2lwaGVy"[^>]*\bdisabled\b/);
  });

  it('opens a source from the hash on the sources tab', async () => {
    const html = await render(`#tab=sources&id=${SOURCE}`);
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connections-tab-sources"/);
    expect(html).toMatch(/<input[^>]*value="c291cmNlLWNpcGhlcg=="[^>]*\bdisabled\b/);
  });

  it('understands the older #connection-<id> link', async () => {
    const html = await render(`#connection-${CONNECTION}`);
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connections-tab-connections"/);
    // The open connection's card is rendered (shown as raw ciphertext in its header).
    expect(html).toContain(`id="connection-${CONNECTION}"`);
  });

  it('ignores a record id that does not exist', async () => {
    const html = await render('#tab=sources&id=99999999-9999-4999-8999-999999999999');
    expect(html).toMatch(/nav-link active[^>]*aria-controls="connections-tab-sources"/);
    expect(html).toContain('Select a source on the left');
  });
});
