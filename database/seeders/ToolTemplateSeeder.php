<?php

namespace Database\Seeders;

use App\Domain\Tool\Models\ToolTemplate;
use Illuminate\Database\Seeder;

class ToolTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'slug' => 'glm-ocr',
                'name' => 'GLM-OCR',
                'category' => 'ocr',
                'description' => 'State-of-the-art multimodal OCR model (0.9B params) from Zhipu AI. #1 on OmniDocBench V1.5 (94.62). Handles documents, tables, formulas, handwriting, and multi-language text extraction.',
                'icon' => '📄',
                'provider' => 'runpod',
                'docker_image' => 'vllm/vllm-openai:latest',
                'model_id' => 'stepfun-ai/GLM-OCR-2B',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL or base64-encoded image'],
                        'prompt' => ['type' => 'string', 'description' => 'Extraction prompt (default: extract all text)', 'default' => 'Extract all text from this image.'],
                    ],
                    'required' => ['image_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Extracted text content'],
                        'confidence' => ['type' => 'number', 'description' => 'Confidence score 0-1'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'stepfun-ai/GLM-OCR-2B', 'MAX_MODEL_LEN' => '4096'],
                    'volume_gb' => 20,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'ocr_extract',
                        'description' => 'Extract text from an image using GLM-OCR. Supports documents, tables, formulas, handwriting.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'image_url' => ['type' => 'string', 'description' => 'URL of the image to process'],
                                'prompt' => ['type' => 'string', 'description' => 'Optional extraction prompt'],
                            ],
                            'required' => ['image_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://github.com/zai-org/GLM-OCR',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'whisper-large-v3',
                'name' => 'Whisper Large v3',
                'category' => 'stt',
                'description' => 'OpenAI Whisper Large v3 for speech-to-text transcription. Supports 99+ languages, automatic language detection, and timestamp generation.',
                'icon' => '🎤',
                'provider' => 'runpod',
                'docker_image' => 'runpod/worker-faster-whisper:latest',
                'model_id' => 'large-v3',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_url' => ['type' => 'string', 'description' => 'URL of the audio file'],
                        'language' => ['type' => 'string', 'description' => 'Language code (auto-detected if omitted)'],
                        'word_timestamps' => ['type' => 'boolean', 'description' => 'Include word-level timestamps', 'default' => false],
                    ],
                    'required' => ['audio_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Transcribed text'],
                        'language' => ['type' => 'string', 'description' => 'Detected language'],
                        'segments' => ['type' => 'array', 'description' => 'Timestamped segments'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_SIZE' => 'large-v3'],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'transcribe_audio',
                        'description' => 'Transcribe audio to text using Whisper Large v3. Supports 99+ languages.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'audio_url' => ['type' => 'string', 'description' => 'URL of the audio file to transcribe'],
                                'language' => ['type' => 'string', 'description' => 'Language code (optional, auto-detected)'],
                            ],
                            'required' => ['audio_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/openai/whisper-large-v3',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'sdxl-turbo',
                'name' => 'SDXL Turbo',
                'category' => 'image_generation',
                'description' => 'Stability AI SDXL Turbo for fast image generation. Produces high-quality 512x512 images in a single diffusion step (~200ms).',
                'icon' => '🎨',
                'provider' => 'runpod',
                'docker_image' => 'runpod/worker-sdxl:latest',
                'model_id' => 'stabilityai/sdxl-turbo',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Text prompt for image generation'],
                        'negative_prompt' => ['type' => 'string', 'description' => 'What to avoid in the image'],
                        'width' => ['type' => 'integer', 'description' => 'Image width (default: 512)', 'default' => 512],
                        'height' => ['type' => 'integer', 'description' => 'Image height (default: 512)', 'default' => 512],
                        'num_inference_steps' => ['type' => 'integer', 'description' => 'Steps (1-4, default: 1)', 'default' => 1],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL of the generated image'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'stabilityai/sdxl-turbo'],
                    'volume_gb' => 20,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_image',
                        'description' => 'Generate an image from a text prompt using SDXL Turbo.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Text description of the desired image'],
                                'width' => ['type' => 'integer', 'description' => 'Image width (default: 512)'],
                                'height' => ['type' => 'integer', 'description' => 'Image height (default: 512)'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/stabilityai/sdxl-turbo',
                'license' => 'SDXL-Turbo License',
                'is_featured' => true,
                'sort_order' => 30,
            ],
            [
                'slug' => 'bge-m3-embeddings',
                'name' => 'BGE-M3 Embeddings',
                'category' => 'embedding',
                'description' => 'BAAI BGE-M3 multilingual embedding model. 1024-dim vectors, supports 100+ languages, optimized for retrieval and semantic similarity.',
                'icon' => '🔢',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/text-embeddings-inference:latest',
                'model_id' => 'BAAI/bge-m3',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'inputs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Text(s) to embed'],
                    ],
                    'required' => ['inputs'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'embeddings' => ['type' => 'array', 'description' => 'Vector embeddings (1024-dim each)'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 3090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'BAAI/bge-m3'],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_embeddings',
                        'description' => 'Generate vector embeddings for text using BGE-M3. 1024-dim, 100+ languages.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'texts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Texts to embed'],
                            ],
                            'required' => ['texts'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 3090',
                'estimated_cost_per_hour' => 230,
                'source_url' => 'https://huggingface.co/BAAI/bge-m3',
                'license' => 'MIT',
                'is_featured' => false,
                'sort_order' => 40,
            ],
            [
                'slug' => 'xtts-v2',
                'name' => 'XTTS v2',
                'category' => 'tts',
                'description' => 'Coqui XTTS v2 text-to-speech with voice cloning. Supports 17 languages, zero-shot voice cloning from a 6-second reference audio.',
                'icon' => '🔊',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/coqui-ai/xtts-streaming-server:latest',
                'model_id' => 'tts_models/multilingual/multi-dataset/xtts_v2',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to synthesize'],
                        'language' => ['type' => 'string', 'description' => 'Language code (e.g. en, de, fr)', 'default' => 'en'],
                        'speaker_wav_url' => ['type' => 'string', 'description' => 'URL of reference audio for voice cloning (optional)'],
                    ],
                    'required' => ['text'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_url' => ['type' => 'string', 'description' => 'URL of the generated audio file'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => [],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'text_to_speech',
                        'description' => 'Convert text to speech using XTTS v2. Supports voice cloning from reference audio.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'Text to synthesize into speech'],
                                'language' => ['type' => 'string', 'description' => 'Language code (en, de, fr, etc.)'],
                                'speaker_wav_url' => ['type' => 'string', 'description' => 'Reference audio URL for voice cloning'],
                            ],
                            'required' => ['text'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/coqui/XTTS-v2',
                'license' => 'CPML',
                'is_featured' => false,
                'sort_order' => 50,
            ],
            // --- Code Generation ---
            [
                'slug' => 'qwen25-coder-7b',
                'name' => 'Qwen2.5 Coder 7B',
                'category' => 'code_execution',
                'description' => 'Best-in-class 7B code generation model from Alibaba. Supports 92 programming languages with code completion, debugging, and refactoring.',
                'icon' => '💻',
                'provider' => 'runpod',
                'docker_image' => 'vllm/vllm-openai:latest',
                'model_id' => 'Qwen/Qwen2.5-Coder-7B-Instruct',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Code prompt or question'],
                        'max_tokens' => ['type' => 'integer', 'description' => 'Max tokens to generate', 'default' => 2048],
                        'temperature' => ['type' => 'number', 'description' => 'Sampling temperature', 'default' => 0.1],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Generated code or response'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'Qwen/Qwen2.5-Coder-7B-Instruct', 'MAX_MODEL_LEN' => '8192'],
                    'volume_gb' => 20,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_code',
                        'description' => 'Generate or complete code using Qwen2.5 Coder 7B. Supports 92 languages.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Code generation prompt'],
                                'language' => ['type' => 'string', 'description' => 'Programming language hint'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/Qwen/Qwen2.5-Coder-7B-Instruct',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 60,
            ],
            [
                'slug' => 'mistral-7b-instruct',
                'name' => 'Mistral 7B Instruct',
                'category' => 'custom',
                'description' => 'Fast, capable general-purpose 7B instruction-tuned model. Excellent for agentic tool-use, summarization, and reasoning tasks.',
                'icon' => '🧠',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/text-generation-inference:latest',
                'model_id' => 'mistralai/Mistral-7B-Instruct-v0.3',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Instruction or question'],
                        'max_tokens' => ['type' => 'integer', 'default' => 1024],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Generated response'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'mistralai/Mistral-7B-Instruct-v0.3'],
                    'volume_gb' => 20,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'llm_generate',
                        'description' => 'Generate text with Mistral 7B Instruct. Good for reasoning, summarization, and tool-use.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Input prompt or instruction'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/mistralai/Mistral-7B-Instruct-v0.3',
                'license' => 'Apache-2.0',
                'is_featured' => false,
                'sort_order' => 70,
            ],
            // --- Image Generation ---
            [
                'slug' => 'flux1-schnell',
                'name' => 'FLUX.1 Schnell',
                'category' => 'image_generation',
                'description' => 'Fastest FLUX variant from Black Forest Labs. 4-step generation with production-ready quality. Best speed/quality tradeoff for image generation.',
                'icon' => '🎨',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/diffusers-inference:latest',
                'model_id' => 'black-forest-labs/FLUX.1-schnell',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Text prompt for image generation'],
                        'width' => ['type' => 'integer', 'default' => 1024],
                        'height' => ['type' => 'integer', 'default' => 1024],
                        'num_inference_steps' => ['type' => 'integer', 'default' => 4],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL of the generated image'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'black-forest-labs/FLUX.1-schnell'],
                    'volume_gb' => 30,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_image',
                        'description' => 'Generate an image from text using FLUX.1 Schnell. Fast 4-step generation.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Text description of the desired image'],
                                'width' => ['type' => 'integer', 'description' => 'Image width (default: 1024)'],
                                'height' => ['type' => 'integer', 'description' => 'Image height (default: 1024)'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/black-forest-labs/FLUX.1-schnell',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 80,
            ],
            // --- Video Generation ---
            [
                'slug' => 'wan21-t2v',
                'name' => 'Wan2.1 Text-to-Video',
                'category' => 'video_generation',
                'description' => 'Lightweight 1.3B text-to-video model from Wan-AI. Generates 5-second video clips from text prompts. Best open-source T2V as of 2025.',
                'icon' => '🎬',
                'provider' => 'runpod',
                'docker_image' => 'runpod/pytorch:2.4.0-py3.11-cuda12.4.1-devel-ubuntu22.04',
                'model_id' => 'Wan-AI/Wan2.1-T2V-1.3B-Diffusers',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Text prompt describing the video'],
                        'num_frames' => ['type' => 'integer', 'default' => 24],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'video_url' => ['type' => 'string', 'description' => 'URL of the generated MP4 video'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'Wan-AI/Wan2.1-T2V-1.3B-Diffusers'],
                    'volume_gb' => 20,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_video',
                        'description' => 'Generate a short video clip from a text description using Wan2.1.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Text description of the desired video'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/Wan-AI/Wan2.1-T2V-1.3B-Diffusers',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 90,
            ],
            // --- Vision / Object Detection ---
            [
                'slug' => 'florence-2-large',
                'name' => 'Florence-2 Large',
                'category' => 'ocr',
                'description' => 'Microsoft vision-language model. One model for captioning, OCR, visual grounding, object detection, and region description. Extremely versatile.',
                'icon' => '👁️',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/text-generation-inference:latest',
                'model_id' => 'microsoft/Florence-2-large',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL of the image to analyze'],
                        'task' => ['type' => 'string', 'description' => 'Task: caption, ocr, detect, ground, region_caption', 'default' => 'caption'],
                        'prompt' => ['type' => 'string', 'description' => 'Text prompt for grounding tasks (optional)'],
                    ],
                    'required' => ['image_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Generated text (caption, OCR result, etc.)'],
                        'regions' => ['type' => 'array', 'description' => 'Detected regions with bounding boxes'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'microsoft/Florence-2-large'],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'analyze_image',
                        'description' => 'Analyze an image with Florence-2: captioning, OCR, object detection, visual grounding.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'image_url' => ['type' => 'string', 'description' => 'URL of the image'],
                                'task' => ['type' => 'string', 'description' => 'Task: caption, ocr, detect, ground'],
                            ],
                            'required' => ['image_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/microsoft/Florence-2-large',
                'license' => 'MIT',
                'is_featured' => true,
                'sort_order' => 100,
            ],
            [
                'slug' => 'sam2-hiera-large',
                'name' => 'SAM 2 (Segment Anything)',
                'category' => 'custom',
                'description' => 'Meta Segment Anything Model 2. Point or box-prompted segmentation for images and video. Extract objects, masks, and regions from any image.',
                'icon' => '✂️',
                'provider' => 'runpod',
                'docker_image' => 'runpod/pytorch:2.4.0-py3.11-cuda12.4.1-devel-ubuntu22.04',
                'model_id' => 'facebook/sam2-hiera-large',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL of the image to segment'],
                        'points' => ['type' => 'array', 'description' => 'Click points [[x,y], ...] for segmentation'],
                        'boxes' => ['type' => 'array', 'description' => 'Bounding boxes [[x1,y1,x2,y2], ...] for segmentation'],
                    ],
                    'required' => ['image_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'masks' => ['type' => 'array', 'description' => 'Segmentation masks as base64 PNGs'],
                        'scores' => ['type' => 'array', 'description' => 'Confidence scores per mask'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'facebook/sam2-hiera-large'],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'segment_image',
                        'description' => 'Segment objects in an image using SAM 2. Provide points or boxes as prompts.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'image_url' => ['type' => 'string', 'description' => 'URL of the image'],
                                'points' => ['type' => 'array', 'description' => 'Click points for segmentation'],
                            ],
                            'required' => ['image_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/facebook/sam2-hiera-large',
                'license' => 'Apache-2.0',
                'is_featured' => false,
                'sort_order' => 110,
            ],
            // --- Document Processing ---
            [
                'slug' => 'table-transformer',
                'name' => 'Table Transformer',
                'category' => 'ocr',
                'description' => 'Microsoft table detection and structure recognition. Extracts table structures from document images, PDFs, and scans. Lightweight and fast.',
                'icon' => '📊',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/transformers-inference:latest',
                'model_id' => 'microsoft/table-transformer-detection',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'URL of document image containing tables'],
                    ],
                    'required' => ['image_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tables' => ['type' => 'array', 'description' => 'Detected tables with bounding boxes and cell structure'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 3090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'microsoft/table-transformer-detection'],
                    'volume_gb' => 5,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'detect_tables',
                        'description' => 'Detect and extract table structures from a document image.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'image_url' => ['type' => 'string', 'description' => 'URL of the document image'],
                            ],
                            'required' => ['image_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 3090',
                'estimated_cost_per_hour' => 230,
                'source_url' => 'https://huggingface.co/microsoft/table-transformer-detection',
                'license' => 'MIT',
                'is_featured' => false,
                'sort_order' => 120,
            ],
            // --- Speech ---
            [
                'slug' => 'kokoro-tts',
                'name' => 'Kokoro TTS',
                'category' => 'tts',
                'description' => 'Ultra-lightweight 82M parameter TTS model with 54 built-in voices. Fastest open-source text-to-speech, runs on CPU too. Natural-sounding output.',
                'icon' => '🔊',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/text-generation-inference:latest',
                'model_id' => 'hexgrad/Kokoro-82M',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to synthesize'],
                        'voice' => ['type' => 'string', 'description' => 'Voice ID (54 available)', 'default' => 'af_heart'],
                        'speed' => ['type' => 'number', 'description' => 'Speech speed multiplier', 'default' => 1.0],
                    ],
                    'required' => ['text'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_url' => ['type' => 'string', 'description' => 'URL of the generated WAV audio'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 3090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'hexgrad/Kokoro-82M'],
                    'volume_gb' => 5,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'text_to_speech',
                        'description' => 'Convert text to speech using Kokoro TTS. 54 voices, ultra-fast.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'Text to synthesize'],
                                'voice' => ['type' => 'string', 'description' => 'Voice ID (default: af_heart)'],
                            ],
                            'required' => ['text'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 3090',
                'estimated_cost_per_hour' => 230,
                'source_url' => 'https://huggingface.co/hexgrad/Kokoro-82M',
                'license' => 'Apache-2.0',
                'is_featured' => true,
                'sort_order' => 130,
            ],
            // --- Translation ---
            [
                'slug' => 'nllb-200',
                'name' => 'NLLB-200 Translation',
                'category' => 'custom',
                'description' => 'Meta No Language Left Behind — 200-language neural machine translation. Best multilingual coverage of any open model. Distilled 600M variant is fast and lightweight.',
                'icon' => '🌍',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/text-generation-inference:latest',
                'model_id' => 'facebook/nllb-200-distilled-600M',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to translate'],
                        'source_lang' => ['type' => 'string', 'description' => 'Source language code (e.g. eng_Latn)'],
                        'target_lang' => ['type' => 'string', 'description' => 'Target language code (e.g. fra_Latn)'],
                    ],
                    'required' => ['text', 'target_lang'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'translated_text' => ['type' => 'string', 'description' => 'Translated text'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 3090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_ID' => 'facebook/nllb-200-distilled-600M'],
                    'volume_gb' => 5,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'translate_text',
                        'description' => 'Translate text between 200 languages using NLLB-200.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'Text to translate'],
                                'source_lang' => ['type' => 'string', 'description' => 'Source language code'],
                                'target_lang' => ['type' => 'string', 'description' => 'Target language code'],
                            ],
                            'required' => ['text', 'target_lang'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 3090',
                'estimated_cost_per_hour' => 230,
                'source_url' => 'https://huggingface.co/facebook/nllb-200-distilled-600M',
                'license' => 'CC-BY-NC-4.0',
                'is_featured' => false,
                'sort_order' => 140,
            ],
            // --- Music Generation ---
            [
                'slug' => 'musicgen-medium',
                'name' => 'MusicGen Medium',
                'category' => 'custom',
                'description' => 'Meta text-conditioned music generation. Creates up to 30 seconds of music from text prompts. Supports melody conditioning with reference audio.',
                'icon' => '🎵',
                'provider' => 'runpod',
                'docker_image' => 'ghcr.io/huggingface/transformers-inference:latest',
                'model_id' => 'facebook/musicgen-medium',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Text description of the music to generate'],
                        'duration' => ['type' => 'number', 'description' => 'Duration in seconds (max 30)', 'default' => 10],
                        'melody_url' => ['type' => 'string', 'description' => 'Optional reference audio URL for melody conditioning'],
                    ],
                    'required' => ['prompt'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_url' => ['type' => 'string', 'description' => 'URL of the generated music WAV file'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => ['MODEL_NAME' => 'facebook/musicgen-medium'],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_music',
                        'description' => 'Generate music from a text description using MusicGen. Up to 30 seconds.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Description of the music (e.g. "upbeat jazz piano")'],
                                'duration' => ['type' => 'number', 'description' => 'Duration in seconds (max 30)'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/facebook/musicgen-medium',
                'license' => 'CC-BY-NC-4.0',
                'is_featured' => false,
                'sort_order' => 150,
            ],
            [
                'slug' => 'f5-tts',
                'name' => 'F5-TTS Voice Cloning',
                'category' => 'tts',
                'description' => 'Flow-matching TTS with zero-shot voice cloning from just a 3-second reference clip. High-quality, natural-sounding speech synthesis.',
                'icon' => '🎙️',
                'provider' => 'runpod',
                'docker_image' => 'runpod/pytorch:2.4.0-py3.11-cuda12.4.1-devel-ubuntu22.04',
                'model_id' => 'SWivid/F5-TTS',
                'default_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to synthesize'],
                        'reference_audio_url' => ['type' => 'string', 'description' => 'URL of 3+ second reference audio for voice cloning'],
                        'reference_text' => ['type' => 'string', 'description' => 'Transcript of the reference audio'],
                    ],
                    'required' => ['text', 'reference_audio_url'],
                ],
                'default_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'audio_url' => ['type' => 'string', 'description' => 'URL of the cloned voice WAV audio'],
                    ],
                ],
                'deploy_config' => [
                    'gpu_type' => 'NVIDIA RTX 4090',
                    'min_workers' => 0,
                    'max_workers' => 1,
                    'idle_timeout' => 300,
                    'env' => [],
                    'volume_gb' => 10,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'clone_voice',
                        'description' => 'Clone a voice from a 3-second reference and generate speech using F5-TTS.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'Text to speak in the cloned voice'],
                                'reference_audio_url' => ['type' => 'string', 'description' => 'URL of reference audio (3+ seconds)'],
                            ],
                            'required' => ['text', 'reference_audio_url'],
                        ],
                    ],
                ],
                'estimated_gpu' => 'NVIDIA RTX 4090',
                'estimated_cost_per_hour' => 440,
                'source_url' => 'https://huggingface.co/SWivid/F5-TTS',
                'license' => 'CC-BY-NC-4.0',
                'is_featured' => false,
                'sort_order' => 160,
            ],
        ];

        foreach ($templates as $template) {
            ToolTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                $template,
            );
        }
    }
}
