/**
 * Relationships are an ordinary "relationship" custom field now (several can exist: Friends, Family, ...),
 * not a built-in list of plaintext edges. This is the one-time move of the old edges into a field, run in
 * the browser on the first vault unlock afterwards, because only the browser can encrypt the lists.
 *
 * The server's endpoint (ConnectionController::relationshipsField) creates the field once per owner, binds it
 * to the network graph, stores each connection's list as the opaque ciphertext handed to it (guarded by the
 * ciphertext this module last saw, so a concurrent edit is reported as a conflict instead of overwritten), and
 * on `finish` deletes the old edges. Relationships are mirrored, so an old edge between A and B puts B in A's
 * list and A in B's. Safe to run again after an interruption: lists are merged with what is already there.
 */
import { listEntries, paddedListText } from './encryptedList';

export const RELATIONSHIPS_FIELD_LABEL = 'Relationships';

/** The server takes this many values per call. */
export const VALUES_PER_CALL = 500;
/** How often a connection edited meanwhile is merged and re-sent before giving up until the next unlock. */
const MAX_ROUNDS = 3;

export interface LegacyEdge {
  from_connection_id: string;
  to_connection_id: string;
}

export interface RelationshipsFieldDefinition {
  id: string;
  label_ciphertext: string;
  type: string;
  options_ciphertext: string | null;
  icon_rules_ciphertext: string | null;
}

export interface RelationshipsConnection {
  id: string;
  attribute_values: { attribute_definition_id: string; value_ciphertext?: string | null }[];
}

export interface RelationshipsRequest {
  id?: string;
  label_ciphertext?: string;
  values?: { connection_id: string; value_ciphertext: string; base_ciphertext: string | null }[];
  finish?: boolean;
}

export interface RelationshipsResponse<C extends RelationshipsConnection = RelationshipsConnection> {
  definition: RelationshipsFieldDefinition;
  created: boolean;
  connections: C[];
  /** Connection id to the ciphertext it holds now, for each value that was not written because it changed. */
  conflicts: Record<string, string | null>;
}

export interface RelationshipsDeps<C extends RelationshipsConnection = RelationshipsConnection> {
  /** The field the old edges are moved into, if the page already knows it (an interrupted earlier run). */
  fieldId: () => string | null;
  /** The old plaintext edges, empty once they have been moved. */
  edges: () => LegacyEdge[];
  connections: () => C[];
  getRecordKey: (id: string) => Promise<CryptoKey>;
  createRecordKey: (id: string) => Promise<CryptoKey>;
  encrypt: (key: CryptoKey, text: string) => Promise<string>;
  decrypt: (key: CryptoKey, ciphertext: string) => Promise<string>;
  post: (body: RelationshipsRequest) => Promise<RelationshipsResponse<C>>;
  /** Puts a response into the page's own state (the definition, the id, the updated connections). */
  apply: (response: RelationshipsResponse<C>) => void | Promise<void>;
  /** The edges are gone on the server: forget them. */
  finished: () => void;
}

/** Each connection's related ids from the old edges, both directions, dropping edges to connections that no longer exist. */
export function relatedIdsByConnection(edges: LegacyEdge[], connectionIds: string[]): Map<string, Set<string>> {
  const known = new Set(connectionIds);
  const related = new Map<string, Set<string>>();

  const add = (from: string, to: string): void => {
    if (from === to || !known.has(from) || !known.has(to)) return;
    if (!related.has(from)) related.set(from, new Set());
    related.get(from)!.add(to);
  };

  for (const edge of edges) {
    add(edge.from_connection_id, edge.to_connection_id);
    add(edge.to_connection_id, edge.from_connection_id);
  }

  return related;
}

async function creationPayload<C extends RelationshipsConnection>(deps: RelationshipsDeps<C>): Promise<RelationshipsRequest> {
  if (deps.fieldId() !== null) return {};

  const id = crypto.randomUUID();
  const key = await deps.createRecordKey(id);

  return { id, label_ciphertext: await deps.encrypt(key, RELATIONSHIPS_FIELD_LABEL) };
}

/** Moves the old edges into the Relationships field, then retires them. Does nothing when there are none. */
export async function moveLegacyRelationships<C extends RelationshipsConnection>(deps: RelationshipsDeps<C>): Promise<void> {
  if (deps.edges().length === 0) return;

  let response = await deps.post(await creationPayload(deps));
  await deps.apply(response);
  const fieldId = response.definition.id;

  const related = relatedIdsByConnection(deps.edges(), deps.connections().map((c) => c.id));
  /** What the server holds per connection, as last seen: from the page's rows, or from a conflict reply. */
  const stored = new Map<string, string | null>(deps.connections().map((c) => [c.id, c.attribute_values.find((v) => v.attribute_definition_id === fieldId)?.value_ciphertext ?? null]));
  let pending = [...related.keys()];

  for (let round = 0; round < MAX_ROUNDS && pending.length > 0; round++) {
    const values: NonNullable<RelationshipsRequest['values']> = [];

    for (const id of pending) {
      const key = await deps.getRecordKey(id);
      const base = stored.get(id) ?? null;
      const existing = base ? listEntries(await deps.decrypt(key, base)) : [];
      const merged = [...new Set([...existing, ...related.get(id)!])];

      // Already all there (an interrupted run got this far): nothing to write.
      if (merged.length === existing.length) continue;

      values.push({ connection_id: id, value_ciphertext: await deps.encrypt(key, paddedListText(merged.join('\n'))), base_ciphertext: base });
    }

    pending = [];
    for (let i = 0; i < values.length; i += VALUES_PER_CALL) {
      response = await deps.post({ values: values.slice(i, i + VALUES_PER_CALL) });
      await deps.apply(response);

      for (const [id, ciphertext] of Object.entries(response.conflicts)) {
        stored.set(id, ciphertext);
        pending.push(id);
      }
      // What was written is now what the server holds.
      for (const written of response.connections) {
        stored.set(written.id, written.attribute_values.find((v) => v.attribute_definition_id === fieldId)?.value_ciphertext ?? null);
      }
    }
  }

  // Anything still unresolved waits for the next unlock; the edges stay until every list is safe.
  if (pending.length > 0) return;

  response = await deps.post({ finish: true });
  if (Object.keys(response.conflicts).length === 0) deps.finished();
}
