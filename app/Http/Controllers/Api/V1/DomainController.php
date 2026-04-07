<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\Domain\CheckDomainAvailabilityAction;
use App\Domain\Website\Actions\Domain\ConfigureDnsAction;
use App\Domain\Website\Actions\Domain\PurchaseDomainAction;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Domain Management
 */
class DomainController extends Controller
{
    /**
     * Check whether a domain name is available for registration via Namecheap.
     */
    public function check(Request $request, Website $website): JsonResponse
    {
        $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $team = $request->user()->currentTeam;
        $result = (new CheckDomainAvailabilityAction)->execute($team, $request->string('domain')->toString());

        return response()->json($result);
    }

    /**
     * Purchase a domain via Namecheap and attach it to the website.
     */
    public function purchase(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'years' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'contact' => ['required', 'array'],
            'contact.first_name' => ['required', 'string'],
            'contact.last_name' => ['required', 'string'],
            'contact.address1' => ['required', 'string'],
            'contact.city' => ['required', 'string'],
            'contact.state_province' => ['required', 'string'],
            'contact.postal_code' => ['required', 'string'],
            'contact.country' => ['required', 'string'],
            'contact.phone' => ['required', 'string'],
            'contact.email_address' => ['required', 'email'],
        ]);

        $contact = array_merge($data['contact'], ['years' => $data['years'] ?? 1]);
        $team = $request->user()->currentTeam;
        $result = (new PurchaseDomainAction)->execute($team, $website, $data['domain'], $contact);

        $status = $result['success'] ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Configure DNS A records for a website's custom domain via Namecheap.
     */
    public function dns(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip'],
        ]);

        $team = $request->user()->currentTeam;
        $success = (new ConfigureDnsAction)->execute($team, $website, $data['ip_address']);

        return response()->json(['success' => $success]);
    }
}
