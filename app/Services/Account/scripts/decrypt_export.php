#!/usr/bin/env php
<?php

/**
 * Decrypts every "e2ee"-tier file in a When data export in place, writing a
 * "<file>.decrypted.json" copy next to each one it processes. Needs no
 * dependencies beyond PHP itself with the sodium and openssl extensions,
 * both bundled by default since PHP 7.2.
 *
 * Run it from inside the unzipped export folder (the one this script
 * itself was extracted into) — every path below is relative to that
 * folder:
 *   php decrypt_export.php
 *
 * This is a PHP port of decrypt_export.py, right next to this file — same
 * logic, same file-format assumptions, in case Python isn't handy. See
 * that file's own comments for the reference explanation of the scheme;
 * README.txt has the same explanation in prose form.
 */
const ARGON2ID_TIME_COST = 2;
const ARGON2ID_MEMORY_COST_KIB = 19456;
const ARGON2ID_HASH_LEN = 32;

function derive_key(string $password, string $saltB64): string
{
    $salt = base64_decode($saltB64, true);

    return sodium_crypto_pwhash(
        ARGON2ID_HASH_LEN,
        $password,
        $salt,
        ARGON2ID_TIME_COST,
        ARGON2ID_MEMORY_COST_KIB * 1024,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
    );
}

function aes_gcm_decrypt(string $key, string $blobB64): string
{
    $combined = base64_decode($blobB64, true);
    if ($combined === false || strlen($combined) <= 12 + 16) {
        throw new RuntimeException('Ciphertext too short or not valid base64.');
    }

    $iv = substr($combined, 0, 12);
    $tag = substr($combined, -16);
    $ciphertext = substr($combined, 12, -16);

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('AES-GCM decryption failed — wrong key or tampered ciphertext.');
    }

    return $plaintext;
}

/**
 * PHP's CLI has no built-in silent password prompt — this disables
 * terminal echo on Unix-likes for the duration of the read, and falls
 * back to a visible prompt (with a clear warning) anywhere that trick
 * doesn't work, e.g. Windows.
 */
function prompt_master_password(): string
{
    $isUnix = PHP_OS_FAMILY !== 'Windows' && stream_isatty(STDIN);

    if ($isUnix) {
        echo 'Master password: ';
        system('stty -echo');
        $password = rtrim(fgets(STDIN), "\r\n");
        system('stty echo');
        echo "\n";

        return $password;
    }

    echo 'Master password (WARNING: will be visible as you type): ';

    return rtrim(fgets(STDIN), "\r\n");
}

/** @return array<string> */
function walk_export_files(string $root): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'json') {
            $paths[] = $file->getPathname();
        }
    }

    return $paths;
}

/**
 * @param  array<string, mixed>  $record
 * @param  array<string, string>  $keyRing
 * @return array<string, mixed>
 */
function decrypt_record(array $record, array $keyRing): array
{
    $recordKey = base64_decode($keyRing[$record['key_ring_id']], true);

    $decrypted = $record;
    foreach ($record as $field => $value) {
        if (! str_ends_with($field, '_ciphertext') || $value === null) {
            continue;
        }

        $plaintextField = substr($field, 0, -strlen('_ciphertext'));
        $decrypted[$plaintextField] = aes_gcm_decrypt($recordKey, $value);
    }

    return $decrypted;
}

function main(): void
{
    $masterPassword = prompt_master_password();

    $keyParamsPath = 'account/key-parameters.json';
    if (! is_file($keyParamsPath)) {
        fwrite(STDERR, "Could not find {$keyParamsPath} — run this script from inside the unzipped export folder.\n");
        exit(1);
    }

    $keyParams = json_decode(file_get_contents($keyParamsPath), true)['records'][0];

    try {
        $vaultKey = derive_key($masterPassword, $keyParams['passphrase_salt']);
        $keyRing = json_decode(aes_gcm_decrypt($vaultKey, $keyParams['key_ring_ciphertext']), true);
    } catch (Throwable) {
        fwrite(STDERR, "Could not unlock the key ring — check your master password.\n");
        exit(1);
    }

    $processed = 0;

    foreach (walk_export_files('.') as $path) {
        $data = json_decode(file_get_contents($path), true);

        if (($data['tier'] ?? null) !== 'e2ee') {
            continue;
        }

        // A record with no "key_ring_id" isn't decryptable this way (e.g.
        // account/key-parameters.json itself, which IS e2ee-tier data but
        // is the input to this whole process, not a record encrypted with
        // its own key-ring entry) — skip those, nothing more to do.
        $records = array_values(array_filter($data['records'] ?? [], fn ($r) => isset($r['key_ring_id'])));
        if ($records === []) {
            continue;
        }

        $data['records'] = array_map(fn ($record) => decrypt_record($record, $keyRing), $records);

        $outPath = substr($path, 0, -strlen('.json')).'.decrypted.json';
        file_put_contents($outPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        echo "Decrypted {$path} -> {$outPath}\n";
        $processed++;
    }

    if ($processed === 0) {
        echo "Nothing to decrypt — no e2ee-tier files found in this folder.\n";
    } else {
        echo "Done — {$processed} file(s) decrypted.\n";
    }
}

main();
