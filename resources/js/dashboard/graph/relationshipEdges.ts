import { decryptString } from '../../crypto';
import { listEntries } from '../encryptedList';
import type { FieldValue, GraphEdge } from './types';

/**
 * Connection-to-connection edges come from the relationship fields the owner chose for the graph. Those lists
 * are end-to-end encrypted, so they are decrypted here and returned as undirected edges (one per pair, however
 * many fields or sides list it). Values that fail to decrypt are skipped.
 */
export async function buildRelationshipEdges(
  fieldValues: FieldValue[],
  getRecordKey: (connectionId: string) => Promise<CryptoKey>,
): Promise<GraphEdge[]> {
  const pairs = new Map<string, GraphEdge>();
  for (const value of fieldValues) {
    if (value.type !== 'relationship' && value.type !== 'connection') continue;

    try {
      const plain = await decryptString(await getRecordKey(value.connection_id), value.value_ciphertext);
      for (const other of value.type === 'relationship' ? listEntries(plain) : [plain.trim()]) {
        if (!other || other === value.connection_id) continue;
        const [from, to] = [value.connection_id, other].sort();
        pairs.set(`${from}|${to}`, { from, to, kind: 'mutual' });
      }
    } catch (error) {
      console.error(error);
    }
  }

  return [...pairs.values()];
}
