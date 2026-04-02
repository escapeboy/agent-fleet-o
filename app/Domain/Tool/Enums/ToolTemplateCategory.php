<?php

namespace App\Domain\Tool\Enums;

enum ToolTemplateCategory: string
{
    case Ocr = 'ocr';
    case Stt = 'stt';
    case Tts = 'tts';
    case ImageGeneration = 'image_generation';
    case VideoGeneration = 'video_generation';
    case Embedding = 'embedding';
    case CodeExecution = 'code_execution';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Ocr => 'OCR / Document Processing',
            self::Stt => 'Speech to Text',
            self::Tts => 'Text to Speech',
            self::ImageGeneration => 'Image Generation',
            self::VideoGeneration => 'Video Generation',
            self::Embedding => 'Embeddings',
            self::CodeExecution => 'Code Execution',
            self::Custom => 'Custom',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ocr => '📄',
            self::Stt => '🎤',
            self::Tts => '🔊',
            self::ImageGeneration => '🎨',
            self::VideoGeneration => '🎬',
            self::Embedding => '🔢',
            self::CodeExecution => '💻',
            self::Custom => '🔧',
        };
    }
}
