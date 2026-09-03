#!/usr/bin/env python3
"""
Decrypts every "e2ee"-tier file in a When data export in place, writing a
"<file>.decrypted.json" copy next to each one it processes. Only needs your
master password and two packages:

    pip install argon2-cffi cryptography

Run it from inside the unzipped export folder (the one this script itself
was extracted into) — every path below is relative to that folder.

This file's own logic is the reference implementation of the export's
decryption scheme; the manual step-by-step in README.txt describes exactly
what this script does, for anyone who wants to reimplement it in another
language instead of running it.
"""
import base64
import getpass
import json
import os
import sys

from argon2.low_level import Type, hash_secret_raw
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

ARGON2ID_TIME_COST = 2
ARGON2ID_MEMORY_COST_KIB = 19456
ARGON2ID_PARALLELISM = 1
ARGON2ID_HASH_LEN = 32


def derive_key(password: str, salt_b64: str) -> bytes:
    salt = base64.b64decode(salt_b64)
    return hash_secret_raw(
        secret=password.encode("utf-8"),
        salt=salt,
        time_cost=ARGON2ID_TIME_COST,
        memory_cost=ARGON2ID_MEMORY_COST_KIB,
        parallelism=ARGON2ID_PARALLELISM,
        hash_len=ARGON2ID_HASH_LEN,
        type=Type.ID,
    )


def aes_gcm_decrypt(key: bytes, blob_b64: str) -> bytes:
    blob = base64.b64decode(blob_b64)
    iv, ciphertext = blob[:12], blob[12:]
    return AESGCM(key).decrypt(iv, ciphertext, None)


def load_key_ring(master_password: str) -> dict:
    with open(os.path.join("account", "key-parameters.json")) as f:
        key_params = json.load(f)["records"][0]

    vault_key = derive_key(master_password, key_params["passphrase_salt"])
    plaintext = aes_gcm_decrypt(vault_key, key_params["key_ring_ciphertext"])
    return json.loads(plaintext)


def decrypt_record(record: dict, key_ring: dict) -> dict:
    """
    Every field ending in "_ciphertext" is decrypted using the raw AES key
    named by the record's own "key_ring_id" (looked up in the key ring) —
    the decrypted value is stored under the same key name with that suffix
    stripped, e.g. "name_ciphertext" -> "name".
    """
    key_ring_id = record["key_ring_id"]
    record_key = base64.b64decode(key_ring[key_ring_id])

    decrypted = dict(record)
    for field, value in record.items():
        if not field.endswith("_ciphertext") or value is None:
            continue
        plaintext = aes_gcm_decrypt(record_key, value).decode("utf-8")
        decrypted[field[: -len("_ciphertext")]] = plaintext

    return decrypted


def walk_export_files():
    for root, _dirs, files in os.walk("."):
        for name in files:
            if name.endswith(".json"):
                yield os.path.relpath(os.path.join(root, name), ".")


def main() -> None:
    master_password = getpass.getpass("Master password: ")

    try:
        key_ring = load_key_ring(master_password)
    except Exception:
        print("Could not unlock the key ring — check your master password.", file=sys.stderr)
        sys.exit(1)

    processed = 0

    for path in walk_export_files():
        with open(path) as f:
            data = json.load(f)

        if data.get("tier") != "e2ee":
            continue

        # A record with no "key_ring_id" isn't decryptable this way (e.g.
        # account/key-parameters.json itself, which IS e2ee-tier data but
        # is the input to this whole process, not a record encrypted with
        # its own key-ring entry) — skip those, nothing more to do.
        records = [r for r in data.get("records", []) if "key_ring_id" in r]
        if not records:
            continue

        data["records"] = [decrypt_record(r, key_ring) for r in records]

        out_path = path[: -len(".json")] + ".decrypted.json"
        with open(out_path, "w") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)

        print(f"Decrypted {path} -> {out_path}")
        processed += 1

    if processed == 0:
        print("Nothing to decrypt — no e2ee-tier files found in this folder.")
    else:
        print(f"Done — {processed} file(s) decrypted.")


if __name__ == "__main__":
    main()
