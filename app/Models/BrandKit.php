<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class BrandKit extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'niche',
        'character_name',
        'character_visual',
        'visual_style',
        'aspect_ratio',
        'on_screen_font',
        'voice_perspective',
        'caption_hashtags',
        'image_tool',
        'video_tool',
        'is_active',
        'region',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ContentBlueprint::class);
    }

    public static function defaultAttributes(): array
    {
        return [
            'niche' => 'psikologi',
            'character_name' => 'Stickman Executive',
            'character_visual' => 'A stickman figure made of thin black wireframe lines, wearing a sharp black executive tuxedo with white shirt and black bowtie. Beside him is a small yellow rubber duck prop. Minimal face, no detailed facial features. Consistent character across all frames.',
            'visual_style' => 'Cinematic 9:16 vertical. Photorealistic ENVIRONMENT only (Jakarta urban night, neon city lights, dramatic rim lighting, shallow DOF) — the character MUST remain a stickman wireframe figure, never a real human. Moody psychology storytelling, high contrast, film still quality.',
            'aspect_ratio' => '9:16',
            'on_screen_font' => 'Anton',
            'voice_perspective' => 'Aku',
            'caption_hashtags' => '#keaabbasy #psikologigelap #darkpsychology #manipulasi #mindset #reelsviral #kontenpsikologi',
            'image_tool' => 'Leonardo AI',
            'video_tool' => 'Image-to-Video',
            'region' => 'ID',
            'is_active' => false,
        ];
    }

    /**
     * Kit aktif user — fallback buat default KEA Abbasy.
     */
    public static function activeFor(User $user): self
    {
        $active = static::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($active) {
            return $active;
        }

        $any = static::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if ($any) {
            $any->activate();

            return $any->fresh();
        }

        return static::defaultFor($user);
    }

    /**
     * Backward compatible: pastikan ada minimal 1 kit (KEA Abbasy).
     */
    public static function defaultFor(User $user): self
    {
        $kit = static::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'KEA Abbasy'],
            array_merge(static::defaultAttributes(), ['is_active' => true])
        );

        if (! static::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
            $kit->activate();
        }

        return $kit->fresh();
    }

    public function activate(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('user_id', $this->user_id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();
        });
    }

    public static function normalizeRegion(?string $region): string
    {
        $r = strtoupper(trim((string) $region));

        return in_array($r, ['ID', 'US'], true) ? $r : 'ID';
    }

    /**
     * Resolve kit by id for user, or active kit.
     */
    public static function resolveForUser(User $user, ?int $brandKitId = null): self
    {
        if ($brandKitId) {
            $kit = static::query()
                ->where('user_id', $user->id)
                ->where('id', $brandKitId)
                ->first();
            if ($kit) {
                return $kit;
            }
        }

        return static::activeFor($user);
    }
}
