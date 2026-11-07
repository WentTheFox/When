import { describe, expect, it } from 'vitest';
import { listEntries, paddedListText } from '../encryptedList';
import { conditionMatches } from '../iconRules';
import {
  moveLegacyRelationships,
  relatedIdsByConnection,
  type LegacyEdge,
  type RelationshipsConnection,
  type RelationshipsDeps,
  type RelationshipsRequest,
  type RelationshipsResponse,
} from '../relationshipsField';

const FIELD = 'field-1';

/** A tiny stand-in for the server and the page: "encryption" is a prefix, the server only stores and guards. */
function setup(edges: LegacyEdge[], ids: string[], stored: Record<string, string> = {}) {
  const server = new Map<string, string>(Object.entries(stored));
  const calls: RelationshipsRequest[] = [];
  let fieldId: string | null = null;
  let edgeList = [...edges];
  let finished = false;
  let conflictOnce: string | null = null;

  const connections: RelationshipsConnection[] = ids.map((id) => ({
    id,
    attribute_values: server.has(id) ? [{ attribute_definition_id: FIELD, value_ciphertext: server.get(id)! }] : [],
  }));

  const deps: RelationshipsDeps<RelationshipsConnection> = {
    fieldId: () => fieldId,
    edges: () => edgeList,
    connections: () => connections,
    getRecordKey: async () => ({}) as CryptoKey,
    createRecordKey: async () => ({}) as CryptoKey,
    encrypt: async (_key, text) => `enc:${text}`,
    decrypt: async (_key, ciphertext) => ciphertext.replace(/^enc:/, ''),
    post: async (body): Promise<RelationshipsResponse<RelationshipsConnection>> => {
      calls.push(body);
      const conflicts: Record<string, string | null> = {};
      const written: string[] = [];

      for (const item of body.values ?? []) {
        if (conflictOnce === item.connection_id) {
          // Someone else saved this connection meanwhile.
          server.set(item.connection_id, `enc:${paddedListText('someone-else')}`);
          conflictOnce = null;
        }
        if ((server.get(item.connection_id) ?? null) !== item.base_ciphertext) {
          conflicts[item.connection_id] = server.get(item.connection_id) ?? null;
          continue;
        }
        server.set(item.connection_id, item.value_ciphertext);
        written.push(item.connection_id);
      }
      if (body.finish && Object.keys(conflicts).length === 0) edgeList = [];

      return {
        definition: { id: FIELD, label_ciphertext: 'l', type: 'relationship', options_ciphertext: null, icon_rules_ciphertext: null },
        created: body.id !== undefined,
        connections: written.map((id) => ({ id, attribute_values: [{ attribute_definition_id: FIELD, value_ciphertext: server.get(id)! }] })),
        conflicts,
      };
    },
    apply: (response) => {
      fieldId = response.definition.id;
      for (const updated of response.connections) {
        const index = connections.findIndex((c) => c.id === updated.id);
        connections[index] = updated;
      }
    },
    finished: () => { finished = true; },
  };

  return { deps, server, calls, isFinished: () => finished, conflictNextWriteTo: (id: string) => { conflictOnce = id; } };
}

const entriesOf = (server: Map<string, string>, id: string) => listEntries(server.get(id)?.replace(/^enc:/, ''));

describe('relatedIdsByConnection', () => {
  it('mirrors each edge to both sides and drops unknown connections and self edges', () => {
    const related = relatedIdsByConnection(
      [
        { from_connection_id: 'a', to_connection_id: 'b' },
        { from_connection_id: 'a', to_connection_id: 'gone' },
        { from_connection_id: 'c', to_connection_id: 'c' },
      ],
      ['a', 'b', 'c'],
    );

    expect([...related.get('a')!]).toEqual(['b']);
    expect([...related.get('b')!]).toEqual(['a']);
    expect(related.has('c')).toBe(false);
  });
});

describe('moveLegacyRelationships', () => {
  it('does nothing without old edges', async () => {
    const { deps, calls } = setup([], ['a', 'b']);
    await moveLegacyRelationships(deps);
    expect(calls).toEqual([]);
  });

  it('creates the field, writes both sides of every edge, then retires the edges', async () => {
    const { deps, server, calls, isFinished } = setup([{ from_connection_id: 'a', to_connection_id: 'b' }], ['a', 'b']);

    await moveLegacyRelationships(deps);

    expect(calls[0].id).toBeDefined();
    expect(calls[0].label_ciphertext).toBe('enc:Relationships');
    expect(entriesOf(server, 'a')).toEqual(['b']);
    expect(entriesOf(server, 'b')).toEqual(['a']);
    expect(calls.at(-1)).toEqual({ finish: true });
    expect(isFinished()).toBe(true);
  });

  it('keeps what a connection already lists and does not rewrite a list that is complete', async () => {
    const { deps, server, calls } = setup(
      [{ from_connection_id: 'a', to_connection_id: 'b' }],
      ['a', 'b'],
      { a: `enc:${paddedListText('b\nz')}`, b: `enc:${paddedListText('a')}` },
    );

    await moveLegacyRelationships(deps);

    expect(entriesOf(server, 'a')).toEqual(['b', 'z']);
    expect(calls.filter((c) => c.values?.length)).toHaveLength(0);
  });

  it('merges again when a connection was changed meanwhile, and only then finishes', async () => {
    const { deps, server, isFinished, conflictNextWriteTo } = setup([{ from_connection_id: 'a', to_connection_id: 'b' }], ['a', 'b']);
    conflictNextWriteTo('a');

    await moveLegacyRelationships(deps);

    expect(entriesOf(server, 'a').sort()).toEqual(['b', 'someone-else']);
    expect(isFinished()).toBe(true);
  });
});

describe('relationship icon rules', () => {
  it('matches a connection with no related connections via the count condition', () => {
    const none = { op: 'count', min: 0, max: 0 } as const;

    expect(conditionMatches(none, 'relationship', '')).toBe(true);
    expect(conditionMatches(none, 'relationship', '\n\n')).toBe(true);
    expect(conditionMatches(none, 'relationship', 'a\nb')).toBe(false);
    expect(conditionMatches({ op: 'count', min: 2 }, 'relationship', 'a\nb')).toBe(true);
  });

  it('offers no pattern condition: ids mean nothing to a pattern', () => {
    expect(conditionMatches({ op: 'regex', pattern: '.' }, 'relationship', 'a')).toBe(false);
  });
});
