/**
 * The rule-driven icons in the connections list (see iconRules.ts for the rules themselves).
 *
 * Rules and the values they look at are E2EE, so this decrypts in the browser, once the vault is
 * unlocked, and only for fields that actually have rules. A value is decrypted at most once per
 * ciphertext (every encryption has its own IV, so a changed value is a new ciphertext and an
 * unchanged one is a cache hit). Icons appear only after a full decrypt pass has finished, and the
 * previous pass's icons stay up while the next one runs, so an `empty` rule can't flash on before
 * the values behind it have loaded.
 */
import { computed, type Ref, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { evaluateRules, parseIconRules } from './iconRules';
import type { ParsedRuleSet, ResolvedIcon } from './iconRules';

export interface RuleDefinition {
  id: string;
  type: string;
  icon_rules_ciphertext?: string | null;
}

export interface RuleConnectionValue {
  attribute_definition_id: string;
  value_ciphertext?: string | null;
  boolean_value?: boolean | null;
  identifier_value?: string | null;
  identifier_list_value?: string[] | null;
}

export interface RuleConnection {
  id: string;
  attribute_values: RuleConnectionValue[];
}

type RecordKeyGetter = (id: string) => Promise<CryptoKey>;

/** The same string ConnectionCard.vue builds for a value: '' = unset, booleans 'true'/'false', lists one entry per line. */
async function valueText(value: RuleConnectionValue, connectionKey: CryptoKey, cache: Map<string, string>): Promise<string> {
  if (value.value_ciphertext) {
    const cached = cache.get(value.value_ciphertext);
    if (cached !== undefined) return cached;

    const plain = await decryptString(connectionKey, value.value_ciphertext);
    cache.set(value.value_ciphertext, plain);

    return plain;
  }

  if (value.boolean_value != null) return String(value.boolean_value);
  if (value.identifier_list_value) return value.identifier_list_value.join('\n');

  return value.identifier_value ?? '';
}

export function useRuleIcons(
  connections: Ref<RuleConnection[]>,
  definitions: Ref<RuleDefinition[]>,
  vaultUnlocked: Ref<boolean>,
  getRecordKey: RecordKeyGetter,
) {
  /** Parsed rules per definition id, once decrypted. */
  const ruleSets = ref<Record<string, ParsedRuleSet>>({});
  /** connection id -> definition id -> value text, for rule-bearing definitions only. null until the first pass finishes. */
  const valueTexts = ref<Record<string, Record<string, string>> | null>(null);

  const ruleSetCache = new Map<string, ParsedRuleSet>();
  const valueCache = new Map<string, string>();
  let pass = 0;

  /** Cheap change signature: a rule blob or a value ciphertext changing is what triggers a pass. */
  const signature = computed(() => {
    const ruled = new Set(definitions.value.filter((d) => d.icon_rules_ciphertext).map((d) => d.id));
    const parts = definitions.value.map((d) => `${d.id}:${d.icon_rules_ciphertext ?? ''}`);

    for (const connection of connections.value) {
      for (const value of connection.attribute_values) {
        if (!ruled.has(value.attribute_definition_id)) continue;
        parts.push(`${connection.id}/${value.attribute_definition_id}:${value.value_ciphertext ?? ''}|${value.boolean_value ?? ''}|${value.identifier_value ?? ''}|${(value.identifier_list_value ?? []).join(',')}`);
      }
    }

    return parts.join('\n');
  });

  async function run(): Promise<void> {
    if (!vaultUnlocked.value) return;

    const current = ++pass;
    const ruled = definitions.value.filter((d) => d.icon_rules_ciphertext);

    try {
      const sets: Record<string, ParsedRuleSet> = {};
      for (const definition of ruled) {
        const ciphertext = definition.icon_rules_ciphertext!;
        let parsed = ruleSetCache.get(ciphertext);
        if (!parsed) {
          const key = await getRecordKey(definition.id);
          parsed = parseIconRules(await decryptString(key, ciphertext));
          ruleSetCache.set(ciphertext, parsed);
        }
        sets[definition.id] = parsed;
      }

      const ruledIds = new Set(ruled.map((d) => d.id));
      const texts: Record<string, Record<string, string>> = {};
      if (ruledIds.size > 0) {
        for (const connection of connections.value) {
          const relevant = connection.attribute_values.filter((v) => ruledIds.has(v.attribute_definition_id));
          const perDefinition: Record<string, string> = {};
          if (relevant.length > 0) {
            const key = await getRecordKey(connection.id);
            for (const value of relevant) perDefinition[value.attribute_definition_id] = await valueText(value, key, valueCache);
          }
          texts[connection.id] = perDefinition;
        }
      }

      // A newer pass started while this one was awaiting: its result wins.
      if (current !== pass) return;

      ruleSets.value = sets;
      valueTexts.value = texts;
    } catch (error) {
      console.error(error);
    }
  }

  watch([vaultUnlocked, signature], run, { immediate: true });

  /** connection id -> the icons its rules produce, in field order then rule order. */
  const ruleIcons = computed<Record<string, ResolvedIcon[]>>(() => {
    const texts = valueTexts.value;
    if (!texts || !vaultUnlocked.value) return {};

    const result: Record<string, ResolvedIcon[]> = {};
    for (const connection of connections.value) {
      const perDefinition = texts[connection.id];
      if (!perDefinition) continue;

      const icons: ResolvedIcon[] = [];
      for (const definition of definitions.value) {
        const parsed = ruleSets.value[definition.id];
        if (parsed?.kind !== 'ok' || parsed.set.rules.length === 0) continue;

        icons.push(...evaluateRules(parsed.set, definition.type, perDefinition[definition.id] ?? ''));
      }
      if (icons.length > 0) result[connection.id] = icons;
    }

    return result;
  });

  return { ruleIcons, ruleSets };
}
