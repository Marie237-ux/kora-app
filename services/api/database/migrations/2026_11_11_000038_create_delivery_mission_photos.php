<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional photos taken by the courier during a mission (parcels at pickup, at handover, damage, incident).
        // They are evidence kept for later claims, never a reason to refuse a customer's later recourse.
        Schema::create('delivery_mission_photos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('mission_id')->constrained('delivery_missions')->restrictOnDelete();
            $t->foreignUuid('document_id')->unique()->constrained('private_documents')->restrictOnDelete();
            $t->string('kind', 12);
            $t->text('note')->nullable();
            $t->foreignId('taken_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['mission_id', 'created_at']);
        });
        $photo =
            "NEW.kind IN ('pickup','handover','damage','incident','other') AND EXISTS (SELECT 1 FROM private_documents d WHERE d.id=NEW.document_id AND d.purpose='delivery_evidence' AND d.supplier_id IS NULL)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION delivery_mission_photo_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END IF; IF NOT COALESCE(({$photo}),false) THEN RAISE EXCEPTION 'MISSION_PHOTO'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_mission_photo_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_mission_photos FOR EACH ROW EXECUTE FUNCTION delivery_mission_photo_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER delivery_mission_photo_insert BEFORE INSERT ON delivery_mission_photos WHEN NOT COALESCE(({$photo}),0) BEGIN SELECT RAISE(ABORT,'MISSION_PHOTO'); END;
            CREATE TRIGGER delivery_mission_photo_update BEFORE UPDATE ON delivery_mission_photos BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_mission_photo_delete BEFORE DELETE ON delivery_mission_photos BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_mission_photos');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS delivery_mission_photo_guard();');
        }
    }
};
