<?php

namespace App\Http\Middleware;

use App\Domain\Professional\BusinessProfiles;
use App\Domain\Professional\ProfessionalQuotes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Recheck current membership before an idempotency receipt can replay private company data. */
final class RequireCompanyMembership
{
    public function handle(Request $request, Closure $next)
    {
        if ($id = $request->route('id')) {
            $owner = in_array(
                $request->route()->getActionMethod(),
                ['updateProfile', 'saveMember', 'removeMember'],
                true,
            );
            $profile = app(BusinessProfiles::class)->authorized(
                $id,
                $request->user(),
                ! $request->isMethod('GET'),
                $owner,
            );
            if ($quoteId = $request->route('quoteId')) {
                app(ProfessionalQuotes::class)->owned($profile, $quoteId);
            }
            if ($addressId = $request->route('addressId')) {
                abort_unless(
                    DB::table('professional_addresses')->where('profile_id', $id)->where('id', $addressId)->exists(),
                    404,
                );
            }
        }

        return $next($request);
    }
}
