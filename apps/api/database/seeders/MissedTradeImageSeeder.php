<?php

namespace Database\Seeders;

use App\Models\MissedTrade;
use App\Models\MissedTradeImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MissedTradeImageSeeder extends Seeder
{
    public function run(): void
    {
        $disk = (string) config('filesystems.trade_images_disk', 'public');
        Storage::disk($disk)->deleteDirectory('missed-trades');
        MissedTradeImage::query()->delete();

        $entries = MissedTrade::query()
            ->orderByDesc('date')
            ->limit(40)
            ->get();

        foreach ($entries as $entry) {
            $imageCount = fake()->numberBetween(0, 1);

            for ($index = 0; $index < $imageCount; $index += 1) {
                $fileName = (string) Str::uuid() . '.svg';
                $originalPath = "missed-trades/{$entry->id}/original/{$fileName}";
                $thumbPath = "missed-trades/{$entry->id}/thumbs/{$fileName}";

                $svgOriginal = $this->renderMockSnapshotSvg($entry->pair, $entry->model, 1360, 760, $index);
                $svgThumb = $this->renderMockSnapshotSvg($entry->pair, $entry->model, 680, 380, $index);

                Storage::disk($disk)->put($originalPath, $svgOriginal);
                Storage::disk($disk)->put($thumbPath, $svgThumb);

                MissedTradeImage::query()->create([
                    'missed_trade_id' => (int) $entry->id,
                    'image_url' => $originalPath,
                    'thumbnail_url' => $thumbPath,
                    'file_size' => strlen($svgOriginal),
                    'file_type' => 'image/svg+xml',
                    'sort_order' => $index,
                ]);
            }
        }
    }

    private function renderMockSnapshotSvg(string $pair, string $model, int $width, int $height, int $seed): string
    {
        $pairText = htmlspecialchars($pair, ENT_QUOTES, 'UTF-8');
        $modelText = htmlspecialchars($model, ENT_QUOTES, 'UTF-8');
        $offset = 18 + ($seed * 12);
        $midY = (int) round($height * 0.52);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#18120c"/>
      <stop offset="100%" stop-color="#100d09"/>
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#bg)"/>
  <g opacity="0.24" stroke="#3f3126" stroke-width="1">
    <path d="M0 {$midY}H{$width}"/>
    <path d="M0 {($midY - 78)}H{$width}"/>
    <path d="M0 {($midY + 76)}H{$width}"/>
    <path d="M{($width * 0.26)} 0V{$height}"/>
    <path d="M{($width * 0.52)} 0V{$height}"/>
    <path d="M{($width * 0.78)} 0V{$height}"/>
  </g>
  <path d="M70 {($midY + 42)} L210 {($midY + 5)} L320 {($midY - 26)} L430 {($midY - 4)} L560 {($midY - 82)} L700 {($midY - 68)} L840 {($midY - 128)} L980 {($midY - 112)} L1110 {($midY - 162)} L1250 {($midY - 138)}" fill="none" stroke="#f5f5f4" stroke-width="2"/>
  <rect x="{($width * 0.56)}" y="{($midY - 92 - $offset)}" width="{($width * 0.2)}" height="150" rx="12" fill="#22d3ee" fill-opacity="0.15"/>
  <text x="28" y="38" fill="#fef3c7" font-size="24" font-family="Manrope, sans-serif" font-weight="700">{$pairText}</text>
  <text x="28" y="70" fill="#fdba74" font-size="19" font-family="Manrope, sans-serif" font-weight="600">Missed: {$modelText}</text>
  <text x="28" y="101" fill="#fcd34d" font-size="16" font-family="JetBrains Mono, monospace" font-weight="700">No entry taken</text>
</svg>
SVG;
    }
}

