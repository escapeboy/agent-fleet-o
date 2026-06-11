<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Contracts\ChatbotFeedbackRecorderInterface;
use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Telegram\Actions\SendTelegramReplyAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatbotTelegramWebhookController extends Controller
{
    /** Cache key prefix for the per-(chat, user) "awaiting 👎 comment" state. */
    private const FEEDBACK_COMMENT_PREFIX = 'tg:fbcomment:';

    /** How long we wait for the user to type their optional comment. */
    private const FEEDBACK_COMMENT_TTL = 600; // 10 minutes

    /** Cache key prefix for the resolved bot username (per bot token). */
    private const BOT_USERNAME_PREFIX = 'tg:botusername:';

    /** Bot identity is stable per token — cache it for a day. */
    private const BOT_USERNAME_TTL = 86400;

    /**
     * Handle an inbound Telegram webhook push for a chatbot channel.
     *
     * The URL is chatbot-specific: POST /api/chatbot/telegram/{tokenPrefix}
     * This isolates each chatbot's Telegram bot from others (no cross-bot routing).
     * Returns 200 immediately; processing is async via ProcessChatbotTelegramMessageJob.
     */
    public function handle(Request $request, string $tokenPrefix): Response
    {
        // Resolve chatbot from token prefix (first 8 chars of API token)
        $token = ChatbotToken::where('token_prefix', $tokenPrefix)
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return response('', 200); // Return 200 to silence Telegram retries
        }

        $chatbot = $token->chatbot;

        if (! $chatbot || ! $chatbot->status->isActive()) {
            return response('', 200);
        }

        // Find the active Telegram channel config for this chatbot
        $channel = $chatbot->channels()
            ->where('channel_type', 'telegram')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response('', 200);
        }

        // Verify webhook secret token if configured
        $webhookSecret = $channel->config['webhook_secret'] ?? null;
        if ($webhookSecret) {
            $incomingSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($incomingSecret !== $webhookSecret) {
                return response('', 200); // Silent fail
            }
        }

        $update = $request->json()->all();

        $callbackQuery = $update['callback_query'] ?? null;
        $callbackData = (string) ($callbackQuery['data'] ?? '');
        $botToken = $channel->config['bot_token'] ?? null;

        // A locked vote button carries `fb:noop`. The keyboard is already gone
        // for the user who voted, but a stray/forged tap can still arrive —
        // just acknowledge it so Telegram stops retrying, and do nothing else.
        if ($callbackQuery !== null && $callbackData === 'fb:noop') {
            if ($botToken && isset($callbackQuery['id'])) {
                app(SendTelegramReplyAction::class)->answerCallbackQuery(
                    $botToken,
                    (string) $callbackQuery['id'],
                );
            }

            return response('', 200);
        }

        // Per-answer vote callback (👍/👎). Record the vote and acknowledge —
        // do NOT route it through the message job as if it were a new question.
        if ($callbackQuery !== null && preg_match('/^fb:(up|down):(.+)$/', $callbackData, $m) === 1) {
            $vote = $m[1] === 'up' ? 'thumbs_up' : 'thumbs_down';
            $messageId = $m[2];

            // Authorization (anti-IDOR): this webhook is public, so a forged
            // callback could carry another tenant's message id. Reject any id
            // that belongs to a DIFFERENT chatbot's message; own-chatbot ids and
            // non-local ids (downstream/Barsy-owned, never a ChatbotMessage row)
            // pass through so the recorder seam stays substitutable.
            $belongsToOtherChatbot = ChatbotMessage::withoutGlobalScopes()
                ->where('id', $messageId)
                ->where('chatbot_id', '!=', $chatbot->id)
                ->exists();

            if (! $belongsToOtherChatbot) {
                app(ChatbotFeedbackRecorderInterface::class)->record($messageId, $vote);
            }

            $reply = app(SendTelegramReplyAction::class);

            // Lock the choice: replace the two-button keyboard on the original
            // bot message with a single, non-actionable confirmation button so
            // the vote can no longer be changed or re-cast. `fb:noop` callback
            // data means a stray tap does nothing.
            $voteMessageId = $callbackQuery['message']['message_id'] ?? null;
            $voteChatId = $callbackQuery['message']['chat']['id'] ?? null;
            if ($botToken && $voteMessageId !== null && $voteChatId !== null) {
                $lockedLabel = $vote === 'thumbs_up' ? '✅ Благодаря!' : '✅ Отбелязано';
                try {
                    $reply->editMessageReplyMarkup(
                        $botToken,
                        (string) $voteChatId,
                        (int) $voteMessageId,
                        [[['text' => $lockedLabel, 'callback_data' => 'fb:noop']]],
                    );
                } catch (\Throwable $e) {
                    // Message may be too old to edit, or already edited — the vote
                    // is recorded regardless, so never fail the webhook over this.
                    Log::warning('Failed to lock Telegram vote keyboard', [
                        'chatbot_id' => $chatbot->id,
                        'message_id' => $voteMessageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // On a 👎 only: invite an optional free-text reason and remember
            // that this user's next plain text message in this chat is that
            // comment. Keyed per (chat, user) so that in a group only the SAME
            // voter's follow-up is captured — not another member's message.
            // The stored value is the feedback message id from fb:down:<id>.
            $voteUserId = $callbackQuery['from']['id'] ?? null;
            if ($vote === 'thumbs_down' && $botToken && $voteChatId !== null) {
                Cache::put(
                    $this->feedbackCommentKey((string) $voteChatId, $voteUserId),
                    $messageId,
                    self::FEEDBACK_COMMENT_TTL,
                );

                $reply->execute(
                    $botToken,
                    (string) $voteChatId,
                    'Какво не беше наред? Може да опишете с няколко думи (по желание).',
                );
            }

            if ($botToken && isset($callbackQuery['id'])) {
                $reply->answerCallbackQuery(
                    $botToken,
                    (string) $callbackQuery['id'],
                    'Благодаря за обратната връзка!',
                );
            }

            return response('', 200);
        }

        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;

        if (! $message) {
            return response('', 200);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $chatType = (string) ($message['chat']['type'] ?? 'private');
        $text = $message['text'] ?? $update['callback_query']['data'] ?? '';
        $fromUserId = $message['from']['id'] ?? null;
        $username = ($update['callback_query']['from'] ?? $message['from'] ?? [])['username'] ?? null;

        // Bot slash-commands are not questions and must never reach the RAG
        // pipeline (it would decline them as out-of-scope). Intercept here —
        // before the pending-👎-comment capture (so a command isn't recorded as
        // feedback) and before group-gating (so /start works in groups too,
        // where Telegram still delivers commands under privacy mode). Only for
        // real inbound messages; callbacks never carry slash-commands.
        if (isset($update['message']) && str_starts_with($text, '/')) {
            $command = strtolower(strtok(ltrim($text, '/'), " @\n\t"));

            if ($command === 'start' && $botToken) {
                app(SendTelegramReplyAction::class)->execute(
                    $botToken,
                    $chatId,
                    'Здравейте! 👋 Аз съм виртуалният асистент на Barsy. Просто напишете въпроса си тук и ще се опитам да помогна. След всеки отговор може да гласувате 👍/👎, за да го подобрявам.',
                );
            }

            // Any other command (incl. unknown ones) is silently ignored — we
            // expose no command surface yet.
            return response('', 200);
        }

        // If we previously prompted this user for a 👎 comment, treat their next
        // plain text message as that comment instead of a new question. The key
        // is per (chat, user) so in a group only the voter's own follow-up is
        // captured. The TTL bounds staleness; we can't distinguish a genuine
        // follow-up question, so the next text becomes the comment.
        if ($chatId !== '' && $text !== '' && isset($update['message'])) {
            $commentKey = $this->feedbackCommentKey($chatId, $fromUserId);
            $pendingMessageId = Cache::get($commentKey);

            if ($pendingMessageId !== null) {
                Cache::forget($commentKey);

                app(ChatbotFeedbackRecorderInterface::class)->recordComment($pendingMessageId, $text);

                if ($botToken) {
                    app(SendTelegramReplyAction::class)->execute(
                        $botToken,
                        $chatId,
                        'Благодаря за обратната връзка!',
                    );
                }

                return response('', 200);
            }
        }

        if ($chatId === '' || $text === '') {
            return response('', 200);
        }

        // Default: private chats answer every message, keyed only by chat id.
        $sessionExternalId = $chatId;

        // Groups/supergroups: only respond when explicitly addressed (an @mention
        // of the bot or a reply to one of the bot's own messages). All other
        // ambient chatter is ignored. Each member gets their own session via a
        // "<chatId>:<userId>" key so context never bleeds between participants.
        if ($chatType === 'group' || $chatType === 'supergroup') {
            $botUserId = ($botToken !== null && $botToken !== '')
                ? (int) strtok($botToken, ':')
                : null;

            $directedText = $this->directedText($message, $botToken, $botUserId);

            if ($directedText === null || $directedText === '') {
                return response('', 200);
            }

            $text = $directedText;
            $sessionExternalId = $fromUserId !== null ? $chatId.':'.$fromUserId : $chatId;
        }

        ProcessChatbotTelegramMessageJob::dispatch(
            $chatbot->id,
            $channel->id,
            $chatId,
            $text,
            $username,
            $sessionExternalId,
        );

        return response('', 200);
    }

    /**
     * Build the per-(chat, user) cache key for the pending 👎-comment state.
     */
    private function feedbackCommentKey(string $chatId, int|string|null $userId): string
    {
        return self::FEEDBACK_COMMENT_PREFIX.$chatId.':'.($userId ?? '');
    }

    /**
     * Decide whether a group message is addressed to the bot, and return the
     * question text to dispatch (with the @mention token stripped). Returns null
     * when the message is ambient chatter that should be ignored.
     *
     * A message is "directed" when it either replies to one of the bot's own
     * messages (reply_to_message.from.id == bot user id) or @mentions the bot
     * (a `mention` entity matching '@<bot_username>', or a `text_mention`
     * entity whose user.id == bot user id).
     *
     * @param  array<string, mixed>  $message
     */
    private function directedText(array $message, ?string $botToken, ?int $botUserId): ?string
    {
        $text = (string) ($message['text'] ?? '');

        $isReplyToBot = $botUserId !== null
            && (int) ($message['reply_to_message']['from']['id'] ?? 0) === $botUserId;

        $hasMentionEntity = false;
        $isTextMention = false;

        foreach ($message['entities'] ?? [] as $entity) {
            $type = $entity['type'] ?? null;

            if ($type === 'mention') {
                $hasMentionEntity = true;
            }

            if ($type === 'text_mention'
                && $botUserId !== null
                && (int) ($entity['user']['id'] ?? 0) === $botUserId) {
                $isTextMention = true;
            }
        }

        $botUsername = ($hasMentionEntity && $botToken !== null && $botToken !== '')
            ? $this->resolveBotUsername($botToken)
            : null;

        $isAtMention = $botUsername !== null && stripos($text, '@'.$botUsername) !== false;

        if (! $isReplyToBot && ! $isAtMention && ! $isTextMention) {
            return null;
        }

        // Strip the '@<bot_username>' token so the RAG question isn't polluted.
        if ($isAtMention) {
            $text = (string) preg_replace('/@'.preg_quote($botUsername, '/').'\b/i', '', $text);
            $text = trim((string) preg_replace('/\s{2,}/', ' ', $text));
        }

        return $text;
    }

    /**
     * Resolve the bot's @username via getMe, cached per bot token (the identity
     * is stable). Returns null if Telegram is unreachable — callers treat a null
     * username as "no @mention match", falling back to reply-detection only.
     */
    private function resolveBotUsername(string $botToken): ?string
    {
        return Cache::remember(
            self::BOT_USERNAME_PREFIX.md5($botToken),
            self::BOT_USERNAME_TTL,
            function () use ($botToken): ?string {
                $me = app(SendTelegramReplyAction::class)->getMe($botToken);

                return is_array($me) ? ($me['username'] ?? null) : null;
            },
        );
    }
}
