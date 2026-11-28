export interface GraphNode {
  id: string;
  type: 'connection' | 'source';
  color_key: string | null;
  /** Sources only: the icon key, E2EE under the key of record `icon_key_id` (the source, or else its category). */
  icon_ciphertext?: string | null;
  icon_key_id?: string | null;
}
export interface GraphEdge { from: string; to: string; kind: 'introduced' | 'mutual' }
/** One connection's stored value in a field the owner chose for the graph: E2EE, only the browser can read it. */
export interface FieldValue { connection_id: string; attribute_definition_id: string; type: string; value_ciphertext: string }
export interface GraphResponse { seed: number; nodes: GraphNode[]; edges: GraphEdge[]; field_values: FieldValue[] }
export interface Point { x: number; y: number }
