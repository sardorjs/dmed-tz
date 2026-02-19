<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            // SHA-256 hash of the original file content (64 hex chars).
            // Used for deduplication: if two uploads share the same hash,
            // the second reuses the existing S3 path instead of uploading again.
            // Nullable because the hash is computed during the async processing job,
            // not at upload time. Indexed for fast lookup during dedup check.
            $table->string('hash', 64)->nullable()->index()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->dropColumn('hash');
        });
    }
};
