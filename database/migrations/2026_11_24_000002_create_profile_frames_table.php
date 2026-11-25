<?php

use Database\Seeders\ProfileFrameSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_frames', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 24)->unique();
            $table->string('name');
            $table->boolean('animated')->default(false);
            $table->string('asset_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
        });

        (new ProfileFrameSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_frames');
    }
};
