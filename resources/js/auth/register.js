// Signup crypto wiring (§0.3, Stage 3). Runs entirely client-side before the
// form ever hits the network: generate a salt, derive the vault key from the
// owner's passphrase, encrypt an initial empty key ring with it, and only
// then let the native form submit go through. The server never sees the
// passphrase or the derived key — only the salt and the resulting
// ciphertext, both opaque to it.
import { deriveKeyFromPassphrase, encryptKeyRing, emptyKeyRing, generateSalt } from '../crypto';

const form = document.getElementById('register-form');

if (form) {
  const passphraseInput = document.getElementById('passphrase');
  const passphraseConfirmInput = document.getElementById('passphrase_confirmation');
  const saltField = document.getElementById('passphrase_salt');
  const keyRingField = document.getElementById('key_ring_ciphertext');
  const submitButton = form.querySelector('button[type="submit"]');
  const errorEl = document.getElementById('passphrase-error');

  form.addEventListener('submit', async (event) => {
    if (form.dataset.cryptoReady === 'true') {
      return; // Already prepared — let the real submit through.
    }

    event.preventDefault();
    errorEl.textContent = '';

    const passphrase = passphraseInput.value;

    if (passphrase.length === 0) {
      errorEl.textContent = 'Please enter a passphrase.';
      return;
    }

    if (passphrase !== passphraseConfirmInput.value) {
      errorEl.textContent = 'Passphrase confirmation does not match.';
      return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Preparing your vault…';

    try {
      const salt = generateSalt();
      const { keyBytes } = await deriveKeyFromPassphrase(passphrase, salt);
      const vaultKey = await crypto.subtle.importKey(
        'raw',
        keyBytes,
        { name: 'AES-GCM' },
        false,
        ['encrypt', 'decrypt'],
      );

      const keyRingCiphertext = await encryptKeyRing(vaultKey, emptyKeyRing());

      saltField.value = salt;
      keyRingField.value = keyRingCiphertext;

      form.dataset.cryptoReady = 'true';
      form.requestSubmit(submitButton);
    } catch (error) {
      console.error(error);
      errorEl.textContent = 'Something went wrong preparing your vault. Please try again.';
      submitButton.disabled = false;
      submitButton.textContent = 'Create account';
    }
  });
}
