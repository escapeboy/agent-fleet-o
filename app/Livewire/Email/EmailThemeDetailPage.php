<?php

namespace App\Livewire\Email;

use App\Domain\Email\Actions\DeleteEmailThemeAction;
use App\Domain\Email\Actions\UpdateEmailThemeAction;
use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use Livewire\Component;

class EmailThemeDetailPage extends Component
{
    public EmailTheme $theme;

    public bool $editing = false;

    // Editable fields
    public string $editName = '';
    public string $editStatus = 'draft';
    public string $editLogoUrl = '';
    public int $editLogoWidth = 150;
    public string $editBackgroundColor = '#f4f4f4';
    public string $editCanvasColor = '#ffffff';
    public string $editPrimaryColor = '#2563eb';
    public string $editTextColor = '#1f2937';
    public string $editHeadingColor = '#111827';
    public string $editMutedColor = '#6b7280';
    public string $editDividerColor = '#e5e7eb';
    public string $editFontName = 'Inter';
    public string $editFontUrl = '';
    public string $editFontFamily = 'Inter, Arial, sans-serif';
    public int $editHeadingFontSize = 24;
    public int $editBodyFontSize = 16;
    public float $editLineHeight = 1.6;
    public int $editEmailWidth = 600;
    public int $editContentPadding = 24;
    public string $editCompanyName = '';
    public string $editCompanyAddress = '';
    public string $editFooterText = '';

    public function mount(EmailTheme $theme): void
    {
        $this->theme = $theme;
    }

    public function startEdit(): void
    {
        $this->editName = $this->theme->name;
        $this->editStatus = $this->theme->status->value;
        $this->editLogoUrl = $this->theme->logo_url ?? '';
        $this->editLogoWidth = $this->theme->logo_width ?? 150;
        $this->editBackgroundColor = $this->theme->background_color;
        $this->editCanvasColor = $this->theme->canvas_color;
        $this->editPrimaryColor = $this->theme->primary_color;
        $this->editTextColor = $this->theme->text_color;
        $this->editHeadingColor = $this->theme->heading_color;
        $this->editMutedColor = $this->theme->muted_color;
        $this->editDividerColor = $this->theme->divider_color;
        $this->editFontName = $this->theme->font_name;
        $this->editFontUrl = $this->theme->font_url ?? '';
        $this->editFontFamily = $this->theme->font_family;
        $this->editHeadingFontSize = $this->theme->heading_font_size;
        $this->editBodyFontSize = $this->theme->body_font_size;
        $this->editLineHeight = $this->theme->line_height;
        $this->editEmailWidth = $this->theme->email_width;
        $this->editContentPadding = $this->theme->content_padding;
        $this->editCompanyName = $this->theme->company_name ?? '';
        $this->editCompanyAddress = $this->theme->company_address ?? '';
        $this->editFooterText = $this->theme->footer_text ?? '';
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editStatus' => 'required|in:draft,active,archived',
            'editLogoUrl' => 'nullable|url|max:500',
            'editLogoWidth' => 'integer|min:50|max:400',
            'editBackgroundColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editCanvasColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editPrimaryColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editTextColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editHeadingColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editMutedColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editDividerColor' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'editFontName' => 'required|max:100',
            'editFontUrl' => 'nullable|url|max:500',
            'editFontFamily' => 'required|max:200',
            'editHeadingFontSize' => 'integer|min:12|max:60',
            'editBodyFontSize' => 'integer|min:10|max:30',
            'editLineHeight' => 'numeric|min:1|max:3',
            'editEmailWidth' => 'integer|min:320|max:800',
            'editContentPadding' => 'integer|min:8|max:80',
            'editCompanyName' => 'nullable|max:255',
            'editCompanyAddress' => 'nullable|max:500',
            'editFooterText' => 'nullable|max:1000',
        ]);

        app(UpdateEmailThemeAction::class)->execute($this->theme, [
            'name' => $this->editName,
            'status' => $this->editStatus,
            'logo_url' => $this->editLogoUrl ?: null,
            'logo_width' => $this->editLogoWidth,
            'background_color' => $this->editBackgroundColor,
            'canvas_color' => $this->editCanvasColor,
            'primary_color' => $this->editPrimaryColor,
            'text_color' => $this->editTextColor,
            'heading_color' => $this->editHeadingColor,
            'muted_color' => $this->editMutedColor,
            'divider_color' => $this->editDividerColor,
            'font_name' => $this->editFontName,
            'font_url' => $this->editFontUrl ?: null,
            'font_family' => $this->editFontFamily,
            'heading_font_size' => $this->editHeadingFontSize,
            'body_font_size' => $this->editBodyFontSize,
            'line_height' => $this->editLineHeight,
            'email_width' => $this->editEmailWidth,
            'content_padding' => $this->editContentPadding,
            'company_name' => $this->editCompanyName ?: null,
            'company_address' => $this->editCompanyAddress ?: null,
            'footer_text' => $this->editFooterText ?: null,
        ]);

        $this->theme->refresh();
        $this->editing = false;

        session()->flash('message', 'Email theme saved.');
    }

    public function setAsDefault(): void
    {
        $team = auth()->user()->currentTeam;

        Team::withoutGlobalScopes()
            ->where('id', $team->id)
            ->update(['default_email_theme_id' => $this->theme->id]);

        session()->flash('message', 'This theme is now the default for all system emails.');
    }

    public function deleteTheme(): void
    {
        app(DeleteEmailThemeAction::class)->execute($this->theme);

        session()->flash('message', 'Email theme deleted.');
        $this->redirect(route('email.themes.index'));
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        return view('livewire.email.email-theme-detail-page', [
            'statuses' => EmailThemeStatus::cases(),
            'isDefault' => $team->default_email_theme_id === $this->theme->id,
        ])->layout('layouts.app', ['header' => $this->theme->name]);
    }
}
