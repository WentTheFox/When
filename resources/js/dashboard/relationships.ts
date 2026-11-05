import type { ConfirmOptions } from './confirmModal';

/**
 * The confirmation shown before a "knows" relationship is removed. It names
 * both connections, so there is no doubt which link is about to go, however the
 * list happened to be laid out.
 */
export function removalPrompt(thisName: string, otherName: string): ConfirmOptions {
  return {
    title: `Remove the link to “${otherName}”?`,
    message: `${thisName} and ${otherName} will no longer be linked as knowing each other. This also removes it from ${otherName}'s side.`,
    confirmText: 'Remove',
    variant: 'danger',
  };
}
