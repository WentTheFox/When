/**
 * Which connections still need sorting out, so the Connections page can list them first.
 *
 * A connection made from a synced friend (VRChat, Discord, Telegram) starts with nothing but the ID it
 * came with: its name and an identifier field. Until the owner gives it anything else (a note, a tag, a
 * relationship, ...) it is a to-do, so it floats to the top, newest first, and stays there across page
 * loads. Giving it any other kind of field value sorts it out and it drops back to its normal place.
 * Nothing here is saved: it is derived from the data every time, so the stored alphabetical order is
 * never disturbed.
 */

export interface TriageValue {
  attribute_definition_id: string;
  value_ciphertext?: string | null;
  identifier_value?: string | null;
  identifier_list_value?: string[] | null;
  boolean_value?: boolean | null;
}

export interface TriageConnection {
  id: string;
  archived: boolean;
  /** ISO 8601; missing on a row that has not been refreshed since it was created in this page. */
  created_at?: string | null;
  attribute_values: TriageValue[];
}

/** A value counts as assigned when it holds something: a blank identifier, an empty list or an empty ciphertext is not one. */
function isAssigned(value: TriageValue): boolean {
  return (value.identifier_value ?? '').trim() !== ''
    || (value.identifier_list_value ?? []).some((entry) => entry.trim() !== '')
    || (value.value_ciphertext ?? '') !== ''
    || (value.boolean_value !== null && value.boolean_value !== undefined);
}

/**
 * True when the connection has at least one identifier / identifier list value and nothing else assigned. A
 * value of a field whose type is unknown (a deleted field) counts as something else: when in doubt it is
 * not a to-do. Archived connections were set aside on purpose.
 */
export function needsSortingOut(connection: TriageConnection, typeOf: (definitionId: string) => string | undefined): boolean {
  if (connection.archived) return false;

  let identifiers = 0;

  for (const value of connection.attribute_values) {
    if (!isAssigned(value)) continue;

    const type = typeOf(value.attribute_definition_id);
    if (type !== 'identifier' && type !== 'identifier_list') return false;

    identifiers++;
  }

  return identifiers > 0;
}

function createdAt(connection: TriageConnection): number {
  const time = connection.created_at ? Date.parse(connection.created_at) : Number.NaN;

  return Number.isNaN(time) ? Number.NEGATIVE_INFINITY : time;
}

/**
 * The list with the connections that need sorting out moved to the top, newest created first; everyone
 * else keeps the order they came in. Returns a new array. Equal times keep their relative order.
 */
export function floatUnsorted<T extends TriageConnection>(list: readonly T[], typeOf: (definitionId: string) => string | undefined): T[] {
  const unsorted = list
    .filter((connection) => needsSortingOut(connection, typeOf))
    .sort((a, b) => {
      const [x, y] = [createdAt(a), createdAt(b)];

      return x === y ? 0 : y > x ? 1 : -1;
    });

  if (unsorted.length === 0) return [...list];

  const ids = new Set(unsorted.map((connection) => connection.id));

  return [...unsorted, ...list.filter((connection) => !ids.has(connection.id))];
}
