import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import VrchatNotesPanel, { type NoteImportItem } from '../VrchatNotesPanel.vue';

const item = (id: string, name: string, notes: string[]): NoteImportItem => ({
  connectionId: id, name, notes: notes.map((note, i) => ({ vrchatId: `usr_${id}${i}`, note })),
});

const render = (items: NoteImportItem[], extra: object = {}) =>
  renderToString(createSSRApp({ render: () => h(VrchatNotesPanel as object, { items, ready: true, busy: false, progress: '', ...extra }) }));

describe('VrchatNotesPanel', () => {
  it('counts notes (not connections) and offers one button to import them all', async () => {
    const html = await render([item('a', 'Alice', ['Likes tea']), item('b', 'Bob', ['One', 'Two'])]);
    expect(html).toContain('VRChat notes to carry over');
    expect(html).toContain('Import all 3 notes');
    expect(html).toContain('Alice');
    expect(html).toContain('“Likes tea”');
  });

  it('says "the note" for a single one', async () => {
    expect(await render([item('a', 'Alice', ['Likes tea'])])).toContain('Import the note');
  });

  it('waits for the vault: no button, and no notes shown', async () => {
    const html = await render([item('a', 'Alice', ['Likes tea'])], { ready: false });
    expect(html).toContain('Unlock your vault to import');
    expect(html).not.toContain('Import the note');
    expect(html).not.toContain('Likes tea');
  });

  it('shows progress while importing, and a long note as a short snippet', async () => {
    const html = await render([item('a', 'Alice', ['x'.repeat(200)])], { busy: true, progress: '2 of 9' });
    expect(html).toContain('Importing… 2 of 9');
    expect(html).toContain('x'.repeat(90) + '…');
    expect(html).not.toContain('x'.repeat(91));
  });

  it('previews the first few connections and offers the rest', async () => {
    const many = Array.from({ length: 8 }, (_, i) => item(String(i), `Person ${i}`, ['note']));
    const html = await render(many);
    expect(html).toContain('Person 4');
    expect(html).not.toContain('Person 5');
    expect(html).toContain('Show all 8');
  });
});
