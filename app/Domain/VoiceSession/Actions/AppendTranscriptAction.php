<?php

namespace App\Domain\VoiceSession\Actions;

use App\Domain\VoiceSession\Models\VoiceSession;
use Illuminate\Support\Facades\DB;

/**
 * Atomically appends a transcript turn to a VoiceSession using PostgreSQL JSONB concatenation.
 *
 * Uses a raw UPDATE with the `||` jsonb operator so that concurrent appends from the
 * Python voice worker and browser client do not race. No `lockForUpdate()` needed —
 * JSONB append is atomic at the Postgres level.
 *
 * Each turn has the shape: {role: 'user'|'agent'|'system', content: string, timestamp: ISO8601}
 */
class AppendTranscriptAction
{
    public function execute(VoiceSession $session, string $role, string $content): VoiceSession
    {
        $turn = json_encode([
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Atomic JSONB array append: transcript = transcript || '[{...}]'::jsonb
        DB::statement(
            'UPDATE voice_sessions SET transcript = transcript || ?::jsonb WHERE id = ?',
            [json_encode([['role' => $role, 'content' => $content, 'timestamp' => now()->toIso8601String()]]), $session->id],
        );

        return $session->refresh();
    }
}
