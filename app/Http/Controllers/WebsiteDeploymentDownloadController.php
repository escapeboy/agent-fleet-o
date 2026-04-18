<?php

namespace App\Http\Controllers;

use App\Domain\Website\Models\WebsiteDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteDeploymentDownloadController extends Controller
{
    public function __invoke(Request $request, string $deployment): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 401);

        /** @var WebsiteDeployment|null $model */
        $model = WebsiteDeployment::query()->find($deployment);

        abort_if($model === null, 404);

        $relativePath = $model->config['storage_path'] ?? null;
        $disk = $model->config['storage_disk'] ?? 'local';

        abort_if($relativePath === null, 404, 'Deployment has no downloadable archive.');

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($relativePath), 404, 'Deployment archive is no longer available.');

        return $storage->download(
            $relativePath,
            'website-'.$model->website_id.'-'.$model->id.'.zip',
        );
    }
}
