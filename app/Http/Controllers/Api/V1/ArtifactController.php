<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArtifactResource;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $artifacts = QueryBuilder::for(Artifact::class)
            ->allowedFilters([
                AllowedFilter::exact('experiment_id'),
                AllowedFilter::exact('crew_execution_id'),
                AllowedFilter::exact('project_run_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'name', 'type'])
            ->defaultSort('-created_at')
            ->withCount('versions')
            ->cursorPaginate($request->input('per_page', 15));

        return ArtifactResource::collection($artifacts);
    }

    public function show(Artifact $artifact): ArtifactResource
    {
        $artifact->load(['versions' => fn ($q) => $q->orderByDesc('version')]);

        return new ArtifactResource($artifact);
    }

    public function content(Artifact $artifact, Request $request): JsonResponse
    {
        $version = $request->query('version')
            ? $artifact->versions()->where('version', $request->query('version'))->firstOrFail()
            : $artifact->versions()->orderByDesc('version')->firstOrFail();

        $content = is_string($version->content)
            ? $version->content
            : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->json([
            'artifact_id' => $artifact->id,
            'version' => $version->version,
            'type' => $artifact->type,
            'category' => ArtifactContentResolver::category($artifact->type, $content),
            'content' => $content,
        ]);
    }

    public function download(Artifact $artifact, Request $request): StreamedResponse
    {
        $version = $request->query('version')
            ? $artifact->versions()->where('version', $request->query('version'))->firstOrFail()
            : $artifact->versions()->orderByDesc('version')->firstOrFail();

        $extension = ArtifactContentResolver::extension($artifact->type);
        $mime = ArtifactContentResolver::mimeType($artifact->type);
        $filename = Str::slug($artifact->name)."-v{$version->version}.{$extension}";

        $content = is_string($version->content)
            ? $version->content
            : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}
