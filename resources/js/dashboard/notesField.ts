/**
 * Connection notes are an ordinary multi-line custom field now, not a built-in property.
 *
 * Everything here runs in the browser because only the browser can encrypt: the field's label, its default
 * icon rule, and any text merged or appended to a note. The server's one endpoint
 * (ConnectionController::notesField) creates the field once per owner, moves each connection's old note into
 * it as opaque ciphertext (the old note and a field value are encrypted with the same key, the connection's
 * own, so no re-encryption is needed), and saves values the browser hands it.
 *
 * Where a connection already has a value in the field, the server can't merge the two (it can't read them),
 * so it reports a conflict and this module merges them here and calls again.
 */
import { serializeIconRules } from './iconRules';

export const NOTES_FIELD_LABEL = 'Notes';
/** An IconKey: the default rule shows it for a connection that has notes, as the old built-in mark did. */
export const NOTES_ICON = 'sticky-note';

export interface NotesFieldDefinition {
  id: string;
  label_ciphertext: string;
  type: string;
  options_ciphertext: string | null;
  icon_rules_ciphertext: string | null;
}

export interface NotesConnection {
  id: string;
  notes_ciphertext: string | null;
  attribute_values: { attribute_definition_id: string; value_ciphertext?: string | null }[];
}

export interface NotesFieldRequest {
  id?: string;
  label_ciphertext?: string;
  icon_rules_ciphertext?: string;
  values?: { connection_id: string; value_ciphertext: string }[];
}

export interface NotesFieldResponse<C extends NotesConnection = NotesConnection> {
  definition: NotesFieldDefinition;
  created: boolean;
  connections: C[];
  conflicts: string[];
}

export interface NotesFieldDeps<C extends NotesConnection = NotesConnection> {
  /** The field the page already knows holds the notes, if any. */
  fieldId: () => string | null;
  connections: () => C[];
  getRecordKey: (id: string) => Promise<CryptoKey>;
  createRecordKey: (id: string) => Promise<CryptoKey>;
  encrypt: (key: CryptoKey, text: string) => Promise<string>;
  decrypt: (key: CryptoKey, ciphertext: string) => Promise<string>;
  post: (body: NotesFieldRequest) => Promise<NotesFieldResponse<C>>;
  /** Puts a response into the page's own state (the definition, the id, the updated connections). */
  apply: (response: NotesFieldResponse<C>) => void | Promise<void>;
}

/** The server takes this many values per call. */
export const VALUES_PER_CALL = 500;

/** Two notes about the same person, kept apart by a blank line; either may be empty. */
export function joinNotes(first: string, second: string): string {
  const a = first.trim();
  const b = second.trim();

  return a && b ? `${a}\n\n${b}` : a || b;
}

/** What the field's first call has to carry: how to make it, unless the page already knows it exists. */
async function creationPayload<C extends NotesConnection>(deps: NotesFieldDeps<C>): Promise<NotesFieldRequest> {
  if (deps.fieldId() !== null) return {};

  const id = crypto.randomUUID();
  const key = await deps.createRecordKey(id);

  return {
    id,
    label_ciphertext: await deps.encrypt(key, NOTES_FIELD_LABEL),
    icon_rules_ciphertext: await deps.encrypt(key, serializeIconRules({
      version: 1,
      stopAfterFirst: false,
      rules: [{ id: crypto.randomUUID(), icon: NOTES_ICON, color: null, label: 'Has notes', condition: { op: 'nonEmpty' } }],
    })),
  };
}

/** The decrypted text a connection holds in the notes field ('' when it holds none). */
export async function notesTextOf<C extends NotesConnection>(deps: NotesFieldDeps<C>, connection: C): Promise<string> {
  const fieldId = deps.fieldId();
  const ciphertext = fieldId === null ? null : connection.attribute_values.find((v) => v.attribute_definition_id === fieldId)?.value_ciphertext;

  return ciphertext ? deps.decrypt(await deps.getRecordKey(connection.id), ciphertext) : '';
}

/** Makes sure the notes field exists (creating it, with its default rule, if this is the first time). Needs the vault unlocked only when it has to create it. */
export async function ensureNotesField<C extends NotesConnection>(deps: NotesFieldDeps<C>): Promise<void> {
  if (deps.fieldId() !== null) return;

  await deps.apply(await deps.post(await creationPayload(deps)));
}

/**
 * The one-time move: every connection that still has an old built-in note gets it moved into the field.
 * Conflicts (a connection that already has a value there, say from a tab that hadn't reloaded) are merged
 * here and saved. Does nothing when no connection has an old note.
 */
export async function moveLegacyNotes<C extends NotesConnection>(deps: NotesFieldDeps<C>): Promise<void> {
  if (!deps.connections().some((c) => c.notes_ciphertext)) return;

  const response = await deps.post(await creationPayload(deps));
  await deps.apply(response);

  const values: { connection_id: string; value_ciphertext: string }[] = [];

  for (const id of response.conflicts) {
    const connection = deps.connections().find((c) => c.id === id);
    if (!connection?.notes_ciphertext) continue;

    const key = await deps.getRecordKey(id);
    const legacy = await deps.decrypt(key, connection.notes_ciphertext);
    const existing = await notesTextOf(deps, connection);

    values.push({ connection_id: id, value_ciphertext: await deps.encrypt(key, joinNotes(existing, legacy)) });
  }

  for (let i = 0; i < values.length; i += VALUES_PER_CALL) {
    await deps.apply(await deps.post({ values: values.slice(i, i + VALUES_PER_CALL) }));
  }
}

/** Saves new notes text for some connections (the VRChat note import), creating the field first if there isn't one. */
export async function saveNotes<C extends NotesConnection>(deps: NotesFieldDeps<C>, updates: { connectionId: string; text: string }[]): Promise<void> {
  await ensureNotesField(deps);

  const values: { connection_id: string; value_ciphertext: string }[] = [];
  for (const update of updates) {
    values.push({ connection_id: update.connectionId, value_ciphertext: await deps.encrypt(await deps.getRecordKey(update.connectionId), update.text) });
  }

  for (let i = 0; i < values.length; i += VALUES_PER_CALL) {
    await deps.apply(await deps.post({ values: values.slice(i, i + VALUES_PER_CALL) }));
  }
}
