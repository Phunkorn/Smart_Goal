<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsernameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_users_are_backfilled_deterministically_without_losing_email_or_soft_deleted_rows(): void
    {
        $first = User::factory()->create([
            'name' => 'First User',
            'email' => 'Shared.Name@example.test',
            'is_active' => false,
        ]);
        $deleted = User::factory()->create([
            'name' => 'Deleted User',
            'email' => 'shared.name@another.test',
        ]);
        $fallback = User::factory()->create([
            'name' => 'Fallback User',
            'email' => 'x@example.test',
        ]);
        $deleted->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        $migration = require database_path('migrations/2026_08_24_000001_add_username_and_make_email_nullable_on_users_table.php');
        $migration->up();

        $rows = DB::table('users')->orderBy('id')->get()->keyBy('id');
        $this->assertSame('shared.name', $rows[$first->id]->username);
        $this->assertSame('shared.name-'.$deleted->id, $rows[$deleted->id]->username);
        $this->assertSame('user-'.$fallback->id, $rows[$fallback->id]->username);
        $this->assertSame('Shared.Name@example.test', $rows[$first->id]->email);
        $this->assertNotNull($rows[$deleted->id]->deleted_at);
        $this->assertFalse((bool) $rows[$first->id]->is_active);
        $this->assertCount($rows->count(), $rows->pluck('username')->map('strtolower')->unique());

        $emailColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'email');
        $usernameColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'username');
        $usernameIndex = collect(Schema::getIndexes('users'))
            ->first(fn (array $index): bool => $index['columns'] === ['username']);
        $this->assertTrue($emailColumn['nullable']);
        $this->assertFalse($usernameColumn['nullable']);
        $this->assertTrue($usernameIndex['unique']);
    }
}
