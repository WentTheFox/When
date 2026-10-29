import type { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';

export interface ReadOnlyRetryOptions {
  /** How many times a rejected request is sent again before the error reaches the caller. */
  maxRetries?: number;
  /** Upper bound for the wait taken from Retry-After, in seconds. */
  maxDelaySeconds?: number;
  /** Extra random wait in milliseconds so waiting clients don't all come back at the same moment. */
  jitterMs?: () => number;
  sleep?: (ms: number) => Promise<void>;
}

type RetryConfig = InternalAxiosRequestConfig & { __readOnlyRetries?: number };

/**
 * Seconds to wait before retrying, or null when the response isn't the server's "read-only while
 * deploying" rejection: a 503 that carries Retry-After (BlockWritesInReadOnlyMode). The server refuses
 * those before any handler runs, so sending the request again can't apply a write twice.
 */
export function getReadOnlyRetryDelay(error: AxiosError): number | null {
  if (error.response?.status !== 503) return null;
  const seconds = Number(error.response.headers?.['retry-after']);

  return Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
}

/**
 * Makes requests rejected during a deploy's read-only window retry transparently, so a form just stays
 * "processing" until the window is over. Inertia 2 sends its visits through the global axios instance,
 * so installing this on it covers page visits and the app's own axios calls alike. After the retries
 * are used up the original error is thrown as before.
 */
export function installReadOnlyRetry(client: AxiosInstance, options: ReadOnlyRetryOptions = {}): void {
  const {
    maxRetries = 3,
    maxDelaySeconds = 10,
    jitterMs = () => Math.random() * 1000,
    sleep = (ms) => new Promise<void>((resolve) => setTimeout(resolve, ms)),
  } = options;

  client.interceptors.response.use(undefined, async (error: AxiosError) => {
    const config = error.config as RetryConfig | undefined;
    const delaySeconds = getReadOnlyRetryDelay(error);
    const attempt = config?.__readOnlyRetries ?? 0;
    if (!config || delaySeconds === null || attempt >= maxRetries || config.signal?.aborted) {
      throw error;
    }

    config.__readOnlyRetries = attempt + 1;
    await sleep(Math.min(Math.max(delaySeconds, 1), maxDelaySeconds) * 1000 + jitterMs());

    return client.request(config);
  });
}
