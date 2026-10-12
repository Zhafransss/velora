<?php

namespace App\Livewire\Tenant;

use App\Models\AiTestMessage;
use App\Models\Bot;
use App\Support\Tenancy\CurrentTenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiGatewayException;
use App\Services\Ai\PromptBuilder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * FR-008: tenant menguji bot tanpa mengirim apa pun ke pelanggan. Hanya owner/admin (memicu biaya LLM).
 * Pemakaian dicatat di ai_test_messages (dan ai_calls oleh gateway) serta dibatasi per workspace per jam.
 */
class ChatTester extends Component
{
    public string $message = '';

    /** @var list<array{role: string, content: string}> riwayat percakapan uji pada sesi halaman ini */
    public array $turns = [];

    public ?string $error = null;

    public bool $truncated = false;

    public function send(): void
    {
        abort_unless($this->canTest(), 403);

        $this->error = null;
        $this->validate(['message' => ['required', 'string', 'max:' . (int) config('ai.customer_message_max_chars')]]);

        $bot = Bot::orderBy('id')->first();

        if ($bot === null) {
            $this->error = 'Simpan Pengaturan Bot terlebih dahulu sebelum menguji.';

            return;
        }

        $tenant = app(CurrentTenant::class)->get();
        $limitKey = 'ai-test:' . $tenant->id;
        $limit = (int) config('ai.test_limit_per_hour');

        // Hit dulu (atomik) baru panggil AI, supaya klik beruntun tidak melewati batas.
        if (! RateLimiter::attempt($limitKey, $limit, fn () => true, 3600)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limitKey) / 60);
            $this->error = "Batas tes {$limit} per jam tercapai. Coba lagi dalam sekitar {$minutes} menit.";

            return;
        }

        $question = $this->message;
        $history = $this->turns;

        try {
            $built = app(PromptBuilder::class)->build($bot, $question, $history);
            $answer = app(AiGateway::class)->generate($built->request, 'test');
        } catch (AiGatewayException $e) {
            $this->recordTest($bot, $question, null, 'failed', $e->lastType());
            $this->error = 'Layanan AI sedang tidak tersedia. Silakan coba lagi beberapa saat lagi.';

            return;
        }

        $this->recordTest($bot, $question, $answer->text, 'success', null, $answer, $built->stats['truncated']);

        $this->turns[] = ['role' => 'user', 'content' => $question];
        $this->turns[] = ['role' => 'assistant', 'content' => $answer->text];
        $this->truncated = $built->stats['truncated'];
        $this->message = '';
    }

    public function newConversation(): void
    {
        $this->turns = [];
        $this->error = null;
        $this->message = '';
        $this->truncated = false;
    }

    public function canTest(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    private function recordTest(Bot $bot, string $question, ?string $answerText, string $status, ?string $errorType, $answer = null, bool $truncated = false): void
    {
        AiTestMessage::create([
            'user_id' => auth()->id(),
            'bot_id' => $bot->id,
            'question' => $question,
            'answer' => $answerText,
            'status' => $status,
            'error_type' => $errorType,
            'provider' => $answer?->provider,
            'model' => $answer?->model,
            'input_tokens' => $answer?->inputTokens,
            'output_tokens' => $answer?->outputTokens,
            'latency_ms' => $answer?->latencyMs,
            'context_truncated' => $truncated,
        ]);
    }

    public function render()
    {
        return view('livewire.tenant.chat-tester', [
            'hasBot' => Bot::exists(),
            'canTest' => $this->canTest(),
            'recent' => AiTestMessage::latest('id')->limit(10)->get(),
            'limit' => (int) config('ai.test_limit_per_hour'),
        ])->layout('tenant.layouts.app', ['title' => 'Tes Chatbot']);
    }
}
