/**
 * Per-field icon rules: which icon(s) a connection row shows based on the value of one custom field.
 *
 * The whole rule list for a field is one client-vault E2EE blob (definition.icon_rules_ciphertext, §0.1):
 * the server never sees conditions, regexes, choices, icon or colour keys, and never evaluates anything.
 * Everything here is pure and runs in the browser against already-decrypted values.
 *
 * Values arrive as the same strings ConnectionCard.vue builds: '' = unset, booleans are 'true'/'false',
 * identifier lists are one entry per line, a choice is the chosen option's text.
 *
 * Versioning: the blob carries its own `version` inside the ciphertext. Reading migrates an older blob up
 * to ICON_RULES_VERSION in memory (never writing); a blob NEWER than this client knows is reported as
 * `newer` and must be treated as read-only so a stale tab can't overwrite it with an older shape.
 */

export const ICON_RULES_VERSION = 1;
export const MAX_RULES_PER_FIELD = 20;
const MAX_PATTERN_LENGTH = 200;
const MAX_VALUE_LENGTH = 2000;

export type RuleCondition =
  | { op: 'nonEmpty' }
  | { op: 'empty' }
  | { op: 'isTrue' }
  | { op: 'isFalse' }
  | { op: 'choiceIn'; choices: string[] }
  /** Matched case-insensitively with the unicode flag ('iu'), exactly as the pattern preview and every other pattern in the app does. `preview` is the owner's persisted example lines for the visual editor's live preview; it lives inside the encrypted blob and plays no part in matching. */
  | { op: 'regex'; pattern: string; preview?: string | null }
  | { op: 'range'; min?: number; max?: number }
  /** How many entries an identifier list holds (blank lines don't count), inclusive bounds: min 3 + max 3 means exactly three. */
  | { op: 'count'; min?: number; max?: number };

export type RuleOp = RuleCondition['op'];

export interface IconRule {
  id: string;
  /** An IconKey (app/Support/IconKey.php). */
  icon: string;
  /** A ColorPalette key (app/Support/ColorPalette.php), never a raw hex. null = the default row-icon colour. */
  color: string | null;
  /** Optional tooltip text. */
  label?: string;
  condition: RuleCondition;
}

export interface IconRuleSet {
  version: number;
  /** First matching rule wins for this field, instead of every matching rule showing. */
  stopAfterFirst: boolean;
  rules: IconRule[];
}

export interface ResolvedIcon {
  ruleId: string;
  icon: string;
  color: string | null;
  label: string | null;
}

export type ParsedRuleSet =
  | { kind: 'ok'; set: IconRuleSet }
  | { kind: 'newer'; version: number }
  | { kind: 'invalid' };

const TEXT_TYPES = ['text', 'textarea', 'url', 'email', 'phone'];

/** Which conditions make sense for which field type. The editor offers exactly these, and evaluation ignores anything else. */
export function allowedOps(type: string): RuleOp[] {
  if (type === 'boolean') return ['isTrue', 'isFalse', 'nonEmpty', 'empty'];
  if (type === 'radio') return ['choiceIn', 'nonEmpty', 'empty'];
  if (type === 'number') return ['range', 'nonEmpty', 'empty'];
  if (type === 'identifier_list' || type === 'list') return ['nonEmpty', 'empty', 'count', 'regex'];
  if (TEXT_TYPES.includes(type) || type === 'identifier') return ['nonEmpty', 'empty', 'regex'];

  return ['nonEmpty', 'empty'];
}

export function emptyRuleSet(): IconRuleSet {
  return { version: ICON_RULES_VERSION, stopAfterFirst: false, rules: [] };
}

/** One entry per version N: turns a version-N blob into a version-N+1 blob. Empty while version 1 is the only one. */
const MIGRATIONS: Record<number, (raw: Record<string, unknown>) => Record<string, unknown>> = {};

function migrate(raw: Record<string, unknown>): Record<string, unknown> | null {
  let current = raw;
  let version = typeof current.version === 'number' ? current.version : 1;

  while (version < ICON_RULES_VERSION) {
    const step = MIGRATIONS[version];
    if (!step) return null;
    current = { ...step(current), version: version + 1 };
    version += 1;
  }

  return current;
}

