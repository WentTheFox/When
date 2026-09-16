<?php

namespace App\Support;

/**
 * Whitelist of recognized "purposes" for a connection attribute definition
 * that has E2EE disabled on its value (§0.2 tier) — lets an owner mark a
 * field as, e.g., "this holds a Discord username" so a value saved into it
 * gets format-validated accordingly, and so a future visitor-facing flow
 * (OAuth account linking) has a reliable way to find the right field.
 *
 * Never plumbed to the frontend via Inertia, unlike ColorPalette —
 * AttributesPanel.vue's purpose <option> list is a hand-kept mirror of
 * KEYS, the same relationship `type` already has with
 * ConnectionAttributeDefinitionController::store()'s 'in:' rule.
 */
class CustomFieldPurpose
{
    public const DISCORD = 'discord';

    public const VRCHAT = 'vrchat';

    public const KEYS = [self::DISCORD, self::VRCHAT];

    /**
     * Extra format-validation rules applied to a §0.2-tier value at save
     * time, on top of the base ['required', 'string', 'max:255'] every
     * such value gets — see ConnectionController::serversideAttributeRow().
     *
     * @return array<int, string>
     */
    public static function valueRules(?string $purpose): array
    {
        return match ($purpose) {
            // Discord's current username format: lowercase letters, digits,
            // underscores, and periods, 2-32 characters.
            self::DISCORD => ['regex:/^[a-z0-9_.]{2,32}$/'],
            // VRChat display names allow a much wider character set
            // (including emoji/unicode) — only bound the length.
            self::VRCHAT => ['max:64'],
            default => [],
        };
    }
}
