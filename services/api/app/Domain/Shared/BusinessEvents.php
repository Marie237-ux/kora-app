<?php

namespace App\Domain\Shared;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessEvents
{
    public function audit(User $actor, string $action, string $id, array $details = []): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'resource_id' => $id,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'request_id' => request()->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }

    public function catalogueRevision(
        User $actor,
        string $entity,
        string $id,
        int $version,
        string $action,
        array $snapshot,
    ): void {
        DB::table('catalogue_revisions')->insert([
            'entity' => $entity,
            'resource_id' => $id,
            'version' => $version,
            'action' => $action,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
        $this->audit($actor, $entity.'.'.$action, $id, ['version' => $version]);
    }

    public function outbox(string $type, string $key, array $payload): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'deduplication_key' => $key,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
