<?php

namespace App\Services;

class ViralScoreService
{
    public function score(array $metrics): float
    {
        $reach = max(1, (int) ($metrics['reach'] ?? 0));
        $likes = (int) ($metrics['likes'] ?? 0);
        $comments = (int) ($metrics['comments'] ?? 0);
        $shares = (int) ($metrics['shares'] ?? 0);
        $saves = (int) ($metrics['saves'] ?? 0);

        $engagement = $likes + ($comments * 2) + ($shares * 3) + ($saves * 2.5);
        $rate = ($engagement / $reach) * 100;

        // Weighted heuristic for psychology niche content.
        $score = ($rate * 8)
            + min(30, $shares * 1.5)
            + min(20, $comments * 0.8)
            + min(15, log(max(1, $reach)) * 2);

        return round(min(100, $score), 2);
    }

    public function engagementRate(array $metrics): float
    {
        $reach = max(1, (int) ($metrics['reach'] ?? 0));
        $likes = (int) ($metrics['likes'] ?? 0);
        $comments = (int) ($metrics['comments'] ?? 0);
        $shares = (int) ($metrics['shares'] ?? 0);
        $saves = (int) ($metrics['saves'] ?? 0);

        return round((($likes + $comments + $shares + $saves) / $reach) * 100, 4);
    }

    public function detectFormat(?string $statusType, ?string $message): string
    {
        $statusType = strtolower((string) $statusType);
        $message = strtolower((string) $message);

        return match (true) {
            str_contains($statusType, 'reel') || str_contains($message, '#reels') => 'reel',
            str_contains($statusType, 'video') => 'video',
            str_contains($statusType, 'photo') || str_contains($statusType, 'added_photos') => 'photo',
            str_contains($statusType, 'shared_story') => 'share',
            default => filled($message) ? 'status' : 'post',
        };
    }

    public function detectTopicCluster(?string $message): ?string
    {
        $text = mb_strtolower((string) $message);

        $map = [
            'anxiety' => ['cemas', 'anxiety', 'panic', 'panik', 'khawatir'],
            'self-worth' => ['harga diri', 'insecure', 'tidak cukup', 'self worth', 'percaya diri'],
            'relationship' => ['hubungan', 'pasangan', 'toxic', 'breakup', 'putus', 'cinta beracun', 'gaslight'],
            'manipulation' => ['manipulasi', 'kontrol', 'bingkai', 'frame control', 'jebakan'],
            'trauma' => ['trauma', 'luka batin', 'masa lalu'],
            'habits' => ['kebiasaan', 'disiplin', 'produktivitas', 'prokrastinasi'],
            'emotion' => ['emosi', 'marah', 'sedih', 'overthinking', 'feeling'],
        ];

        foreach ($map as $cluster => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $cluster;
                }
            }
        }

        return 'umum';
    }

    public function formulaKey(?string $format, ?string $topicCluster): string
    {
        $format = strtolower(trim((string) ($format ?: 'post')));
        $topic = strtolower(trim((string) ($topicCluster ?: 'umum')));

        return $format.'|'.$topic;
    }
}
