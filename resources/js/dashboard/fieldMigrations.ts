/**
 * Field migrations ("Migrate field"): changing a custom field's type in place. Which changes exist is decided by the
 * server (App\Support\FieldMigrations, shared to the page as `fieldMigrations`); this file holds what the browser does
 * for them: converting a decrypted value, re-filtering icon rules, and finding the features a change would break.
 * Everything here is pure.
 */
import { listEntries, listText, paddedListText } from './encryptedList';
import { type IconRuleSet, allowedOps } from './iconRules';

/** What happens to the values' encryption: e2ee stays E2EE; to_plain leaves it; to_e2ee enters it; plain never had it. */
export type MigrationTier = 'e2ee' | 'to_plain' | 'to_e2ee' | 'plain';

export interface MigrationPath {
  from: string;
  to: string;
  tier: MigrationTier;
  /** Some values can come out different from how they went in. */
  lossy: boolean;
  /** Present when several entries must become one: the owner picks how. */
  strategies?: string[];
}

/** The choices a yes/no field gets when it becomes a choice field. */
export const BOOLEAN_CHOICES = ['Yes', 'No'] as const;

export function pathsFrom(paths: MigrationPath[], type: string): MigrationPath[] {
  return paths.filter((p) => p.from === type);
}

/** Whether the opposite change exists too, i.e. the owner could undo this one (lossy changes still can't restore what was lost). */
export function hasWayBack(paths: MigrationPath[], path: MigrationPath): boolean {
  return paths.some((p) => p.from === path.to && p.to === path.from);
}

/** Labels of the features that use the field but can't use `to`. `types` maps a feature label to the field types it accepts. */
export function featuresBrokenBy(usedBy: string[], types: Record<string, string[]>, to: string): string[] {
  return usedBy.filter((label) => !(types[label] ?? []).includes(to));
}

/**
 * The plaintext to encrypt for a value that stays E2EE (or enters it from a list): '' means the value comes out
 * empty and is removed. `text` is the decrypted old value.
 */
export function convertedText(from: string, to: string, text: string): string {
  if (to === 'list') return paddedListText(text);
  if (to === 'textarea' && from === 'list') return listText(text);
  if (to === 'text' && from === 'textarea') return text.split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== '').join(' ');

  return text;
}

/** The value of a single identifier made from a text value. */
export function identifierOf(text: string): string {
  return text.trim();
}

/** The entries of an identifier list made from a list value. */
export function identifierEntriesOf(listValue: string): string[] {
  return listEntries(listValue);
}

/** A list value (padded, ready to encrypt) made from an identifier list's entries. */
export function listTextOf(entries: string[]): string {
  return paddedListText(entries.join('\n'));
}

/** The one choice a value of the old type maps to when a yes/no field becomes a choice field. */
export function choiceOfBoolean(value: boolean | null | undefined): string {
  return value ? BOOLEAN_CHOICES[0] : BOOLEAN_CHOICES[1];
}

/** Drops the icon rules whose condition makes no sense for the new type (evaluation would silently ignore them anyway). */
export function rulesFor(set: IconRuleSet, newType: string): IconRuleSet {
  const ops = allowedOps(newType);

  return { ...set, rules: set.rules.filter((r) => ops.includes(r.condition.op)) };
}
