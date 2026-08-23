<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('name');
        });

        $usedUsernames = [];

        DB::table('users')
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$usedUsernames): void {
                foreach ($users as $user) {
                    $base = $this->baseUsername($user);
                    $username = $base;
                    $attempt = 1;

                    while (isset($usedUsernames[$username])) {
                        $suffix = '-'.$user->id.($attempt > 1 ? '-'.$attempt : '');
                        $prefix = rtrim(substr($base, 0, 50 - strlen($suffix)), '.-_');
                        $username = ($prefix !== '' ? $prefix : 'user').$suffix;
                        $attempt++;
                    }

                    DB::table('users')->where('id', $user->id)->update(['username' => $username]);
                    $usedUsernames[$username] = true;
                }
            }, 'id');

        if (DB::table('users')->whereNull('username')->exists()) {
            throw new RuntimeException('Username backfill left users without a username.');
        }

        if (DB::table('users')
            ->selectRaw('LOWER(username) AS normalized_username')
            ->groupByRaw('LOWER(username)')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Username backfill produced a case-insensitive collision.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot make users.email required while users without email exist.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    private function baseUsername(object $user): string
    {
        $emailLocalPart = Str::before((string) $user->email, '@');
        $source = $emailLocalPart !== '' ? $emailLocalPart : (string) $user->name;
        $username = Str::lower(Str::ascii($source));
        $username = preg_replace('/[^a-z0-9._-]+/', '-', $username) ?? '';
        $username = trim($username, '.-_');
        $username = trim(substr($username, 0, 50), '.-_');

        return strlen($username) >= 3 ? $username : 'user-'.$user->id;
    }
};
