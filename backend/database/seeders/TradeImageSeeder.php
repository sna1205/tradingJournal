<?php

namespace Database\Seeders;

use App\Models\Trade;
use App\Models\TradeImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TradeImageSeeder extends Seeder
{
    public function run(): void
    {
        $disk = (string) config('filesystems.trade_images_disk', 'public');
        Storage::disk($disk)->deleteDirectory('trades');

        $trades = Trade::query()
            ->orderByDesc('date')
            ->limit(48)
            ->get();

        foreach ($trades as $trade) {
            $imageCount = fake()->numberBetween(0, 2);

            for ($index = 0; $index < $imageCount; $index += 1) {
                $fileName = (string) Str::uuid() . '.svg';
                $originalPath = "trades/{$trade->id}/original/{$fileName}";
                $thumbPath = "trades/{$trade->id}/thumbs/{$fileName}";

                $svgOriginal = $this->renderMockChartSvg($trade->pair, (float) $trade->profit_loss, 1360, 760, $index);
                $svgThumb = $this->renderMockChartSvg($trade->pair, (float) $trade->profit_loss, 680, 380, $index);

                Storage::disk($disk)->put($originalPath, $svgOriginal);
                Storage::disk($disk)->put($thumbPath, $svgThumb);

                TradeImage::query()->create([
                    'trade_id' => (int) $trade->id,
                    'image_url' => $originalPath,
                    'thumbnail_url' => $thumbPath,
                    'file_size' => strlen($svgOriginal),
                    'file_type' => 'image/svg+xml',
                    'sort_order' => $index,
                ]);
            }
        }
    }

    private function renderMockChartSvg(string $pair, float $pnl, int $width, int $height, int $seed): string
    {
        $pairText = htmlspecialchars($pair, ENT_QUOTES, 'UTF-8');
        $pnlColor = $pnl >= 0 ? '#34d399' : '#f87171';
        $accent = $pnl >= 0 ? '#22c55e' : '#ef4444';
        $offset = 20 + ($seed * 16);
        $midY = (int) round($height * 0.53);
        $lineY = max(28, $midY - $offset);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#0d1118"/>
      <stop offset="100%" stop-color="#090e14"/>
    </linearGradient>
    <linearGradient id="zone" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.26"/>
      <stop offset="100%" stop-color="#38bdf8" stop-opacity="0.08"/>
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#bg)"/>
  <g opacity="0.22" stroke="#1f2937" stroke-width="1">
    <path d="M0 {$midY}H{$width}"/>
    <path d="M0 {$lineY}H{$width}"/>
    <path d="M0 {($midY + 64)}H{$width}"/>
    <path d="M{($width * 0.24)} 0V{$height}"/>
    <path d="M{($width * 0.5)} 0V{$height}"/>
    <path d="M{($width * 0.76)} 0V{$height}"/>
  </g>
  <rect x="{($width * 0.45)}" y="{($midY - 80)}" width="{($width * 0.2)}" height="140" rx="12" fill="url(#zone)"/>
  <path d="M80 {($midY + 20)} L220 {$midY} L330 {($midY - 46)} L460 {($midY - 30)} L560 {($midY - 115)} L710 {($midY - 104)} L840 {($midY - 160)} L970 {($midY - 132)} L1120 {($midY - 188)} L1260 {($midY - 172)}" fill="none" stroke="#e5e7eb" stroke-width="2"/>
  <path d="M80 {($midY + 20)} L1260 {($midY - 172)}" fill="none" stroke="#f43f5e" stroke-opacity="0.7" stroke-dasharray="8 8" stroke-width="1.4"/>
  <circle cx="560" cy="{($midY - 115)}" r="4.5" fill="{$accent}"/>
  <circle cx="970" cy="{($midY - 132)}" r="4.5" fill="{$accent}"/>
  <text x="28" y="38" fill="#f3f4f6" font-size="24" font-family="Manrope, sans-serif" font-weight="700">{$pairText}</text>
  <text x="28" y="68" fill="{$pnlColor}" font-size="20" font-family="JetBrains Mono, monospace" font-weight="700">P/L {$pnl}</text>
</svg>
SVG;
    }
}

