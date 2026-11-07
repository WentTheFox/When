import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import ConnectionCard, { type ConnectionRow } from '../ConnectionCard.vue';
import { useVault } from '../useVault';

const RAW_NAME = 'bmFtZS1jaXBoZXJ0ZXh0LWJsb2I=';

const connection: ConnectionRow = {
  id: '11111111-1111-4111-8111-111111111111',
  source_ids: [],
  name_ciphertext: RAW_NAME,
  notes_ciphertext: null,
  share_link: { id: 'l1', highlight_token: 'tok', last_visited_at: null },
  archived: false,
  attribute_values: [],
};

/** The visible text of every button (comments and tags stripped; a linear scan, no backtracking). */
function buttonLabels(html: string): string[] {
  return [...html.matchAll(/<button\b[^>]*>([\s\S]*?)<\/button>/g)].map(([, inner]) => inner.replace(/<!--[\s\S]*?-->|<[^>]*>/g, '').trim());
}

function render(overrides: Record<string, unknown> = {}): Promise<string> {
  const props = {
    connection,
    sources: [],
    attributeDefinitions: [],
    connectionOptions: [],
    ...overrides,
  };
  return renderToString(createSSRApp({ render: () => h(ConnectionCard, props) }));
}

/** Props that make the given custom fields show: a field only appears once it has a stored value (or is added by hand). */
function withStoredValues<T extends { id: string }>(definitions: T[]): Record<string, unknown> {
  return {
    attributeDefinitions: definitions,
    connection: {
      ...connection,
      attribute_values: definitions.map((d) => ({ attribute_definition_id: d.id, value_ciphertext: 'x' })),
    },
  };
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
    expect(buttonLabels(html)).toContain('Save');
    // …and no pencil/"Edit" toggle, and no read-only summary repeating the form.
    expect(html).not.toContain('Edit this connection');
    expect(html).not.toContain('manage the link under Edit');
    expect(html).not.toContain('Knows:');
  });

  it('archives with a button next to Delete in the header, not a checkbox in the form', async () => {
    const html = await render();
    const header = html.slice(0, html.indexOf('Name'));

    expect(header).toContain('Archive');
    expect(header).toContain('aria-label="Delete this connection"');
    expect(header.indexOf('Archive')).toBeLessThan(header.indexOf('Delete this connection'));
    // The buttons are spaced apart, and there is no longer an "Archived" checkbox in the form.
    expect(header).toMatch(/class="d-flex gap-2"/);
    expect(html).not.toContain('type="checkbox"');
  });

  it('offers Unarchive on an archived connection, and labels it with a badge', async () => {
    const html = await render({ connection: { ...connection, archived: true } });
    expect(buttonLabels(html)).toContain('Unarchive');
    expect(html).toContain('Archived');
  });

  it('never sends `archived` when saving the form (so Save cannot undo an archive)', async () => {
    const source = (await import('node:fs')).readFileSync(new URL('../ConnectionCard.vue', import.meta.url), 'utf8');
    const save = source.slice(source.indexOf('async function save()'), source.indexOf('const archiving = ref(false)'));
    expect(save.length).toBeGreaterThan(200); // the slice really is save()
    expect(save).not.toContain('archived');
  });

  // Switching from one connection to another creates a new card while the vault is already unlocked, so its
  // decryption runs during setup. It once read a const (isDirty) declared further down and threw
  // "can't access lexical declaration before initialization" as an unhandled promise rejection.
  it('can be created while the vault is already unlocked, without an unhandled error', async () => {
    const { vaultUnlocked } = useVault();
    const unhandled: unknown[] = [];
    const record = (reason: unknown) => { unhandled.push(reason); };

    process.on('unhandledRejection', record);
    vaultUnlocked.value = true;

    try {
      await render();
      await new Promise((resolve) => setTimeout(resolve, 25)); // let the async decrypt settle
    } finally {
      vaultUnlocked.value = false;
      process.off('unhandledRejection', record);
    }

    expect(unhandled).toEqual([]);
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

  it('has no separate Relationships section any more: nothing saves outside the Save button', async () => {
    const html = await render({ connectionOptions: [{ id: 'alice', label: 'Alice' }] });
    expect(html).not.toContain('Changes here save immediately');
    expect(html).not.toContain('Knows…');
    expect(html).not.toMatch(/<table\b/);
  });

  it('shows a relationship field among the custom fields, above Save, as a multi-select of the other connections', async () => {
    const html = await render(withStoredValues([{ id: 'd-friends', label: 'Friends', type: 'relationship', options: [] }]));

    expect(html).toContain('>Friends<');
    expect(html).toContain('aria-label="Connections for Friends"');
    expect(html).toContain('Related connections list each other; changes save with Save.');
    expect(html.indexOf('>Friends<')).toBeLessThan(html.indexOf('>Save<'));
  });

  it('offers a relationship field in the "add a custom field" picker like any other field', async () => {
    const html = await render({ attributeDefinitions: [{ id: 'd-family', label: 'Family', type: 'relationship', options: [] }] });
    expect(html).toContain('Add a custom field…');
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
      const group = groupOf(await render(withStoredValues(definitions)), 'Tier');
      const radios = [...group.matchAll(/<input\b[^>]*type="radio"[^>]*>/g)].map(([tag]) => tag);

      expect(radios).toHaveLength(3); // (unset), Gold, Silver
      expect(radios.filter((tag) => /\bchecked\b/.test(tag))).toHaveLength(1);
      expect(radios[0]).toMatch(/\bchecked\b/);
      // The "(unset)" radio's value really is empty (a casting quirk in the radio component once made it `true`).
      expect(radios.map((tag) => /value="([^"]*)"/.exec(tag)?.[1])).toEqual(['', 'Gold', 'Silver']);
    });

    it('shows a Yes/No field as three radios — (unset), Yes, No — with "(unset)" selected', async () => {
      const group = groupOf(await render(withStoredValues(definitions)), 'VIP');
      const radios = [...group.matchAll(/<input\b[^>]*type="radio"[^>]*>/g)].map(([tag]) => tag);

      expect(radios).toHaveLength(3);
      expect(group).not.toContain('<select');
      expect(radios.filter((tag) => /\bchecked\b/.test(tag))).toEqual([radios[0]]);
      expect(radios.map((tag) => /value="([^"]*)"/.exec(tag)?.[1])).toEqual(['', 'true', 'false']);
      expect(group).toMatch(/Yes[\s\S]*No/);
    });

    it('groups each field\'s radios under one name, so the arrow keys move between them', async () => {
      const html = await render(withStoredValues(definitions));
      for (const label of ['Tier', 'VIP']) {
        const names = new Set([...groupOf(html, label).matchAll(/<input\b[^>]*type="radio"[^>]*\bname="([^"]+)"/g)].map((m) => m[1]));
        expect(names.size, label).toBe(1);
      }
    });

    // A connection field is a searchable select: a closed button showing the current choice, with the options
    // only rendered once opened. The unset-as-empty binding is covered in fieldBinding.test.ts; this only checks
    // the control exists and shows "(none)" while unset.
    it('shows "(none)" on an unset connection field, which is a searchable select', async () => {
      const group = groupOf(await render({
        ...withStoredValues(definitions),
        connectionOptions: [{ id: 'other', label: 'Alice' }],
      }), 'Referrer');
      expect(group).toContain('searchable-select');
      expect(group).not.toContain('<select');
      expect(group).toContain('(none)');
    });
  });

  it('hides custom fields without a value behind an "add a field" picker', async () => {
    const definitions = [
      { id: 'a', type: 'text', label: 'Nickname', label_ciphertext: 'x', options: [] },
      { id: 'b', type: 'email', label: 'Mail', label_ciphertext: 'x', options: [] },
    ];
    const html = await render({
      attributeDefinitions: definitions,
      connection: { ...connection, attribute_values: [{ attribute_definition_id: 'a', value_ciphertext: 'x' }] },
    });

    expect(html).toContain('>Nickname<');
    expect(html).not.toContain('>Mail<');
    expect(html).toContain('Add a custom field…');
    expect(buttonLabels(html)).toContain('Add field');
  });

  it('gives a url field an open-in-a-new-tab button, disabled while there is no valid link', async () => {
    const html = await render(withStoredValues([{ id: 'u1', type: 'url', label: 'Website', label_ciphertext: 'x', options: [] }]));

    expect(html).toContain('type="url"');
    expect(html).toContain('aria-label="Open in a new tab"');
    expect(html).toMatch(/<button[^>]*\bdisabled\b[^>]*aria-label="Open in a new tab"/);
  });

  it('edits a link list as one link per line, with no read-only summary', async () => {
    const html = await render(withStoredValues([{ id: 'l1', type: 'link_list', label: 'Contacts', label_ciphertext: 'x', options: [] }]));

    expect(html).toContain('<textarea');
    expect(html).toContain('One https:// link per line');
  });
});

