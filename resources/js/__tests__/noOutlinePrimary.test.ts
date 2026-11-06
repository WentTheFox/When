import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * House rule: never use Bootstrap's outlined primary button (`btn-outline-primary`, which in bootstrap-vue-next is
 * `<BButton variant="outline-primary">`). Blue text on a thin blue outline is unreadable. Use `outline-secondary`
 * for a quiet action, solid `primary` for the one main action, `outline-danger` for a destructive one.
 *
 * This is a source-level check over everything under resources/ (templates, scripts, styles, Blade views), so a
 * new use fails here rather than getting past review. Tests are skipped, since they may name the style in order
 * to say it is not allowed.
 */

const RESOURCES = join(__dirname, '..', '..');
const EXTENSIONS = ['.vue', '.ts', '.js', '.css', '.scss', '.php'];

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    if (name === 'node_modules' || name === '__tests__') return [];
    const path = join(dir, name);

    return statSync(path).isDirectory() ? sourceFiles(path) : EXTENSIONS.some((ext) => name.endsWith(ext)) ? [path] : [];
  });
}

/** Every `file:line` that names the style, whether as a class or as a BButton variant. */
function offenders(): string[] {
  return sourceFiles(RESOURCES).flatMap((file) => readFileSync(file, 'utf8')
    .split('\n')
    .flatMap((line, index) => (/outline-primary/.test(line) ? [`${relative(RESOURCES, file)}:${index + 1}  ${line.trim().slice(0, 100)}`] : [])));
}

describe('no btn-outline-primary', () => {
  it('scans a plausible number of source files', () => {
    expect(sourceFiles(RESOURCES).length).toBeGreaterThan(100);
  });

  it('is not used anywhere under resources/', () => {
    expect(offenders()).toEqual([]);
  });
});
