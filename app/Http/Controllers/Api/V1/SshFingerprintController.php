<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Models\SshHostFingerprint;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SshFingerprintResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags SSH Fingerprints
 */
class SshFingerprintController extends Controller
{
    /**
     * List all trusted SSH host fingerprints.
     *
     * Returns every host:port combination that has been trusted via TOFU
     * (Trust On First Use) for the current team.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $fingerprints = SshHostFingerprint::orderBy('host')->orderBy('port')->get();

        return SshFingerprintResource::collection($fingerprints);
    }

    /**
     * Delete a trusted SSH host fingerprint.
     *
     * Removes the stored fingerprint for this host:port. The next SSH connection
     * to that host will re-run TOFU and store a fresh fingerprint.
     * Use this when a host's SSH key has been rotated legitimately.
     *
     * @response 200 {"message": "Fingerprint removed. Next connection will re-verify via TOFU."}
     */
    public function destroy(SshHostFingerprint $sshFingerprint): JsonResponse
    {
        $sshFingerprint->delete();

        return response()->json([
            'message' => 'Fingerprint removed. Next connection will re-verify via TOFU.',
        ]);
    }
}
