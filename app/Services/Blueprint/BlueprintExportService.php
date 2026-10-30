<?php

namespace App\Services\Blueprint;

use App\Models\BrandKit;
use App\Models\ContentBlueprint;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BlueprintExportService
{
    public function __construct(
        protected BlueprintGeneratorService $generator,
    ) {}

    /**
     * @param  'full'|'naskah'  $scope
     * @param  'pdf'|'docx'  $format
     */
    public function download(ContentBlueprint $bp, BrandKit $kit, string $format = 'pdf', string $scope = 'full'): StreamedResponse
    {
        $format = in_array($format, ['pdf', 'docx'], true) ? $format : 'pdf';
        $scope = $scope === 'naskah' ? 'naskah' : 'full';
        $slug = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit((string) ($bp->cover_theme ?: $bp->title ?: 'blueprint'), 50, '')) ?: 'blueprint';
        $suffix = $scope === 'naskah' ? '-naskah' : '';
        $filename = "blueprint-{$slug}{$suffix}.{$format}";

        if ($format === 'docx') {
            $binary = $this->toDocxBinary($bp, $kit, $scope);

            return response()->streamDownload(function () use ($binary) {
                echo $binary;
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
        }

        $binary = $this->toPdfBinary($bp, $kit, $scope);

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @param  'full'|'naskah'  $scope
     */
    public function toHtml(ContentBlueprint $bp, BrandKit $kit, string $scope = 'full'): string
    {
        $voice = $bp->voice_perspective ?: ($kit->voice_perspective ?: 'Aku');
        $title = e((string) ($bp->cover_theme ?: $bp->title ?: 'Blueprint'));
        $concept = e((string) ($bp->story_concept ?? ''));
        $dur = (int) ($bp->duration_seconds ?: 0);
        $rows = '';

        if ($scope === 'naskah') {
            $current = null;
            foreach ($bp->scenes ?? [] as $scene) {
                $type = strtolower((string) ($scene['visual_type'] ?? 'ai'));
                if (in_array($type, ['bumper', 'black'], true)) {
                    continue;
                }
                $vo = trim((string) ($scene['vo'] ?? ''));
                if ($vo === '') {
                    continue;
                }
                $section = (string) ($scene['section'] ?? '');
                if ($section !== '' && $section !== $current) {
                    $current = $section;
                    $rows .= '<h3 style="margin:18px 0 8px;font-size:13pt;color:#0f766e;">'.e($section).'</h3>';
                }
                $tc = e((string) ($scene['timecode'] ?? ''));
                $rows .= '<p style="margin:0 0 10px;line-height:1.45;"><strong>'.$tc.'</strong><br>'.e($vo).'</p>';
            }

            return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$title} — Naskah</title></head>
<body style="font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111; margin: 24px;">
  <h1 style="font-size:18pt;margin:0 0 6px;">{$title}</h1>
  <p style="margin:0 0 4px;color:#444;font-size:10pt;">Naskah VO saja · Sudut “{$voice}” · Durasi {$dur}s</p>
  <p style="margin:0 0 16px;color:#555;font-size:10pt;">{$concept}</p>
  <hr style="border:none;border-top:1px solid #ddd;margin:0 0 16px;">
  {$rows}
</body></html>
HTML;
        }

        $current = null;
        foreach ($bp->scenes ?? [] as $scene) {
            $section = (string) ($scene['section'] ?? '');
            if ($section !== '' && $section !== $current) {
                $current = $section;
                $rows .= '<h3 style="margin:18px 0 8px;font-size:13pt;color:#0f766e;">'.e($section).'</h3>';
            }
            $tc = e((string) ($scene['timecode'] ?? ''));
            $vo = e((string) ($scene['vo'] ?? ''));
            $on = e((string) ($scene['on_screen'] ?? ''));
            $img = e((string) ($scene['image_prompt'] ?? ''));
            $mot = e((string) ($scene['motion_prompt'] ?? ''));
            $type = e((string) ($scene['visual_type'] ?? ''));
            $rows .= <<<ROW
<div style="margin:0 0 14px;padding:10px 0;border-bottom:1px solid #eee;">
  <p style="margin:0 0 4px;"><strong>{$tc}</strong> <span style="color:#666;font-size:9pt;">({$type})</span></p>
  <p style="margin:0 0 4px;"><strong>VO:</strong> {$vo}</p>
  <p style="margin:0 0 4px;"><strong>On-screen:</strong> {$on}</p>
  <p style="margin:0 0 4px;font-size:9pt;color:#333;"><strong>Visual:</strong> {$img}</p>
  <p style="margin:0;font-size:9pt;color:#333;"><strong>Motion:</strong> {$mot}</p>
</div>
ROW;
        }

        $thumb = e((string) ($bp->thumbnail_prompt ?? ''));
        $caption = nl2br(e((string) ($bp->caption ?? '')));

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$title}</title></head>
<body style="font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111; margin: 24px;">
  <h1 style="font-size:18pt;margin:0 0 6px;">{$title}</h1>
  <p style="margin:0 0 4px;color:#444;font-size:10pt;">Blueprint lengkap · Sudut “{$voice}” · Durasi {$dur}s · Framework FLSR</p>
  <p style="margin:0 0 16px;color:#555;font-size:10pt;">{$concept}</p>
  <hr style="border:none;border-top:1px solid #ddd;margin:0 0 16px;">
  {$rows}
  <h3 style="margin:20px 0 8px;font-size:13pt;color:#0f766e;">Thumbnail</h3>
  <p style="font-size:9pt;">{$thumb}</p>
  <h3 style="margin:20px 0 8px;font-size:13pt;color:#0f766e;">Caption</h3>
  <p style="font-size:10pt;">{$caption}</p>
</body></html>
HTML;
    }

    /**
     * @param  'full'|'naskah'  $scope
     */
    public function toPdfBinary(ContentBlueprint $bp, BrandKit $kit, string $scope = 'full'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->toHtml($bp, $kit, $scope));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  'full'|'naskah'  $scope
     */
    public function toDocxBinary(ContentBlueprint $bp, BrandKit $kit, string $scope = 'full'): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        // PhpWord Html helper expects a fragment; strip html/body wrappers.
        $html = $this->toHtml($bp, $kit, $scope);
        $html = preg_replace('/^.*?<body[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<\/body>.*$/is', '', $html) ?? $html;
        Html::addHtml($section, $html, false, false);

        $tmp = tempnam(sys_get_temp_dir(), 'bpdocx');
        if ($tmp === false) {
            throw new \RuntimeException('Tidak bisa buat file sementara untuk DOCX.');
        }
        $path = $tmp.'.docx';
        @unlink($tmp);
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }
}
