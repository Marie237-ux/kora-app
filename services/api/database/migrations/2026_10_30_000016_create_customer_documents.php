<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('status', 20);
            // Allocated at issue time only; drafts have no number.
            $t->string('number', 40)->nullable()->unique();
            // Self-reference added once the primary key exists (PostgreSQL requires it).
            $t->uuid('invoice_id')->nullable();
            $t->foreignUuid('refund_id')->nullable()->unique()->constrained('customer_refunds')->restrictOnDelete();
            $t->bigInteger('total');
            $t->boolean('demo');
            // Everything needed to reproduce the issued document, frozen at issue time, with its fingerprint.
            $t->json('snapshot')->nullable();
            $t->string('snapshot_sha256', 64)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['order_id', 'kind', 'status']);
        });
        Schema::table(
            'customer_documents',
            fn (Blueprint $t) => $t
                ->foreign('invoice_id')
                ->references('id')
                ->on('customer_documents')
                ->restrictOnDelete(),
        );
        // Brand images used by issued documents, kept by fingerprint so a new logo never alters old documents.
        Schema::create('document_assets', function (Blueprint $t) {
            $t->string('sha256', 64)->primary();
            $t->string('mime', 40);
            $t->string('disk', 40);
            $t->string('object_key');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_assets');
        Schema::dropIfExists('customer_documents');
    }
};
