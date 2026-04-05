<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteDeleteTool extends Tool
{
    protected string $name = 'website_delete';

    protected string $description = 'Permanently delete a website and all its pages. This action cannot be undone.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));
        if (! $website) {
            return Response::error('Website not found.');
        }

        try {
            app(DeleteWebsiteAction::class)->execute($website);

            return Response::text(json_encode(['success' => true, 'message' => 'Website deleted.']));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
