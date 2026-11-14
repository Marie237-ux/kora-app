<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\BillingIdentity;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingBooks
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('accounting.'.$code)]);
    }

    public function row(string $table, string $id, object $book): object
    {
        $row = DB::table($table)->where('id', $id)->where('book_id', $book->id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function create(array $data, User $actor): array
    {
        $identity = app(BillingIdentity::class)->current();
        if ($data['mode'] === 'live' && $identity['missing'] !== []) {
            self::fail('mode', 'ENTITY_REQUIRED');
        }
        if ($data['mode'] === 'live' && DB::table('accounting_books')->where('mode', 'live')->exists()) {
            self::fail('mode', 'LIVE_BOOK_EXISTS');
        }
        $id = Records::id();
        DB::table('accounting_books')->insert([
            'id' => $id,
            'mode' => $data['mode'],
            'live_slot' => $data['mode'] === 'live' ? 1 : null,
            'label' => Records::json($data['label']),
            'entity_snapshot' => Records::json($identity),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'accounting.book_created', $id, ['mode' => $data['mode']]);

        return $this->dto(Records::row('accounting_books', $id, false));
    }

    public function dto(object $book): array
    {
        return [
            'id' => $book->id,
            'mode' => $book->mode,
            'label' => json_decode($book->label, true),
            'currency' => 'XAF',
            'capture' => app(AccountingCapture::class)->settings($book),
            'entity' => json_decode($book->entity_snapshot, true),
            'chart_version' => (int) $book->chart_version,
            'reviewed_chart_version' => $book->reviewed_chart_version === null ? null : (int) $book->reviewed_chart_version,
            'draft_count' => DB::table('accounting_entries')
                ->where('book_id', $book->id)
                ->where('status', 'draft')
                ->count(),
            'posted_count' => DB::table('accounting_entries')
                ->where('book_id', $book->id)
                ->where('status', 'posted')
                ->count(),
            'known_unposted_sources' => DB::table('accounting_sources')
                ->where('book_id', $book->id)
                ->whereNull('posted_entry_id')
                ->count(),
            'provisional' => true,
            'regulatory_certification' => false,
        ];
    }

    /** Complete explicit chart import; historical account identities and posted snapshots are retained. */
    public function importChart(object $book, array $data, User $actor): array
    {
        abort_unless((int) $book->chart_version === $data['version'], 409);
        $codes = array_column($data['accounts'], 'code');
        foreach ($data['accounts'] as $a) {
            if (
                $a['parent_code'] !== null &&
                (! in_array($a['parent_code'], $codes, true) ||
                    strlen($a['parent_code']) >= strlen($a['code']) ||
                    ! str_starts_with($a['code'], $a['parent_code']))
            ) {
                self::fail('accounts', 'CHART_PARENT_INVALID');
            }
        }
        $version = (int) $book->chart_version + 1;
        $accounts = collect($data['accounts'])
            ->sortBy('code', SORT_STRING)
            ->values()
            ->all();
        foreach ($accounts as $a) {
            $old = DB::table('accounting_accounts')->where('book_id', $book->id)->where('code', $a['code'])->first();
            DB::table('accounting_accounts')->updateOrInsert(
                ['id' => $old?->id ?? Records::id()],
                [
                    'book_id' => $book->id,
                    'code' => $a['code'],
                    'parent_code' => $a['parent_code'],
                    'label' => Records::json($a['label']),
                    'kind' => $a['kind'],
                    'auxiliary_type' => $a['auxiliary_type'],
                    'postable' => $a['postable'],
                    'active' => $a['active'],
                    'chart_version' => $version,
                    'created_at' => $old?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        }
        DB::table('accounting_accounts')
            ->where('book_id', $book->id)
            ->whereNotIn('code', $codes)
            ->update(['active' => false, 'chart_version' => $version, 'updated_at' => now()]);
        DB::table('accounting_chart_revisions')->insert([
            'id' => Records::id(),
            'book_id' => $book->id,
            'version' => $version,
            'snapshot' => Records::json($accounts),
            'sha256' => app(CommandBus::class)->fingerprint($accounts),
            'reference' => $data['reference'],
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('accounting_books')
            ->where('id', $book->id)
            ->update(['chart_version' => $version, 'reviewed_chart_version' => null, 'updated_at' => now()]);
        Records::audit($actor, 'accounting.chart_imported', $book->id, [
            'version' => $version,
            'reference' => $data['reference'],
        ]);

        return $this->chart(Records::row('accounting_books', $book->id, false));
    }

    public function chart(object $book): array
    {
        $revision = DB::table('accounting_chart_revisions')
            ->where('book_id', $book->id)
            ->where('version', $book->chart_version)
            ->first();

        return [
            'version' => (int) $book->chart_version,
            'sha256' => $revision?->sha256,
            'reviewed' => $book->reviewed_chart_version !== null &&
                (int) $book->reviewed_chart_version === (int) $book->chart_version,
            'accounts' => DB::table('accounting_accounts')
                ->where('book_id', $book->id)
                ->orderBy('code')
                ->get()
                ->map(fn ($a) => $this->account($a)),
        ];
    }

    public function account(object $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'parent_code' => $a->parent_code,
            'label' => json_decode($a->label, true),
            'kind' => $a->kind,
            'auxiliary_type' => $a->auxiliary_type,
            'postable' => (bool) $a->postable,
            'active' => (bool) $a->active,
        ];
    }

    public function reviewChart(object $book, array $data, User $actor): array
    {
        abort_unless((int) $book->chart_version === $data['version'], 409);
        $revision = DB::table('accounting_chart_revisions')
            ->where('book_id', $book->id)
            ->where('version', $data['version'])
            ->first();
        abort_unless($revision && hash_equals($revision->sha256, $data['sha256']), 409);
        if ($revision->reviewed_at) {
            self::fail('version', 'ALREADY_REVIEWED');
        }
        DB::table('accounting_chart_revisions')
            ->where('id', $revision->id)
            ->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['note']]);
        DB::table('accounting_books')
            ->where('id', $book->id)
            ->update(['reviewed_chart_version' => $data['version'], 'updated_at' => now()]);
        Records::audit($actor, 'accounting.chart_reviewed', $book->id, [
            'version' => $data['version'],
            'note' => $data['note'],
        ]);

        return $this->chart(Records::row('accounting_books', $book->id, false));
    }

    public function journal(object $book, ?string $id, array $data, User $actor): array
    {
        $old = $id ? $this->row('accounting_journals', $id, $book) : null;
        if ($old) {
            abort_unless((int) $old->version === $data['version'], 409);
        } elseif (
            DB::table('accounting_journals')->where('book_id', $book->id)->where('code', $data['code'])->exists()
        ) {
            self::fail('code', 'JOURNAL_EXISTS');
        }
        $id ??= Records::id();
        DB::table('accounting_journals')->updateOrInsert(
            ['id' => $id],
            [
                'book_id' => $book->id,
                'code' => $old?->code ?? $data['code'],
                'label' => Records::json($data['label']),
                'active' => $data['active'],
                'version' => $old ? $old->version + 1 : 1,
                'created_at' => $old?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        Records::audit($actor, 'accounting.journal_saved', $id);

        return $this->journalDto($this->row('accounting_journals', $id, $book));
    }

    public function journalDto(object $j): array
    {
        return [
            'id' => $j->id,
            'code' => $j->code,
            'label' => json_decode($j->label, true),
            'active' => (bool) $j->active,
            'version' => (int) $j->version,
        ];
    }
}
