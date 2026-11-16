<?php

namespace App\Domain\Reviews;

use App\Domain\Media\MediaLibrary;
use App\Domain\Orders\OrderPreparation;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviews of verified purchases (§24): one per delivered order line, by its buyer. Moderation removes abuse,
 * personal data, spam or off-topic text; a review is never refused for being negative. Photos are re-encoded
 * by the media library (metadata such as location removed) and shown only once approved.
 */
final class Reviews
{
    public const REJECTIONS = ['abusive', 'personal_data', 'off_topic', 'spam', 'not_about_product'];

    public const MIN_PHOTOS = 1;

    public const MAX_PHOTOS = 3;

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('reviews.'.$code)]);
    }

    public function write(User $user, array $data): array
    {
        $line = DB::table('order_lines')->where('id', $data['order_line_id'])->first();
        abort_unless($line, 404);
        $order = Records::row('orders', $line->order_id);
        abort_unless((int) $order->user_id === $user->id, 404);
        if (! $order->delivered_at || ! isset(app(OrderPreparation::class)->issued($order->id)[$line->id])) {
            self::fail('order_line_id', 'NOT_DELIVERED');
        }
        $existing = DB::table('product_reviews')->where('order_line_id', $line->id)->lockForUpdate()->first();
        $values = [
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'locale' => app()->getLocale() === 'en' ? 'en' : 'fr',
            'updated_at' => now(),
        ];
        if ($existing) {
            // A buyer may correct a review until it is moderated; after that it stays as published or refused.
            if ($existing->status !== 'pending') {
                self::fail('order_line_id', 'ALREADY_REVIEWED');
            }
            DB::table('product_reviews')
                ->where('id', $existing->id)
                ->update([...$values, 'version' => $existing->version + 1]);
            $id = $existing->id;
        } else {
            $id = Records::id();
            DB::table('product_reviews')->insert([
                ...$values,
                'id' => $id,
                'order_line_id' => $line->id,
                'order_id' => $order->id,
                'product_id' => DB::table('offers AS o')
                    ->join('variants AS v', 'v.id', '=', 'o.variant_id')
                    ->where('o.id', $line->offer_id)
                    ->value('v.product_id'),
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
        }

        return $this->ownDto(Records::row('product_reviews', $id, false));
    }

    public function photo(User $user, string $id, UploadedFile $file): array
    {
        $review = Records::row('product_reviews', $id, false);
        abort_unless((int) $review->user_id === $user->id, 404);
        if ($review->status !== 'pending') {
            self::fail('review', 'REVIEW_STATE');
        }
        // A review carries 1 to 3 photos (the minimum is checked when it is published).
        if (DB::table('review_photos')->where('review_id', $id)->count() >= self::MAX_PHOTOS) {
            self::fail('file', 'TOO_MANY_PHOTOS');
        }
        $media = app(MediaLibrary::class)->upload(
            $file,
            ['fr' => 'Photo d’un client', 'en' => 'Customer photo'],
            $user,
        );
        DB::table('review_photos')->insert([
            'id' => Records::id(),
            'review_id' => $id,
            'media_id' => $media['id'],
            'created_at' => now(),
        ]);

        return $this->ownDto(Records::row('product_reviews', $id, false));
    }

    /** Publish or refuse with a listed reason; approved photos become public, the others stay private. */
    public function moderate(string $id, array $data, User $actor): array
    {
        $review = Records::row('product_reviews', $id);
        abort_unless((int) $review->version === $data['version'], 409);
        if ($review->status !== 'pending') {
            self::fail('review', 'REVIEW_STATE');
        }
        $publish = $data['decision'] === 'publish';
        if ($publish) {
            $approvable = DB::table('review_photos')->where('review_id', $id)
                ->whereIn('id', $data['approved_photos'] ?? [])->count();
            if ($approvable < self::MIN_PHOTOS) {
                self::fail('approved_photos', 'PHOTO_REQUIRED');
            }
        }
        DB::table('product_reviews')
            ->where('id', $id)
            ->update([
                'status' => $publish ? 'published' : 'rejected',
                'rejection_reason' => $publish ? null : $data['reason'],
                'moderated_by' => $actor->id,
                'moderated_at' => now(),
                'version' => $review->version + 1,
                'updated_at' => now(),
            ]);
        $approved = $publish ? $data['approved_photos'] ?? [] : [];
        foreach (DB::table('review_photos')->where('review_id', $id)->get() as $photo) {
            $ok = in_array($photo->id, $approved, true);
            DB::table('review_photos')
                ->where('id', $photo->id)
                ->update(['status' => $ok ? 'approved' : 'rejected']);
            if ($ok) {
                $media = DB::table('media_assets')->where('id', $photo->media_id)->first();
                app(MediaLibrary::class)->setPublic($media->id, (int) $media->version, true, $actor, true);
            }
        }
        Records::audit($actor, 'review.'.($publish ? 'published' : 'rejected'), $id, [
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->staffDto(Records::row('product_reviews', $id, false));
    }

    public function reply(string $id, array $data, User $actor): array
    {
        $review = Records::row('product_reviews', $id);
        abort_unless((int) $review->version === $data['version'], 409);
        if ($review->status !== 'published') {
            self::fail('review', 'REVIEW_STATE');
        }
        DB::table('product_reviews')
            ->where('id', $id)
            ->update([
                'reply' => $data['body'],
                'replied_by' => $actor->id,
                'replied_at' => now(),
                'version' => $review->version + 1,
                'updated_at' => now(),
            ]);

        return $this->staffDto(Records::row('product_reviews', $id, false));
    }

    public function report(User $user, string $id, array $data): array
    {
        $review = Records::row('product_reviews', $id, false);
        if ($review->status !== 'published' || (int) $review->user_id === $user->id) {
            self::fail('review', 'REVIEW_STATE');
        }
        DB::table('review_reports')->insertOrIgnore([
            'id' => Records::id(),
            'review_id' => $id,
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
            'created_at' => now(),
        ]);

        return ['reported' => true];
    }

    /** A report is dismissed, or leads to withdrawing the review for one of the listed reasons. */
    public function handleReport(string $reportId, array $data, User $actor): array
    {
        $report = Records::row('review_reports', $reportId);
        if ($report->status !== 'open') {
            self::fail('report', 'REVIEW_STATE');
        }
        if ($data['action'] === 'withdraw') {
            $review = Records::row('product_reviews', $report->review_id);
            DB::table('product_reviews')
                ->where('id', $review->id)
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $data['reason'],
                    'moderated_by' => $actor->id,
                    'moderated_at' => now(),
                    'version' => $review->version + 1,
                    'updated_at' => now(),
                ]);
        }
        DB::table('review_reports')
            ->where('id', $reportId)
            ->update([
                'status' => $data['action'] === 'withdraw' ? 'actioned' : 'dismissed',
                'handled_by' => $actor->id,
                'handled_at' => now(),
            ]);

        return $this->staffDto(Records::row('product_reviews', $report->review_id, false));
    }

    /** Public: published reviews, verified-purchase mark, first name and initial only, approved photos. */
    public function forProduct(string $productId): array
    {
        $rows = DB::table('product_reviews AS r')
            ->join('users AS u', 'u.id', '=', 'r.user_id')
            ->where('r.product_id', $productId)
            ->where('r.status', 'published')
            ->orderByDesc('r.created_at')
            ->get(['r.*', 'u.name', 'u.avatar_path']);

        return [
            'summary' => [
                'count' => $rows->count(),
                'average' => $rows->isEmpty() ? null : round($rows->avg('rating'), 1),
                'ratings' => collect(range(1, 5))->mapWithKeys(fn ($n) => [$n => $rows->where('rating', $n)->count()]),
            ],
            'reviews' => $rows
                ->map(function ($r) {
                    $parts = preg_split('/\s+/', trim((string) $r->name));

                    return [
                        'id' => $r->id,
                        'author' => $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : ''),
                        'avatar' => $r->avatar_path ?: null,
                        'verified_purchase' => true,
                        'rating' => (int) $r->rating,
                        'title' => $r->title,
                        'body' => $r->body,
                        'locale' => $r->locale,
                        'photos' => DB::table('review_photos')
                            ->where('review_id', $r->id)
                            ->where('status', 'approved')
                            ->pluck('media_id')
                            ->map(fn ($m) => '/api/v1/media/'.$m.'/public')
                            ->values(),
                        'reply' => $r->reply,
                        'created_at' => Records::iso($r->created_at),
                    ];
                })
                ->values(),
        ];
    }

    public function ownDto(object $r): array
    {
        return [
            'id' => $r->id,
            'order_line_id' => $r->order_line_id,
            'product_id' => $r->product_id,
            'rating' => (int) $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'status' => $r->status,
            'rejection_reason' => $r->rejection_reason,
            'photos' => DB::table('review_photos')->where('review_id', $r->id)->count(),
            'reply' => $r->reply,
            'version' => (int) $r->version,
        ];
    }

    public function staffDto(object $r): array
    {
        return [
            ...$this->ownDto($r),
            'user_id' => (int) $r->user_id,
            'photo_ids' => DB::table('review_photos')
                ->where('review_id', $r->id)
                ->get(['id', 'media_id', 'status'])
                ->map(fn ($p) => (array) $p)
                ->values(),
            'reports' => DB::table('review_reports')
                ->where('review_id', $r->id)
                ->get(['id', 'reason', 'note', 'status'])
                ->map(fn ($p) => (array) $p)
                ->values(),
            'moderated_at' => Records::iso($r->moderated_at),
        ];
    }
}
