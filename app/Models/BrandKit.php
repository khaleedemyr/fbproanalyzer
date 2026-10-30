<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ContentBlueprint::class);
    }

    public static function defaultFor(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'KEA Abbasy'],
            [
                'niche' => 'psikologi',
                'character_name' => 'Stickman Executive',
                'character_visual' => 'A stickman figure made of thin black wireframe lines, wearing a sharp black executive tuxedo with white shirt and black bowtie. Beside him is a small yellow rubber duck prop. Minimal face, no detailed facial features. Consistent character across all frames.',
                'visual_style' => 'Cinematic 9:16 vertical. Photorealistic ENVIRONMENT only (Jakarta urban night, neon city lights, dramatic rim lighting, shallow DOF) — the character MUST remain a stickman wireframe figure, never a real human. Moody psychology storytelling, high contrast, film still quality.',
                'aspect_ratio' => '9:16',
                'on_screen_font' => 'Anton',
                'voice_perspective' => 'Aku',
                'caption_hashtags' => "#keaabbasy #psikologigelap #darkpsychology #manipulasi #mindset #reelsviral #kontenpsikologi",
                'image_tool' => 'Leonardo AI',
                'video_tool' => 'Image-to-Video',
            ]
        );
    }
}
