<?php

namespace App\Livewire\Email;

use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Actions\RenderEmailTemplateAction;
use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Enums\EmailTemplateStatus;
use App\Domain\Email\Enums\EmailTemplateVisibility;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class EmailTemplateBuilderPage extends Component
{
    public EmailTemplate $template;

    public string $name = '';

    public string $subject = '';

    public string $previewText = '';

    public string $status = 'draft';

    public string $visibility = 'private';

    public ?string $emailThemeId = null;

    public array $designJson = [];

    public function mount(EmailTemplate $template): void
    {
        $this->template = $template;
        $this->name = $template->name;
        $this->subject = $template->subject ?? '';
        $this->previewText = $template->preview_text ?? '';
        $this->status = $template->status->value;
        $this->visibility = $template->visibility->value;
        $this->emailThemeId = $template->email_theme_id;
        $this->designJson = $template->design_json ?? [];
    }

    public function saveSettings(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'name' => 'required|min:2|max:255',
            'subject' => 'nullable|max:500',
            'previewText' => 'nullable|max:200',
            'status' => 'required|in:draft,active,archived',
            'visibility' => 'required|in:private,public',
            'emailThemeId' => 'nullable|exists:email_themes,id',
        ]);

        app(UpdateEmailTemplateAction::class)->execute($this->template, [
            'name' => $this->name,
            'subject' => $this->subject ?: null,
            'preview_text' => $this->previewText ?: null,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'email_theme_id' => $this->emailThemeId ?: null,
        ]);

        $this->template->refresh();
        session()->flash('message', 'Settings saved.');
    }

    public function save(string $html, string $designJsonStr): void
    {
        Gate::authorize('edit-content');

        $designJson = json_decode($designJsonStr, true) ?? [];

        app(RenderEmailTemplateAction::class)->execute($this->template, $html, $designJson);

        $this->template->refresh();
        $this->designJson = $this->template->design_json ?? [];

        session()->flash('message', 'Template saved.');
    }

    public function deleteTemplate(): void
    {
        Gate::authorize('edit-content');

        app(DeleteEmailTemplateAction::class)->execute($this->template);

        session()->flash('message', 'Template deleted.');
        $this->redirect(route('email.templates.index'));
    }

    public function render()
    {
        $themes = EmailTheme::where('status', 'active')->orderBy('name')->get();

        return view('livewire.email.email-template-builder-page', [
            'statuses' => EmailTemplateStatus::cases(),
            'visibilities' => EmailTemplateVisibility::cases(),
            'themes' => $themes,
        ])->layout('layouts.app', ['header' => $this->template->name]);
    }
}
