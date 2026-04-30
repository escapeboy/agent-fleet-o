<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Actions\GenerateEmailTemplateAction;
use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmailTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Email Templates
 */
class EmailTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = QueryBuilder::for(EmailTemplate::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('visibility'),
                AllowedFilter::exact('email_theme_id'),
                AllowedFilter::partial('name'),
            )
            ->allowedSorts('created_at', 'name', 'status')
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return EmailTemplateResource::collection($templates);
    }

    public function show(EmailTemplate $emailTemplate): EmailTemplateResource
    {
        return new EmailTemplateResource($emailTemplate);
    }

    public function store(Request $request, CreateEmailTemplateAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email_theme_id' => ['sometimes', 'nullable', 'string', 'exists:email_themes,id'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'design_json' => ['sometimes', 'array'],
            'html_cache' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:draft,active,archived'],
            'visibility' => ['sometimes', 'in:private,public'],
        ]);

        $team = Team::findOrFail($request->user()->current_team_id);
        $template = $action->execute($team, $request->only([
            'name', 'email_theme_id', 'subject', 'preview_text',
            'design_json', 'html_cache', 'status', 'visibility',
        ]));

        return (new EmailTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, EmailTemplate $emailTemplate, UpdateEmailTemplateAction $action): EmailTemplateResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email_theme_id' => ['sometimes', 'nullable', 'string', 'exists:email_themes,id'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'design_json' => ['sometimes', 'array'],
            'html_cache' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:draft,active,archived'],
            'visibility' => ['sometimes', 'in:private,public'],
        ]);

        $template = $action->execute($emailTemplate, $request->only([
            'name', 'email_theme_id', 'subject', 'preview_text',
            'design_json', 'html_cache', 'status', 'visibility',
        ]));

        return new EmailTemplateResource($template);
    }

    /**
     * @response 200 {"message": "Email template deleted."}
     */
    public function destroy(EmailTemplate $emailTemplate, DeleteEmailTemplateAction $action): JsonResponse
    {
        $action->execute($emailTemplate);

        return response()->json(['message' => 'Email template deleted.']);
    }

    /**
     * Generate email template content from a natural language description.
     *
     * @response 200 {"mjml_source": "...", "html_preview": "...", "subject_suggestion": "..."}
     */
    public function generate(Request $request, EmailTemplate $emailTemplate, GenerateEmailTemplateAction $action): JsonResponse
    {
        $request->validate([
            'description' => ['required', 'string', 'min:10'],
            'tone' => ['sometimes', 'in:professional,friendly,promotional,transactional'],
        ]);

        $theme = $emailTemplate->email_theme_id
            ? EmailTheme::find($emailTemplate->email_theme_id)
            : null;

        $result = $action->execute(
            description: $request->input('description'),
            theme: $theme,
            tone: $request->input('tone', 'professional'),
            teamId: $request->user()->current_team_id,
        );

        return response()->json($result);
    }
}