/** Parses a decrypted blob. Never throws. */
export function parseIconRules(json: string): ParsedRuleSet {
  let raw: unknown;
  try {
    raw = JSON.parse(json);
  } catch {
    return { kind: 'invalid' };
  }

  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return { kind: 'invalid' };

  const record = raw as Record<string, unknown>;
  const version = typeof record.version === 'number' ? record.version : 1;
  if (version > ICON_RULES_VERSION) return { kind: 'newer', version };

  const migrated = migrate(record);
  if (!migrated || !Array.isArray(migrated.rules)) return { kind: 'invalid' };

  const rules = (migrated.rules as unknown[]).filter(isRule).slice(0, MAX_RULES_PER_FIELD);

  return { kind: 'ok', set: { version: ICON_RULES_VERSION, stopAfterFirst: migrated.stopAfterFirst === true, rules } };
}

export function serializeIconRules(set: IconRuleSet): string {
  return JSON.stringify({ ...set, version: ICON_RULES_VERSION, rules: set.rules.slice(0, MAX_RULES_PER_FIELD) });
}

function isRule(value: unknown): value is IconRule {
  if (typeof value !== 'object' || value === null) return false;
  const rule = value as Partial<IconRule>;

  return typeof rule.id === 'string'
    && typeof rule.icon === 'string'
    && (rule.color === null || typeof rule.color === 'string')
    && typeof rule.condition === 'object' && rule.condition !== null
    && typeof (rule.condition as { op?: unknown }).op === 'string';
}

const regexCache = new Map<string, RegExp | null>();

/** Compiles a user pattern defensively: length-capped, cached, null when invalid. Always 'iu', like PatternPreview.vue. */
export function compileRulePattern(pattern: string): RegExp | null {
  if (pattern === '' || pattern.length > MAX_PATTERN_LENGTH) return null;

  if (regexCache.has(pattern)) return regexCache.get(pattern) ?? null;

  let compiled: RegExp | null;
  try {
    compiled = new RegExp(pattern, 'iu');
  } catch {
    compiled = null;
  }
  regexCache.set(pattern, compiled);

  return compiled;
}

/** Whether one condition holds for a decrypted value of a field of this type. Unknown ops and ops not allowed for the type never match. */
export function conditionMatches(condition: RuleCondition, type: string, value: string): boolean {
  if (!allowedOps(type).includes(condition.op)) return false;

  const trimmed = value.trim();

  switch (condition.op) {
    case 'nonEmpty':
      return trimmed !== '';
    case 'empty':
      return trimmed === '';
    case 'isTrue':
      return trimmed === 'true';
    case 'isFalse':
      return trimmed === 'false';
    case 'choiceIn':
      return trimmed !== '' && Array.isArray(condition.choices) && condition.choices.includes(value);
    case 'range': {
      if (trimmed === '') return false;
      const number = Number(trimmed);
      if (!Number.isFinite(number)) return false;
      if (typeof condition.min === 'number' && number < condition.min) return false;
      if (typeof condition.max === 'number' && number > condition.max) return false;

      return true;
    }
    case 'count': {
      const entries = value.split('\n').filter((entry) => entry.trim() !== '').length;
      if (typeof condition.min === 'number' && entries < condition.min) return false;
      if (typeof condition.max === 'number' && entries > condition.max) return false;

      // A rule with neither bound has nothing to say, so it never matches (the editor won't save one).
      return typeof condition.min === 'number' || typeof condition.max === 'number';
    }
    case 'regex': {
      const regex = compileRulePattern(condition.pattern);
      if (!regex) return false;

      // A list is matched entry by entry, anything else as one string.
      const candidates = type === 'identifier_list' || type === 'list' ? value.split('\n').filter((entry) => entry.trim() !== '') : [value];

      return candidates.some((candidate) => regex.test(candidate.slice(0, MAX_VALUE_LENGTH)));
    }
    default:
      return false;
  }
}

/** The icons one field contributes to one connection, in rule order. */
export function evaluateRules(set: IconRuleSet, type: string, value: string): ResolvedIcon[] {
  const resolved: ResolvedIcon[] = [];

  for (const rule of set.rules.slice(0, MAX_RULES_PER_FIELD)) {
    if (!conditionMatches(rule.condition, type, value)) continue;

    resolved.push({ ruleId: rule.id, icon: rule.icon, color: rule.color, label: rule.label?.trim() ? rule.label.trim() : null });
    if (set.stopAfterFirst) break;
  }

  return resolved;
}
