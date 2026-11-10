// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

vi.mock('../../../composables/useTheme', () => ({ useResolvedTheme: () => ({ value: 'light' }) }));
vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { render: () => null },
  usePage: () => ({ props: {} }),
  router: {},
}));
// No real keys: "decrypting" just turns a ciphertext into a readable marker.
vi.mock('../../../dashboard/vault', () => ({
  isVaultUnlocked: () => false,
  getRecordKey: async () => ({}),
  createRecordKey: async () => ({}),
  deleteRecordKey: async () => undefined,
  lockVault: () => undefined,
  restoreVault: async () => null,
  unlockVault: async () => ({ expiresAt: null }),
}));
// While `hold` is set, every decryption waits for release(), so a test can lock the vault mid-decrypt.
const gate = vi.hoisted(() => {
  const state = { hold: false, waiting: [] as Array<() => void> };
  return {
    state,
    wait: () => (state.hold ? new Promise<void>((resolve) => { state.waiting.push(resolve); }) : Promise.resolve()),
    release: () => { state.hold = false; state.waiting.splice(0).forEach((resolve) => resolve()); },
  };
});
vi.mock('../../../crypto', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  decryptString: async (_key: unknown, ciphertext: string) => {
    await gate.wait();
    return `plain:${ciphertext}`;
  },
}));

import { useVault } from '../../../dashboard/useVault';
import Connections from '../Connections.vue';

const props = {
  connections: [{
    id: '11111111-1111-4111-8111-111111111111', source_ids: [], name_ciphertext: 'connection-cipher', notes_ciphertext: null,
    share_link: null, archived: false, attribute_values: [],
  }],
  sources: [{ id: '22222222-2222-4222-8222-222222222222', category_id: null, name_ciphertext: 'source-cipher' }],
  categories: [{ id: '44444444-4444-4444-8444-444444444444', color_key: null, name_ciphertext: 'category-cipher' }],
  attributeDefinitions: [{ id: '33333333-3333-4333-8333-333333333333', label_ciphertext: 'field-cipher', type: 'text', options_ciphertext: null, icon_rules_ciphertext: null }],
  legacyEdges: [],
  fieldFeatures: {},
  fieldFeatureTypes: {},
  fieldMigrations: [],
  vrchat: { configured: false, synced_at: null, auto_create_friends: false, pending_friends: 0, friends_source_id: null, highlight_field_id: null, discord_field_id: null, field: null, profiles: {} },
};

describe('Connections page vault locking', () => {
  const { vaultUnlocked } = useVault();
  afterEach(() => {
    gate.release();
    vaultUnlocked.value = false;
  });

  it('shows decrypted values after unlock and removes them again on lock', async () => {
    const wrapper = mount(Connections as object, { props, attachTo: document.body });

    vaultUnlocked.value = true;
    await flushPromises();
    expect(wrapper.html()).toContain('plain:connection-cipher');

    vaultUnlocked.value = false;
    await nextTick();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('plain:');
    expect(html).toContain('connection-cipher'); // back to the raw blob

    wrapper.unmount();
  });

  it('does not write a decryption back when the vault was locked while it was in flight', async () => {
    const wrapper = mount(Connections as object, { props, attachTo: document.body });

    gate.state.hold = true;
    vaultUnlocked.value = true;
    await flushPromises(); // the first decryption is now waiting on the gate

    vaultUnlocked.value = false;
    await nextTick();
    gate.release();
    await flushPromises();

    expect(wrapper.html()).not.toContain('plain:');

    wrapper.unmount();
  });
});
