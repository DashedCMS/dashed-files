<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tot nu toe mocht iedereen met paneeltoegang alles in de mediabibliotheek.
 * De vijf nieuwe rechten gaan daarom naar elke bestaande rol, zodat niemand
 * na de update iets kwijt is. `purge_media` blijft wat het was: dat recht
 * krijgt niemand vanzelf.
 *
 * Bewust voor de migratie die de kolommen toevoegt (de naam sorteert
 * ervoor): de rechten worden pas afgedwongen als die kolommen bestaan, en
 * dan zijn ze hier al uitgedeeld.
 */
return new class () extends Migration {
    private const PERMISSIONS = ['view_media', 'upload_media', 'edit_media', 'delete_media', 'manage_media_folders'];

    private const FALLBACK_ROLE = 'Mediabibliotheek';

    public function up(): void
    {
        if (! Schema::hasTable('dashed__roles') || ! Schema::hasColumn('dashed__roles', 'extra_permissions')) {
            return;
        }

        foreach (DB::table('dashed__roles')->orderBy('id')->get(['id', 'extra_permissions']) as $role) {
            $current = json_decode((string) ($role->extra_permissions ?? ''), true);
            $current = is_array($current) ? array_values($current) : [];
            $merged = array_values(array_unique(array_merge($current, self::PERMISSIONS)));

            if ($merged !== $current) {
                DB::table('dashed__roles')->where('id', $role->id)->update(['extra_permissions' => json_encode($merged)]);
            }
        }

        $this->coverAdminsWithoutRole();
    }

    /**
     * Een account met de oude rol `admin` zonder gekoppelde rollen komt het
     * paneel in en kon dus de mediabibliotheek gebruiken, maar heeft geen rol
     * om de rechten aan te hangen. Die accounts krijgen een eigen rol met
     * precies de vijf rechten.
     */
    private function coverAdminsWithoutRole(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role') || ! Schema::hasTable('dashed__model_has_roles')) {
            return;
        }

        $userIds = DB::table('users')
            ->where('role', 'admin')
            ->whereNotIn('id', fn ($query) => $query->select('user_id')->from('dashed__model_has_roles'))
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return;
        }

        $roleId = DB::table('dashed__roles')->where('name', self::FALLBACK_ROLE)->value('id');

        if ($roleId === null) {
            $roleId = DB::table('dashed__roles')->insertGetId([
                'name' => self::FALLBACK_ROLE,
                'extra_permissions' => json_encode(self::PERMISSIONS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($userIds as $userId) {
            DB::table('dashed__model_has_roles')->insertOrIgnore(['role_id' => $roleId, 'user_id' => $userId]);
        }
    }

    public function down(): void
    {
        // Bewust leeg: de rechten weghalen zou beheerders buitensluiten.
    }
};
