import { describe, expect, it, vi } from 'vitest';
import { parseIconRules } from '../iconRules';
import { ensureNotesField, joinNotes, moveLegacyNotes, NOTES_ICON, notesTextOf, saveNotes } from '../notesField';
import type { NotesConnection, NotesFieldDeps, NotesFieldRequest, NotesFieldResponse } from '../notesField';

const KEY = {} as CryptoKey;
const FIELD = 'field-1';

/** Fake crypto: "ciphertext" is `enc:` + plaintext. */
const enc = async (_key: CryptoKey, text: string) => `enc:${text}`;
const dec = async (_key: CryptoKey, cipher: string) => cipher.replace(/^enc:/, '');

function connection(id: string, notes: string | null, fieldValue?: string): NotesConnection {
  return {
    id,
    notes_ciphertext: notes === null ? null : `enc:${notes}`,
    attribute_values: fieldValue === undefined ? [] : [{ attribute_definition_id: FIELD, value_ciphertext: `enc:${fieldValue}` }],
  };
}

/** A page: holds connections and the field id, and a fake server that behaves like ConnectionController::notesField. */
function setup(initial: NotesConnection[], startFieldId: string | null = null) {
  let fieldId = startFieldId;
  const connections = initial.map((c) => ({ ...c, attribute_values: [...c.attribute_values] }));
  const requests: NotesFieldRequest[] = [];

  const post = vi.fn(async (body: NotesFieldRequest): Promise<NotesFieldResponse> => {
    requests.push(body);
    const created = fieldId === null;
    fieldId = fieldId ?? body.id ?? null;
    const changed: NotesConnection[] = [];
    const conflicts: string[] = [];

    for (const value of body.values ?? []) {
      const c = connections.find((x) => x.id === value.connection_id)!;
      c.attribute_values = [{ attribute_definition_id: fieldId!, value_ciphertext: value.value_ciphertext }];
      c.notes_ciphertext = null;
      changed.push(c);
    }
    for (const c of connections) {
      if (!c.notes_ciphertext || (body.values ?? []).some((v) => v.connection_id === c.id)) continue;
      if (c.attribute_values.some((v) => v.attribute_definition_id === fieldId && v.value_ciphertext)) {
        conflicts.push(c.id);
        continue;
      }
      c.attribute_values = [{ attribute_definition_id: fieldId!, value_ciphertext: c.notes_ciphertext }];
      c.notes_ciphertext = null;
      changed.push(c);
    }

    return {
      definition: { id: fieldId!, label_ciphertext: body.label_ciphertext ?? 'old-label', type: 'textarea', options_ciphertext: null, icon_rules_ciphertext: body.icon_rules_ciphertext ?? null },
      created,
      connections: changed.map((c) => structuredClone(c)),
      conflicts,
    };
  });

  const applied: NotesFieldResponse[] = [];
  const deps: NotesFieldDeps = {
    fieldId: () => fieldId,
    connections: () => connections,
    getRecordKey: vi.fn(async () => KEY),
    createRecordKey: vi.fn(async () => KEY),
    encrypt: enc,
    decrypt: dec,
    post,
    apply: (response) => {
      applied.push(response);
      fieldId = response.definition.id;
      for (const updated of response.connections) {
        const index = connections.findIndex((c) => c.id === updated.id);
        if (index !== -1) connections[index] = updated;
      }
    },
  };

  return { deps, connections, requests, applied, post };
}

describe('joinNotes', () => {
  it('keeps two notes apart with a blank line, and tolerates either being empty', () => {
    expect(joinNotes('one', 'two')).toBe('one\n\ntwo');
    expect(joinNotes('  one  ', '')).toBe('one');
    expect(joinNotes('', 'two')).toBe('two');
    expect(joinNotes('', '')).toBe('');
  });
});

describe('moveLegacyNotes', () => {
  it('does nothing, and does not even call the server, when no connection has an old note', async () => {
    const { deps, post } = setup([connection('c1', null), connection('c2', null)]);

    await moveLegacyNotes(deps);

    expect(post).not.toHaveBeenCalled();
  });

  it('creates the field with an encrypted label and a default "has notes" rule, then moves the notes', async () => {
    const { deps, connections, requests } = setup([connection('c1', 'first'), connection('c2', 'second'), connection('c3', null)]);

    await moveLegacyNotes(deps);

    expect(requests).toHaveLength(1);
    const [request] = requests;
    expect(request.id).toBeTruthy();
    expect(request.label_ciphertext).toBe('enc:Notes');

    const rules = parseIconRules((request.icon_rules_ciphertext ?? '').replace(/^enc:/, ''));
    expect(rules.kind).toBe('ok');
    if (rules.kind === 'ok') {
      expect(rules.set.rules).toHaveLength(1);
      expect(rules.set.rules[0]).toMatchObject({ icon: NOTES_ICON, condition: { op: 'nonEmpty' } });
    }

    expect(connections.map((c) => c.notes_ciphertext)).toEqual([null, null, null]);
    expect(connections[0].attribute_values[0]).toEqual({ attribute_definition_id: request.id, value_ciphertext: 'enc:first' });
  });

  it('does not make a second field when the page already knows one', async () => {
    const { deps, requests } = setup([connection('c1', 'late note')], FIELD);

    await moveLegacyNotes(deps);

    expect(requests).toEqual([{}]);
    expect(deps.createRecordKey).not.toHaveBeenCalled();
  });

  it('merges an old note into a value already in the field, keeping both', async () => {
    const { deps, connections, requests } = setup([connection('c1', 'old built-in note', 'typed in the field')], FIELD);

    await moveLegacyNotes(deps);

    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual({ values: [{ connection_id: 'c1', value_ciphertext: 'enc:typed in the field\n\nold built-in note' }] });
    expect(connections[0].notes_ciphertext).toBeNull();
    expect(connections[0].attribute_values[0].value_ciphertext).toBe('enc:typed in the field\n\nold built-in note');
  });

  it('sends merged values in batches the server accepts', async () => {
    const many = Array.from({ length: 501 }, (_, i) => connection(`c${i}`, `legacy ${i}`, `existing ${i}`));
    const { deps, requests } = setup(many, FIELD);

    await moveLegacyNotes(deps);

    // One call that finds the conflicts, then 500 + 1 merged values.
    expect(requests.map((r) => r.values?.length ?? 0)).toEqual([0, 500, 1]);
  });
});

describe('ensureNotesField / saveNotes / notesTextOf', () => {
  it('creates the field for a first-time user, and not again afterwards', async () => {
    const { deps, requests } = setup([connection('c1', null)]);

    await ensureNotesField(deps);
    await ensureNotesField(deps);

    expect(requests).toHaveLength(1);
    expect(requests[0].label_ciphertext).toBe('enc:Notes');
  });

  it('saves notes text for connections, creating the field first when needed', async () => {
    const { deps, connections, requests } = setup([connection('c1', null), connection('c2', null)]);

    await saveNotes(deps, [{ connectionId: 'c1', text: 'from VRChat' }]);

    expect(requests).toHaveLength(2);
    expect(requests[0].id).toBeTruthy();
    expect(requests[1]).toEqual({ values: [{ connection_id: 'c1', value_ciphertext: 'enc:from VRChat' }] });
    expect(await notesTextOf(deps, connections[0])).toBe('from VRChat');
    expect(await notesTextOf(deps, connections[1])).toBe('');
  });

  it('reads nothing from a connection when there is no notes field yet', async () => {
    const { deps } = setup([connection('c1', null)]);

    expect(await notesTextOf(deps, deps.connections()[0])).toBe('');
  });
});
