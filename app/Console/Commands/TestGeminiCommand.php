<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestGeminiCommand extends Command
{
    protected $signature = 'blueprint:test-gemini';

    protected $description = 'Cek apakah GEMINI_API_KEY terbaca dan bisa generate';

    public function handle(): int
    {
        $key = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        $primary = (string) config('blueprint.gemini_model', 'gemini-3.8-flash');
        $models = array_values(array_unique(array_filter([
            $primary,
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
        ])));

        if ($key === '') {
            $this->error('GEMINI_API_KEY kosong / tidak kebaca config.');
            $this->line('Isi di .env:');
            $this->line('GEMINI_API_KEY=...');
            $this->line('GEMINI_MODEL=gemini-3.8-flash');
            $this->line('Lalu: php artisan config:clear');

            return self::FAILURE;
        }

        $this->info('Key terbaca: '.substr($key, 0, 8).'...'.substr($key, -4).' (len '.strlen($key).')');
        $this->info('Coba model: '.implode(', ', $models));

        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

            try {
                $response = Http::timeout(30)->post($url, [
                    'contents' => [[
                        'parts' => [['text' => 'Balas JSON saja: {"ok":true,"msg":"halo"}']],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->warn("{$model}: request gagal — ".$e->getMessage());

                continue;
            }

            if (! $response->successful()) {
                $this->warn("{$model}: HTTP ".$response->status().' — '.(data_get($response->json(), 'error.message') ?: 'error'));

                continue;
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            $this->info("OK — {$model} merespons.");
            $this->line(is_string($text) ? Str::limit($text, 200) : json_encode($response->json()));

            return self::SUCCESS;
        }

        $this->error('Semua model gagal. Cek API key / billing Gemini / akses model.');

        return self::FAILURE;
    }
}
