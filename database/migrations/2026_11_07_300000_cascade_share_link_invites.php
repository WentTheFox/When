<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deleting a share link removes the invites it issued, except ones that
     * were redeemed: those keep their "invited by" trail (the FK still nulls
     * their source link) but are expired so the dead link's code stops
     * working. A BEFORE DELETE trigger rather than a model event, so it also
     * fires when the link goes via a connection's or account's DB cascade;
     * it runs before the FK's own SET NULL. Also sweeps the orphans that
     * nulling already left behind: footer invites are unlimited,
     * non-expiring and, once their link is gone, indistinguishable from a
     * manual invite of the same shape, so any unredeemed one with no source
     * is removed.
     */
    public function up(): void
    {
        DB::table('invites')
            ->whereNull('source_share_link_id')
            ->whereNull('max_uses')
            ->whereNull('expires_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('invite_redemptions')
                ->whereColumn('invite_redemptions.invite_id', 'invites.id'))
            ->delete();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cleanup_share_link_invites() RETURNS trigger AS $$
            BEGIN
                DELETE FROM invites
                WHERE source_share_link_id = OLD.id
                  AND NOT EXISTS (SELECT 1 FROM invite_redemptions WHERE invite_id = invites.id);

                UPDATE invites SET expires_at = now()
                WHERE source_share_link_id = OLD.id
                  AND (expires_at IS NULL OR expires_at > now());

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS share_links_cleanup_invites ON share_links;
            CREATE TRIGGER share_links_cleanup_invites
            BEFORE DELETE ON share_links
            FOR EACH ROW EXECUTE FUNCTION cleanup_share_link_invites();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS share_links_cleanup_invites ON share_links; DROP FUNCTION IF EXISTS cleanup_share_link_invites();');
    }
};
