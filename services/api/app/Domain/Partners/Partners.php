<?php

namespace App\Domain\Partners;

use App\Domain\Catalogue\CatalogueManager;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Partner applications and the public partner page (§28). Anyone may apply with consent to the use of their
 * data; the team follows the application, decides, and converts it into a supplier and shop through the usual
 * catalogue tools, never twice. The public page lists only published partners and never invents any.
 */
final class Partners
{
    public const CONSENT_VERSION = 'partner-application-2026-11';

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('partners.'.$code)]);
    }

    private function phoneKey(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone), -9);
    }

    /**
     * Public application. A filled trap field (left empty by people) is answered like any other submission but
     * nothing is stored. An earlier open application with the same phone or e-mail is linked for the team only.
     */
    public function apply(array $data): array
    {
        if (! blank($data['company_site'] ?? null)) {
            return ['received' => true, 'reference' => 'CAND-'.strtoupper(Str::random(7))];
        }
        $key = $this->phoneKey($data['phone']);
        $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $duplicate = DB::table('partner_applications')
            ->where('status', '!=', 'rejected')
            ->where(fn ($q) => $q->where('phone_key', $key)->when($email, fn ($q) => $q->orWhere('email', $email)))
            ->orderBy('created_at')
            ->value('id');
        $id = Records::id();
        $number = app(DocumentNumbers::class)->next('partner_application', 'CAND-', 7);
        DB::table('partner_applications')->insert([
            'id' => $id,
            'number' => $number,
            'contact_name' => trim($data['contact_name']),
            'business_name' => trim($data['business_name']),
            'activity' => $data['activity'],
            'phone' => trim($data['phone']),
            'phone_key' => $key,
            'email' => $email,
            'city' => trim($data['city']),
            'district' => $data['district'] ?? null,
            'product_types' => Records::json(array_values($data['product_types'])),
            'message' => $data['message'] ?? null,
            'consent_version' => self::CONSENT_VERSION,
            'consent_at' => now(),
            'locale' => app()->getLocale() === 'en' ? 'en' : 'fr',
            'duplicate_of' => $duplicate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit(null, 'partner.application_received', $id, ['duplicate_of' => $duplicate]);

        return ['received' => true, 'reference' => $number];
    }

    public function note(string $id, array $data, User $actor): array
    {
        $application = Records::row('partner_applications', $id);
        DB::table('partner_application_notes')->insert([
            'id' => Records::id(),
            'application_id' => $application->id,
            'author_id' => $actor->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        if ($application->status === 'submitted') {
            $this->update($application, ['status' => 'in_review']);
        }

        return $this->applicationDto(Records::row('partner_applications', $id, false));
    }

    public function attach(string $id, UploadedFile $file, User $actor): array
    {
        Records::row('partner_applications', $id, false);
        $document = app(PrivateDocuments::class)->upload($file, 'partner_evidence', null, $actor);

        return DB::transaction(function () use ($id, $document, $actor) {
            Records::row('partner_applications', $id);
            DB::table('partner_application_notes')->insert([
                'id' => Records::id(),
                'application_id' => $id,
                'author_id' => $actor->id,
                'document_id' => $document['id'],
                'created_at' => now(),
            ]);

            return $this->applicationDto(Records::row('partner_applications', $id, false));
        }, 3);
    }

    public function decide(string $id, array $data, User $actor): array
    {
        $application = Records::row('partner_applications', $id);
        abort_unless((int) $application->version === $data['version'], 409);
        if (! in_array($application->status, ['submitted', 'in_review'], true)) {
            self::fail('application', 'APPLICATION_STATE');
        }
        $this->update($application, [
            'status' => $data['decision'] === 'accept' ? 'accepted' : 'rejected',
            'decision_note' => $data['note'],
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);
        Records::audit($actor, 'partner.application_'.$data['decision'], $id, []);

        return $this->applicationDto(Records::row('partner_applications', $id, false));
    }

    /**
     * Accepted application → supplier (an existing one, or created from the application) and optionally a draft
     * shop, through the catalogue's own save so history and checks are the usual ones. Refused when the supplier
     * is already another conversion's, or when a supplier with the same phone or e-mail already exists.
     */
    public function convert(string $id, array $data, User $actor): array
    {
        $application = Records::row('partner_applications', $id);
        abort_unless((int) $application->version === $data['version'], 409);
        if ($application->status !== 'accepted') {
            self::fail('application', 'APPLICATION_STATE');
        }
        $catalogue = app(CatalogueManager::class);
        if (isset($data['supplier_id'])) {
            abort_unless(DB::table('suppliers')->where('id', $data['supplier_id'])->exists(), 404);
            $supplier = $data['supplier_id'];
        } else {
            $existing = DB::table('suppliers')
                ->get(['id', 'private_details'])
                ->first(function ($s) use ($application) {
                    $details = json_decode($s->private_details ?? '{}', true);

                    return (($details['phone'] ?? null) &&
                        $this->phoneKey($details['phone']) === $application->phone_key) ||
                        ($application->email &&
                            mb_strtolower((string) ($details['email'] ?? '')) === $application->email);
                });
            if ($existing) {
                self::fail('supplier_id', 'SUPPLIER_EXISTS');
            }
            $supplier = $catalogue->save(
                'suppliers',
                null,
                null,
                [
                    'name' => $application->business_name,
                    'private_details' => [
                        'contact_name' => $application->contact_name,
                        'email' => $application->email,
                        'phone' => $application->phone,
                        'address' => trim($application->city.' '.($application->district ?? '')),
                        'legal_name' => null,
                    ],
                ],
                $actor,
            )['id'];
        }
        if (
            DB::table('partner_applications')->where('supplier_id', $supplier)->where('status', 'converted')->exists()
        ) {
            self::fail('supplier_id', 'SUPPLIER_ALREADY_CONVERTED');
        }
        $shop = isset($data['shop'])
            ? $catalogue->save('shops', null, null, [...$data['shop'], 'supplier_id' => $supplier], $actor)['id']
            : null;
        $this->update($application, ['status' => 'converted', 'supplier_id' => $supplier, 'shop_id' => $shop]);
        Records::audit($actor, 'partner.application_converted', $id, ['supplier_id' => $supplier, 'shop_id' => $shop]);

        return $this->applicationDto(Records::row('partner_applications', $id, false));
    }

    /** Partner profile for the public page; created as a draft, published only when complete. */
    public function save(?string $id, array $data, User $actor): array
    {
        $values = [
            'name' => trim($data['name']),
            'slug' => $data['slug'],
            'domain' => Records::json($data['domain']),
            'presentation' => Records::json($data['presentation']),
            'advantages' => Records::json($data['advantages']),
            'logo_media_id' => $data['logo_media_id'] ?? null,
            'website' => $data['website'] ?? null,
            'updated_at' => now(),
        ];
        if ($id) {
            $partner = Records::row('partners', $id);
            abort_unless((int) $partner->version === $data['version'], 409);
            DB::table('partners')
                ->where('id', $id)
                ->update([...$values, 'version' => $partner->version + 1]);
        } else {
            $application = isset($data['application_id'])
                ? Records::row('partner_applications', $data['application_id'])
                : null;
            if ($application && $application->status !== 'converted') {
                self::fail('application_id', 'APPLICATION_STATE');
            }
            $id = Records::id();
            DB::table('partners')->insert([
                ...$values,
                'id' => $id,
                'application_id' => $application?->id,
                'supplier_id' => $application?->supplier_id ?? ($data['supplier_id'] ?? null),
                'shop_id' => $application?->shop_id ?? ($data['shop_id'] ?? null),
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'partner.saved', $id, ['slug' => $data['slug']]);

        return $this->partnerDto(Records::row('partners', $id, false), false);
    }

    /** Publishing needs a presentation in both languages and, when a logo is set, a public logo. */
    public function status(string $id, array $data, User $actor): array
    {
        $partner = Records::row('partners', $id);
        abort_unless((int) $partner->version === $data['version'], 409);
        if ($data['status'] === 'published') {
            $presentation = json_decode($partner->presentation, true);
            if (blank($presentation['fr'] ?? null) || blank($presentation['en'] ?? null)) {
                self::fail('presentation', 'PRESENTATION_REQUIRED');
            }
            if (
                $partner->logo_media_id &&
                ! DB::table('media_assets')
                    ->where('id', $partner->logo_media_id)
                    ->where('is_public', true)
                    ->where('revoked', false)
                    ->exists()
            ) {
                self::fail('logo_media_id', 'LOGO_NOT_PUBLIC');
            }
        }
        DB::table('partners')
            ->where('id', $id)
            ->update([
                'status' => $data['status'],
                'published_at' => $data['status'] === 'published' ? $partner->published_at ?? now() : $partner->published_at,
                'version' => $partner->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'partner.'.$data['status'], $id, []);

        return $this->partnerDto(Records::row('partners', $id, false), false);
    }

    private function update(object $application, array $changes): void
    {
        DB::table('partner_applications')
            ->where('id', $application->id)
            ->update([...$changes, 'version' => $application->version + 1, 'updated_at' => now()]);
    }

    public function applicationDto(object $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'status' => $a->status,
            'version' => (int) $a->version,
            'contact_name' => $a->contact_name,
            'business_name' => $a->business_name,
            'activity' => $a->activity,
            'phone' => $a->phone,
            'email' => $a->email,
            'city' => $a->city,
            'district' => $a->district,
            'product_types' => json_decode($a->product_types, true),
            'message' => $a->message,
            'consent' => ['version' => $a->consent_version, 'at' => Records::iso($a->consent_at)],
            'locale' => $a->locale,
            'duplicate_of' => $a->duplicate_of,
            'decision_note' => $a->decision_note,
            'decided_at' => Records::iso($a->decided_at),
            'supplier_id' => $a->supplier_id,
            'shop_id' => $a->shop_id,
            'notes' => DB::table('partner_application_notes')
                ->where('application_id', $a->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($n) => [
                        'id' => $n->id,
                        'author_id' => (int) $n->author_id,
                        'body' => $n->body,
                        'document_id' => $n->document_id,
                        'created_at' => Records::iso($n->created_at),
                    ],
                )
                ->values(),
            'created_at' => Records::iso($a->created_at),
        ];
    }

    /** Public card: verifiable facts only; the shop link only when that shop is published. */
    public function partnerDto(object $p, bool $public): array
    {
        $locale = app()->getLocale();
        $text = fn (string $json) => $public
            ? json_decode($json, true)[$locale] ?? (json_decode($json, true)['fr'] ?? null)
            : json_decode($json, true);
        $shop = $p->shop_id
            ? DB::table('shops')
                ->where('id', $p->shop_id)
                ->first(['name', 'slug', 'status'])
            : null;
        $logo =
            $p->logo_media_id &&
            DB::table('media_assets')
                ->where('id', $p->logo_media_id)
                ->where('is_public', true)
                ->where('revoked', false)
                ->exists();

        return [
            ...$public
                ? []
                : [
                    'id' => $p->id,
                    'status' => $p->status,
                    'version' => (int) $p->version,
                    'supplier_id' => $p->supplier_id,
                    'application_id' => $p->application_id,
                ],
            'name' => $p->name,
            'slug' => $p->slug,
            'domain' => $text($p->domain),
            'presentation' => $text($p->presentation),
            'advantages' => $text($p->advantages),
            'logo_url' => $logo ? '/api/v1/media/'.$p->logo_media_id.'/public' : null,
            'website' => $p->website,
            'shop' => $shop && (! $public || $shop->status === 'published')
                    ? ['name' => $shop->name, 'slug' => $shop->slug]
                    : null,
            'published_at' => Records::iso($p->published_at),
        ];
    }
}
