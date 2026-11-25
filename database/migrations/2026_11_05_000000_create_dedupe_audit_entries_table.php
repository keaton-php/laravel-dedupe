<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Keaton\Dedupe\Stores\DatabaseAuditStore;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return DatabaseAuditStore::connectionFrom(config('dedupe.audit.connection'));
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('run_id', 191)->index();
            $blueprint->string('dropped_id', 191)->index();
            $blueprint->char('dropped_hash', 64);
            $blueprint->string('survivor_id', 191)->index();
            $blueprint->string('matched_id', 191);
            $blueprint->string('matched_key', 128);
            $blueprint->text('matched_value')->nullable();
            $blueprint->dateTime('dropped_record_at')->nullable();
            $blueprint->unsignedBigInteger('seconds_apart')->nullable();
            $blueprint->string('survivorship', 8);
            $blueprint->unsignedBigInteger('window_seconds')->nullable();
            $blueprint->dateTime('deduped_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        return DatabaseAuditStore::tableFrom(config('dedupe.audit.table', 'dedupe_audit_entries'));
    }
};
