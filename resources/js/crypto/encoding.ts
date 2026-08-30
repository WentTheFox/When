/**
 * Base64 / base64url helpers. Base64url (no padding) is used specifically
 * for anything that travels in a URL fragment (share-link content keys),
 * since it's safe unescaped in a URL. Standard base64 is used for opaque
 * blobs going into JSON bodies / the database.
 */

export function bytesToBase64(bytes: Uint8Array<ArrayBuffer>): string {
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

export function base64ToBytes(base64: string): Uint8Array<ArrayBuffer> {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

export function bytesToBase64Url(bytes: Uint8Array<ArrayBuffer>): string {
  return bytesToBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function base64UrlToBytes(base64url: string): Uint8Array<ArrayBuffer> {
  let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
  while (base64.length % 4 !== 0) {
    base64 += '=';
  }
  return base64ToBytes(base64);
}

export function utf8ToBytes(text: string): Uint8Array<ArrayBuffer> {
  return new TextEncoder().encode(text);
}

export function bytesToUtf8(bytes: Uint8Array<ArrayBuffer>): string {
  return new TextDecoder().decode(bytes);
}
