/**
 * Thin browser-side half of the passkey (WebAuthn) flows. The server
 * (App\Services\PasskeyService, backed by lbuchs/webauthn) builds the option
 * objects with every binary field base64url-encoded; this decodes them for
 * navigator.credentials, then re-encodes the authenticator's response for the
 * trip back. No key material is ever handled here — the private key never
 * leaves the authenticator.
 */
import { base64UrlToBytes, bytesToBase64Url } from '../crypto/encoding';

interface DescriptorJson {
  id: string;
  type: 'public-key';
  transports?: AuthenticatorTransport[];
}

export interface CreationOptionsJson {
  publicKey: Omit<PublicKeyCredentialCreationOptions, 'challenge' | 'user' | 'excludeCredentials'> & {
    challenge: string;
    user: { id: string; name: string; displayName: string };
    excludeCredentials?: DescriptorJson[];
  };
}

export interface RequestOptionsJson {
  publicKey: Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
    challenge: string;
    allowCredentials?: DescriptorJson[];
  };
}

export interface RegistrationPayload {
  client_data_json: string;
  attestation_object: string;
  transports: string[];
}

export interface AssertionPayload {
  id: string;
  client_data_json: string;
  authenticator_data: string;
  signature: string;
}

export function passkeysSupported(): boolean {
  return typeof window !== 'undefined' && !!window.PublicKeyCredential && !!navigator.credentials;
}

function toDescriptors(list: DescriptorJson[] | undefined): PublicKeyCredentialDescriptor[] | undefined {
  return list?.map((descriptor) => ({ ...descriptor, id: base64UrlToBytes(descriptor.id) }));
}

export async function createPasskey(options: CreationOptionsJson): Promise<RegistrationPayload> {
  const { publicKey } = options;

  const credential = (await navigator.credentials.create({
    publicKey: {
      ...publicKey,
      challenge: base64UrlToBytes(publicKey.challenge),
      user: { ...publicKey.user, id: base64UrlToBytes(publicKey.user.id) },
      excludeCredentials: toDescriptors(publicKey.excludeCredentials),
    },
  })) as PublicKeyCredential | null;

  if (!credential) {
    throw new Error('No passkey was created.');
  }

  const response = credential.response as AuthenticatorAttestationResponse;

  return {
    client_data_json: bytesToBase64Url(new Uint8Array(response.clientDataJSON)),
    attestation_object: bytesToBase64Url(new Uint8Array(response.attestationObject)),
    transports: response.getTransports?.() ?? [],
  };
}

export async function getPasskeyAssertion(options: RequestOptionsJson): Promise<AssertionPayload> {
  const { publicKey } = options;

  const credential = (await navigator.credentials.get({
    publicKey: {
      ...publicKey,
      challenge: base64UrlToBytes(publicKey.challenge),
      allowCredentials: toDescriptors(publicKey.allowCredentials),
    },
  })) as PublicKeyCredential | null;

  if (!credential) {
    throw new Error('No passkey was provided.');
  }

  const response = credential.response as AuthenticatorAssertionResponse;

  return {
    id: bytesToBase64Url(new Uint8Array(credential.rawId)),
    client_data_json: bytesToBase64Url(new Uint8Array(response.clientDataJSON)),
    authenticator_data: bytesToBase64Url(new Uint8Array(response.authenticatorData)),
    signature: bytesToBase64Url(new Uint8Array(response.signature)),
  };
}

/** True when the user dismissed/denied the browser's passkey prompt (not a real failure). */
export function isPasskeyCancellation(error: unknown): boolean {
  return error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'AbortError');
}
