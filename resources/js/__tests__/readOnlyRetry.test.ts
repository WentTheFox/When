import axios, { AxiosError, type AxiosAdapter, type AxiosResponse } from 'axios';
import { describe, expect, it } from 'vitest';
import { getReadOnlyRetryDelay, installReadOnlyRetry } from '../readOnlyRetry';

function clientAnswering(statuses: { status: number; headers?: Record<string, string> }[]) {
  const calls: number[] = [];
  const adapter: AxiosAdapter = async (config) => {
    const next = statuses[Math.min(calls.length, statuses.length - 1)];
    calls.push(next.status);
    const response = { data: {}, status: next.status, statusText: '', headers: next.headers ?? {}, config } as AxiosResponse;
    if (next.status >= 400) {
      throw new AxiosError('failed', 'ERR_BAD_RESPONSE', config, null, response);
    }

    return response;
  };
  const client = axios.create({ adapter });
  installReadOnlyRetry(client, { sleep: async () => {}, jitterMs: () => 0 });

  return { client, calls };
}

const readOnly = { status: 503, headers: { 'retry-after': '5' } };

describe('read-only retry', () => {
  it('sends a request again after a 503 with Retry-After and returns the eventual success', async () => {
    const { client, calls } = clientAnswering([readOnly, readOnly, { status: 200 }]);

    expect((await client.post('/x')).status).toBe(200);
    expect(calls).toEqual([503, 503, 200]);
  });

  it('gives up after the retries are used up and throws the original error', async () => {
    const { client, calls } = clientAnswering([readOnly]);

    await expect(client.post('/x')).rejects.toMatchObject({ response: { status: 503 } });
    expect(calls).toHaveLength(4);
  });

  it('never retries a 503 without Retry-After or any other error', async () => {
    for (const answer of [{ status: 503 }, { status: 500, headers: { 'retry-after': '5' } }, { status: 422 }]) {
      const { client, calls } = clientAnswering([answer]);
      await expect(client.post('/x')).rejects.toBeDefined();
      expect(calls).toHaveLength(1);
    }
  });

  it('reads the delay only from a 503 carrying Retry-After', () => {
    const make = (status: number, headers: Record<string, string>) =>
      new AxiosError('x', undefined, undefined, null, { status, headers } as unknown as AxiosResponse);

    expect(getReadOnlyRetryDelay(make(503, { 'retry-after': '7' }))).toBe(7);
    expect(getReadOnlyRetryDelay(make(503, {}))).toBeNull();
    expect(getReadOnlyRetryDelay(make(500, { 'retry-after': '7' }))).toBeNull();
  });
});
