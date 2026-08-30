// One-off generator for tests/Fixtures/crypto/ts_encrypted.json — proves
// TS-encrypted ciphertext decrypts correctly in PHP (AesGcm.php). Not part
// of the app or its test run; re-run manually only if the wire format in
// resources/js/crypto/aesgcm.ts changes.
import { encryptString, generateAesKey, exportAesKey } from '../resources/js/crypto/aesgcm.ts';
import { bytesToBase64 } from '../resources/js/crypto/encoding.ts';
import { writeFileSync } from 'node:fs';

const key = await generateAesKey();
const rawKey = await exportAesKey(key);
const plaintext = 'TS-encrypted interop fixture: the quick brown 🦊 jumps over 42 lazy calendars.';
const ciphertext = await encryptString(key, plaintext);

writeFileSync(
  new URL('../tests/Fixtures/crypto/ts_encrypted.json', import.meta.url),
  JSON.stringify({ key_base64: bytesToBase64(rawKey), plaintext, ciphertext_base64: ciphertext }, null, 2) + '\n',
);

console.log('Wrote tests/Fixtures/crypto/ts_encrypted.json');
