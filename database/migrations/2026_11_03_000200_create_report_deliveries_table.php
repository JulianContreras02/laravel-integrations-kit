<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Control table: one row per report and day. "Pending" means "no
     * completed row", never "after the date of the last run", so a failed or
     * missed run cannot silently skip a day. It also answers audits: what was
     * delivered, when, with which checksum and how many attempts it took.
     */
    public function up(): void
    {
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            // The unique business key is what makes the pipeline idempotent.
            $table->string('report_key', 64)->unique();
            $table->date('report_date');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);

            // Each step stamps its column when it succeeds; a retried job
            // skips every step that already has one.
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('sftp_delivered_at')->nullable();
            $table->string('partner_delivery_id')->nullable();
            $table->timestamp('partner_notified_at')->nullable();
            $table->boolean('emailed_as_attachment')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
    }
};
