import { describe, expect, it } from 'vitest';
import {
  allowedOps,
  compileRulePattern,
  conditionMatches,
  evaluateRules,
  ICON_RULES_VERSION,
  MAX_RULES_PER_FIELD,
  parseIconRules,
  serializeIconRules,
} from '../iconRules';
import type { IconRule, IconRuleSet, RuleCondition } from '../iconRules';

const rule = (id: string, condition: RuleCondition, over: Partial<IconRule> = {}): IconRule => ({ id, icon: 'star', color: null, condition, ...over });
const set = (rules: IconRule[], stopAfterFirst = false): IconRuleSet => ({ version: ICON_RULES_VERSION, stopAfterFirst, rules });

describe('conditionMatches', () => {
  it('treats whitespace-only as empty', () => {
    expect(conditionMatches({ op: 'empty' }, 'text', '   ')).toBe(true);
    expect(conditionMatches({ op: 'nonEmpty' }, 'text', '   ')).toBe(false);
    expect(conditionMatches({ op: 'nonEmpty' }, 'text', 'hi')).toBe(true);
  });

  it('distinguishes an unset boolean from false', () => {
    expect(conditionMatches({ op: 'isTrue' }, 'boolean', 'true')).toBe(true);
    expect(conditionMatches({ op: 'isTrue' }, 'boolean', '')).toBe(false);
    expect(conditionMatches({ op: 'isFalse' }, 'boolean', 'false')).toBe(true);
    expect(conditionMatches({ op: 'isFalse' }, 'boolean', '')).toBe(false);
    expect(conditionMatches({ op: 'empty' }, 'boolean', '')).toBe(true);
  });

  it('matches a choice against the listed choices', () => {
    const condition: RuleCondition = { op: 'choiceIn', choices: ['Close', 'Family'] };

    expect(conditionMatches(condition, 'radio', 'Close')).toBe(true);
    expect(conditionMatches(condition, 'radio', 'Acquaintance')).toBe(false);
    expect(conditionMatches(condition, 'radio', '')).toBe(false);
  });

  it('applies numeric ranges inclusively, with open ends', () => {
    expect(conditionMatches({ op: 'range', min: 1, max: 3 }, 'number', '1')).toBe(true);
    expect(conditionMatches({ op: 'range', min: 1, max: 3 }, 'number', '3')).toBe(true);
    expect(conditionMatches({ op: 'range', min: 1, max: 3 }, 'number', '3.5')).toBe(false);
    expect(conditionMatches({ op: 'range', min: 10 }, 'number', '99')).toBe(true);
    expect(conditionMatches({ op: 'range', max: 0 }, 'number', '-4')).toBe(true);
    expect(conditionMatches({ op: 'range', min: 0 }, 'number', '')).toBe(false);
    expect(conditionMatches({ op: 'range', min: 0 }, 'number', 'abc')).toBe(false);
  });

  it('counts the entries of an identifier list, inclusive of both bounds, ignoring blank lines', () => {
    const list = 'alice\nbob\n\n  \ncarol';

    expect(conditionMatches({ op: 'count', min: 3 }, 'identifier_list', list)).toBe(true);
    expect(conditionMatches({ op: 'count', min: 4 }, 'identifier_list', list)).toBe(false);
    expect(conditionMatches({ op: 'count', max: 3 }, 'identifier_list', list)).toBe(true);
    expect(conditionMatches({ op: 'count', max: 2 }, 'identifier_list', list)).toBe(false);
    expect(conditionMatches({ op: 'count', min: 3, max: 3 }, 'identifier_list', list)).toBe(true); // exactly three
    expect(conditionMatches({ op: 'count', min: 2, max: 4 }, 'identifier_list', list)).toBe(true);
    expect(conditionMatches({ op: 'count', max: 0 }, 'identifier_list', '')).toBe(true); // an empty list has none
    expect(conditionMatches({ op: 'count', min: 1 }, 'identifier_list', '')).toBe(false);
  });

  it('never matches a count with no bounds at all, or on a field that is not a list', () => {
    expect(conditionMatches({ op: 'count' }, 'identifier_list', 'a\nb')).toBe(false);
    expect(conditionMatches({ op: 'count', min: 1 }, 'text', 'a\nb')).toBe(false);
    expect(conditionMatches({ op: 'count', min: 1 }, 'identifier', 'a')).toBe(false);
  });

  it('matches regexes case-insensitively, and each entry of a list', () => {
    expect(conditionMatches({ op: 'regex', pattern: '^ab' }, 'text', 'abc')).toBe(true);
    expect(conditionMatches({ op: 'regex', pattern: '^AB' }, 'text', 'abc')).toBe(true);
    expect(conditionMatches({ op: 'regex', pattern: '^ab$' }, 'text', 'abc')).toBe(false);
    expect(conditionMatches({ op: 'regex', pattern: '^b' }, 'identifier_list', 'a\nBEE')).toBe(true);
    expect(conditionMatches({ op: 'regex', pattern: '^b' }, 'text', 'a\nbee')).toBe(false);
  });

  it('never matches an op that is not allowed for the field type', () => {
    expect(conditionMatches({ op: 'isTrue' }, 'text', 'true')).toBe(false);
    expect(conditionMatches({ op: 'regex', pattern: '.' }, 'number', '5')).toBe(false);
    expect(conditionMatches({ op: 'range', min: 0 }, 'text', '5')).toBe(false);
  });
});

