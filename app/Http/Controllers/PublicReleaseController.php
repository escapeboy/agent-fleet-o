<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Release\Models\Release;
use Illuminate\Http\Response;

class PublicReleaseController extends Controller
{
    public function show(string $shareToken): Response
    {
        $release = Release::withoutGlobalScopes()
            ->where('share_token', $shareToken)
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->first();

        if (! $release) {
            abort(404);
        }

        $artifacts = $release->artifacts()->orderByPivot('sort_order')->get();

        return response()->view('public.release', [
            'release' => $release,
            'artifacts' => $artifacts,
        ]);
    }
}
