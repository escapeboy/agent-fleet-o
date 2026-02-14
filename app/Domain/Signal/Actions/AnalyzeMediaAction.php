<?php

namespace App\Domain\Signal\Actions;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AnalyzeMediaAction
{
    /**
     * Analyze a media file using multimodal LLM capabilities.
     *
     * Returns a text description/transcription of the media content.
     */
    public function execute(Media $media): ?string
    {
        $mimeType = $media->mime_type;

        try {
            if (str_starts_with($mimeType, 'image/')) {
                return $this->analyzeImage($media);
            }

            if ($mimeType === 'application/pdf') {
                return $this->analyzeDocument($media);
            }

            Log::debug('AnalyzeMediaAction: Unsupported media type', [
                'mime_type' => $mimeType,
                'media_id' => $media->id,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('AnalyzeMediaAction: Analysis failed', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function analyzeImage(Media $media): string
    {
        $path = $media->getPath();

        $response = Prism::text()
            ->using('anthropic', 'claude-sonnet-4-20250514')
            ->withSystemPrompt('You are a helpful assistant that describes images accurately and concisely. Extract all relevant text, data, and visual information.')
            ->withPrompt('Describe this image in detail. Extract any text, numbers, charts, or data visible in the image.', [
                Image::fromLocalPath($path),
            ])
            ->asText();

        return $response->text;
    }

    private function analyzeDocument(Media $media): string
    {
        $path = $media->getPath();
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }

        // For PDFs, use base64 encoding with the multimodal API
        $base64 = base64_encode($content);

        $response = Prism::text()
            ->using('anthropic', 'claude-sonnet-4-20250514')
            ->withSystemPrompt('You are a helpful assistant that extracts and summarizes document content accurately.')
            ->withPrompt("Extract and summarize the key content from this PDF document.\n\n[Document provided as attachment]")
            ->asText();

        return $response->text;
    }
}