describe('compileRulePattern', () => {
  it('rejects empty, invalid and oversized patterns', () => {
    expect(compileRulePattern('')).toBeNull();
    expect(compileRulePattern('(')).toBeNull();
    expect(compileRulePattern('a'.repeat(201))).toBeNull();
  });

  it('caches compiled patterns', () => {
    expect(compileRulePattern('^x+$')).toBe(compileRulePattern('^x+$'));
  });

  it('does not let the persisted preview text affect matching', () => {
    expect(conditionMatches({ op: 'regex', pattern: '^zzz', preview: 'zzz\nabc' }, 'text', 'abc')).toBe(false);
  });

  it('never throws on a bad pattern inside a rule', () => {
    expect(conditionMatches({ op: 'regex', pattern: '(' }, 'text', 'x')).toBe(false);
  });
});

describe('allowedOps', () => {
  it('offers type-appropriate conditions', () => {
    expect(allowedOps('boolean')).toContain('isTrue');
    expect(allowedOps('radio')).toContain('choiceIn');
    expect(allowedOps('number')).toContain('range');
    expect(allowedOps('text')).toContain('regex');
    expect(allowedOps('date')).toEqual(['nonEmpty', 'empty']);
    expect(allowedOps('text')).not.toContain('range');
    expect(allowedOps('identifier_list')).toContain('count');
    expect(allowedOps('identifier')).not.toContain('count');
    expect(allowedOps('text')).not.toContain('count');
  });
});

describe('evaluateRules', () => {
  const rules = [
    rule('a', { op: 'nonEmpty' }, { icon: 'star', label: ' Has value ' }),
    rule('b', { op: 'regex', pattern: '^x' }, { icon: 'flag', color: 'red' }),
    rule('c', { op: 'empty' }),
  ];

  it('returns every matching rule, in order, with trimmed labels', () => {
    expect(evaluateRules(set(rules), 'text', 'xyz')).toEqual([
      { ruleId: 'a', icon: 'star', color: null, label: 'Has value' },
      { ruleId: 'b', icon: 'flag', color: 'red', label: null },
    ]);
  });

  it('stops after the first match when asked to', () => {
    expect(evaluateRules(set(rules, true), 'text', 'xyz').map((r) => r.ruleId)).toEqual(['a']);
  });

  it('returns nothing when nothing matches', () => {
    expect(evaluateRules(set([rule('b', { op: 'regex', pattern: '^x' })]), 'text', 'abc')).toEqual([]);
  });
});

describe('parseIconRules / serializeIconRules', () => {
  it('round-trips a rule set', () => {
    const original = set([rule('a', { op: 'choiceIn', choices: ['A'] }, { label: 'x' })], true);
    const parsed = parseIconRules(serializeIconRules(original));

    expect(parsed).toEqual({ kind: 'ok', set: original });
  });

  it('treats a blob without a version as version 1', () => {
    const parsed = parseIconRules(JSON.stringify({ rules: [rule('a', { op: 'nonEmpty' })] }));

    expect(parsed.kind).toBe('ok');
    if (parsed.kind === 'ok') expect(parsed.set.version).toBe(ICON_RULES_VERSION);
  });

  it('reports a blob from a newer client as newer, so it is left read-only', () => {
    expect(parseIconRules(JSON.stringify({ version: ICON_RULES_VERSION + 1, rules: [] }))).toEqual({ kind: 'newer', version: ICON_RULES_VERSION + 1 });
  });

  it('reports garbage as invalid instead of throwing', () => {
    expect(parseIconRules('not json').kind).toBe('invalid');
    expect(parseIconRules('[]').kind).toBe('invalid');
    expect(parseIconRules('null').kind).toBe('invalid');
    expect(parseIconRules(JSON.stringify({ version: 1, rules: 'nope' })).kind).toBe('invalid');
  });

  it('drops malformed rules and caps the rule count', () => {
    const many = Array.from({ length: MAX_RULES_PER_FIELD + 5 }, (_, i) => rule(`r${i}`, { op: 'nonEmpty' }));
    const parsed = parseIconRules(JSON.stringify({ version: 1, rules: [{ nope: true }, ...many] }));

    expect(parsed.kind).toBe('ok');
    if (parsed.kind === 'ok') expect(parsed.set.rules).toHaveLength(MAX_RULES_PER_FIELD);
  });

  it('serializing stamps the current version over a stale one', () => {
    const stale = { ...set([]), version: 0 };

    expect(JSON.parse(serializeIconRules(stale)).version).toBe(ICON_RULES_VERSION);
  });
});

describe('countryIn', () => {
  it('matches a country value against any listed code, case-insensitively, for country fields only', () => {
    const condition: RuleCondition = { op: 'countryIn', countries: ['DE', 'gb'] };

    expect(conditionMatches(condition, 'country', 'DE')).toBe(true);
    expect(conditionMatches(condition, 'country', 'GB')).toBe(true);
    expect(conditionMatches(condition, 'country', 'US')).toBe(false);
    expect(conditionMatches(condition, 'country', '')).toBe(false);
    expect(conditionMatches(condition, 'text', 'DE')).toBe(false);
    expect(allowedOps('country')).toContain('countryIn');
  });
});
