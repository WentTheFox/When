// One-off generator for tests/Fixtures/crypto/argon2id.json — proves
// App\Services\Crypto\Argon2id (libsodium) and resources/js/crypto/argon2.ts
// (hash-wasm) derive the identical key for the same passphrase+salt. Not
// part of the app or its test run; re-run manually only if either side's
// ARGON2ID_PROFILE changes.
import { deriveKeyFromPassphrase, generateSalt } from '../resources/js/crypto/argon2.ts';
import { bytesToBase64 } from '../resources/js/crypto/encoding.ts';
import { writeFileSync } from 'node:fs';

const passphrase = 'correct horse battery staple interop test';
const salt = generateSalt(); // 16 bytes, base64 — matches libsodium's SALTBYTES requirement.
const { keyBytes } = await deriveKeyFromPassphrase(passphrase, salt);

writeFileSync(
  new URL('../tests/Fixtures/crypto/argon2id.json', import.meta.url),
  JSON.stringify(
    { passphrase, salt_base64: salt, expected_key_base64: bytesToBase64(keyBytes) },
    null,
    2,
  ) + '\n',
);

console.log('Wrote tests/Fixtures/crypto/argon2id.json');
