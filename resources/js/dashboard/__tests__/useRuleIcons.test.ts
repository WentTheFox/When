import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { serializeIconRules } from '../iconRules';
import type { IconRule, IconRuleSet } from '../iconRules';
import { useRuleIcons } from '../useRuleIcons';
import type { RuleConnection, RuleDefinition } from '../useRuleIcons';

// The fake "ciphertext" is `enc:` + plaintext; the fake key is irrelevant.
vi.mock('../../crypto', () => ({
  decryptString: vi.fn(async (_key: unknown, ciphertext: string) => ciphertext.replace(/^enc:/, '')),
}));

const rule = (id: string, condition: IconRule['condition'], icon = 'star'): IconRule => ({ id, icon, color: null, condition });
const blob = (rules: IconRule[], stopAfterFirst = false): string => `enc:${serializeIconRules({ version: 1, stopAfterFirst, rules } satisfies IconRuleSet)}`;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const getRecordKey = async () => ({}) as CryptoKey;

function setup(definitions: RuleDefinition[], connections: RuleConnection[], unlocked = true) {
  const defs = ref(definitions);
  const conns = ref(connections);
  const vaultUnlocked = ref(unlocked);
  const result = useRuleIcons(conns, defs, vaultUnlocked, getRecordKey);

  return { defs, conns, vaultUnlocked, ...result };
}

describe('useRuleIcons', () => {
  it('shows an icon for a decrypted text value that a rule matches', async () => {
    const { ruleIcons } = setup(
      [{ id: 'd1', type: 'text', icon_rules_ciphertext: blob([rule('r1', { op: 'regex', pattern: '^vip' }, 'crown')]) }],
      [
        { id: 'c1', attribute_values: [{ attribute_definition_id: 'd1', value_ciphertext: 'enc:vip friend' }] },
        { id: 'c2', attribute_values: [{ attribute_definition_id: 'd1', value_ciphertext: 'enc:someone' }] },
      ],
    );
    await settle();

    expect(ruleIcons.value).toEqual({ c1: [{ ruleId: 'r1', icon: 'crown', color: null, label: null }] });
  });

  it('applies an "is empty" rule to a connection that holds no value row at all', async () => {
    const { ruleIcons } = setup(
      [{ id: 'd1', type: 'text', icon_rules_ciphertext: blob([rule('r1', { op: 'empty' })]) }],
      [{ id: 'c1', attribute_values: [] }, { id: 'c2', attribute_values: [{ attribute_definition_id: 'd1', value_ciphertext: 'enc:x' }] }],
    );
    await settle();

    expect(Object.keys(ruleIcons.value)).toEqual(['c1']);
  });

  it('reads booleans without decrypting anything', async () => {
    const { ruleIcons } = setup(
      [{ id: 'd1', type: 'boolean', icon_rules_ciphertext: blob([rule('r1', { op: 'isTrue' })]) }],
      [
        { id: 'c1', attribute_values: [{ attribute_definition_id: 'd1', boolean_value: true }] },
        { id: 'c2', attribute_values: [{ attribute_definition_id: 'd1', boolean_value: false }] },
      ],
    );
    await settle();

    expect(Object.keys(ruleIcons.value)).toEqual(['c1']);
  });

  it('shows nothing while the vault is locked, and fills in once it unlocks', async () => {
    const { ruleIcons, vaultUnlocked } = setup(
      [{ id: 'd1', type: 'boolean', icon_rules_ciphertext: blob([rule('r1', { op: 'isTrue' })]) }],
      [{ id: 'c1', attribute_values: [{ attribute_definition_id: 'd1', boolean_value: true }] }],
      false,
    );
    await settle();
    expect(ruleIcons.value).toEqual({});

    vaultUnlocked.value = true;
    await settle();
    expect(Object.keys(ruleIcons.value)).toEqual(['c1']);
  });

  it('follows a value being edited', async () => {
    const { ruleIcons, conns } = setup(
      [{ id: 'd1', type: 'text', icon_rules_ciphertext: blob([rule('r1', { op: 'nonEmpty' })]) }],
      [{ id: 'c1', attribute_values: [] }],
    );
    await settle();
    expect(ruleIcons.value).toEqual({});

    conns.value = [{ id: 'c1', attribute_values: [{ attribute_definition_id: 'd1', value_ciphertext: 'enc:now set' }] }];
    await settle();
    expect(Object.keys(ruleIcons.value)).toEqual(['c1']);
  });

  it('orders icons by field order, then rule order, and honours stop-after-first', async () => {
    const { ruleIcons } = setup(
      [
        { id: 'd1', type: 'boolean', icon_rules_ciphertext: blob([rule('a', { op: 'isTrue' }, 'star'), rule('b', { op: 'nonEmpty' }, 'heart')], true) },
        { id: 'd2', type: 'boolean', icon_rules_ciphertext: blob([rule('c', { op: 'isTrue' }, 'flag')]) },
      ],
      [{ id: 'c1', attribute_values: [{ attribute_definition_id: 'd1', boolean_value: true }, { attribute_definition_id: 'd2', boolean_value: true }] }],
    );
    await settle();

    expect(ruleIcons.value.c1.map((i) => i.ruleId)).toEqual(['a', 'c']);
  });

  it('ignores fields without rules, and rule sets from a newer version', async () => {
    const newer = `enc:${JSON.stringify({ version: 99, rules: [rule('r1', { op: 'nonEmpty' })] })}`;
    const { ruleIcons, ruleSets } = setup(
      [{ id: 'd1', type: 'text' }, { id: 'd2', type: 'text', icon_rules_ciphertext: newer }],
      [{ id: 'c1', attribute_values: [{ attribute_definition_id: 'd2', value_ciphertext: 'enc:x' }] }],
    );
    await settle();

    expect(ruleIcons.value).toEqual({});
    expect(ruleSets.value.d2).toEqual({ kind: 'newer', version: 99 });
  });
});
