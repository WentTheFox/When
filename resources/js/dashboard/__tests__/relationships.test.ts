import { describe, expect, it } from 'vitest';
import { removalPrompt } from '../relationships';

describe('removalPrompt', () => {
  const prompt = removalPrompt('Alice Smith', 'Bob Jones');

  it('names both connections, so it is clear which link is being removed', () => {
    expect(prompt.title).toContain('Bob Jones');
    expect(prompt.message).toContain('Alice Smith');
    expect(prompt.message).toContain('Bob Jones');
  });

  it('is a destructive confirmation', () => {
    expect(prompt.variant).toBe('danger');
    expect(prompt.confirmText).toBe('Remove');
  });

  it('says the link is removed from the other connection too (it is a single undirected edge)', () => {
    expect(prompt.message).toMatch(/other|side|each other/i);
  });
});
