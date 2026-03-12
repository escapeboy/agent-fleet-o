<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Email\Actions\CreateEmailThemeAction;
use App\Domain\Email\Actions\DeleteEmailThemeAction;
use App\Domain\Email\Actions\UpdateEmailThemeAction;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmailThemeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Email Themes
 */
class EmailThemeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $themes = QueryBuilder::for(EmailTheme::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'name', 'status'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return EmailThemeResource::collection($themes);
    }

    public function show(EmailTheme $emailTheme): EmailThemeResource
    {
        return new EmailThemeResource($emailTheme);
    }

    public function store(Request $request, CreateEmailThemeAction $action): JsonResponse
    {
        $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'status'            => ['sometimes', 'in:draft,active,archived'],
            'logo_url'          => ['sometimes', 'nullable', 'url'],
            'logo_width'        => ['sometimes', 'integer', 'min:50', 'max:600'],
            'background_color'  => ['sometimes', 'string', 'max:20'],
            'canvas_color'      => ['sometimes', 'string', 'max:20'],
            'primary_color'     => ['sometimes', 'string', 'max:20'],
            'text_color'        => ['sometimes', 'string', 'max:20'],
            'heading_color'     => ['sometimes', 'string', 'max:20'],
            'muted_color'       => ['sometimes', 'string', 'max:20'],
            'divider_color'     => ['sometimes', 'string', 'max:20'],
            'font_name'         => ['sometimes', 'string', 'max:100'],
            'font_url'          => ['sometimes', 'nullable', 'url'],
            'font_family'       => ['sometimes', 'string', 'max:255'],
            'heading_font_size' => ['sometimes', 'integer', 'min:10', 'max:72'],
            'body_font_size'    => ['sometimes', 'integer', 'min:10', 'max:24'],
            'line_height'       => ['sometimes', 'numeric', 'min:1', 'max:3'],
            'email_width'       => ['sometimes', 'integer', 'min:400', 'max:900'],
            'content_padding'   => ['sometimes', 'integer', 'min:0', 'max:80'],
            'company_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_address'   => ['sometimes', 'nullable', 'string'],
            'footer_text'       => ['sometimes', 'nullable', 'string'],
        ]);

        $team = Team::findOrFail($request->user()->current_team_id);
        $theme = $action->execute($team, $request->except(['team_id']));

        return (new EmailThemeResource($theme))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, EmailTheme $emailTheme, UpdateEmailThemeAction $action): EmailThemeResource
    {
        $request->validate([
            'name'              => ['sometimes', 'string', 'max:255'],
            'status'            => ['sometimes', 'in:draft,active,archived'],
            'logo_url'          => ['sometimes', 'nullable', 'url'],
            'logo_width'        => ['sometimes', 'integer', 'min:50', 'max:600'],
            'background_color'  => ['sometimes', 'string', 'max:20'],
            'canvas_color'      => ['sometimes', 'string', 'max:20'],
            'primary_color'     => ['sometimes', 'string', 'max:20'],
            'text_color'        => ['sometimes', 'string', 'max:20'],
            'heading_color'     => ['sometimes', 'string', 'max:20'],
            'muted_color'       => ['sometimes', 'string', 'max:20'],
            'divider_color'     => ['sometimes', 'string', 'max:20'],
            'font_name'         => ['sometimes', 'string', 'max:100'],
            'font_url'          => ['sometimes', 'nullable', 'url'],
            'font_family'       => ['sometimes', 'string', 'max:255'],
            'heading_font_size' => ['sometimes', 'integer', 'min:10', 'max:72'],
            'body_font_size'    => ['sometimes', 'integer', 'min:10', 'max:24'],
            'line_height'       => ['sometimes', 'numeric', 'min:1', 'max:3'],
            'email_width'       => ['sometimes', 'integer', 'min:400', 'max:900'],
            'content_padding'   => ['sometimes', 'integer', 'min:0', 'max:80'],
            'company_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_address'   => ['sometimes', 'nullable', 'string'],
            'footer_text'       => ['sometimes', 'nullable', 'string'],
        ]);

        $theme = $action->execute($emailTheme, $request->except(['team_id']));

        return new EmailThemeResource($theme);
    }

    /**
     * @response 200 {"message": "Email theme deleted."}
     */
    public function destroy(EmailTheme $emailTheme, DeleteEmailThemeAction $action): JsonResponse
    {
        $action->execute($emailTheme);

        return response()->json(['message' => 'Email theme deleted.']);
    }
}
