<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MissedTrade;
use App\Models\MissedTradeImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MissedTradeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));
        $disk = (string) config('filesystems.trade_images_disk', 'public');
        $imageTableExists = Schema::hasTable('missed_trade_images');

        $query = MissedTrade::query()
            ->applyFilters($request->only([
                'pair',
                'model',
                'reason',
                'date_from',
                'date_to',
            ]))
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($imageTableExists) {
            $query
                ->with([
                    'images' => fn ($builder) => $builder
                        ->select(['id', 'missed_trade_id', 'image_url', 'thumbnail_url', 'file_size', 'file_type', 'sort_order'])
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->withCount('images');
        }

        $missedTrades = $query->paginate($perPage);

        $missedTrades->getCollection()->transform(function (MissedTrade $missedTrade) use ($disk, $imageTableExists): MissedTrade {
            if (!$imageTableExists) {
                $missedTrade->setAttribute('images_count', 0);
                $missedTrade->setRelation('images', collect());
                return $missedTrade;
            }

            $serialized = $missedTrade->images->map(
                fn (MissedTradeImage $image): array => $this->serializeMissedTradeImage($image, $disk)
            )->values();
            $missedTrade->setRelation('images', $serialized);
            return $missedTrade;
        });

        return response()->json($missedTrades);
    }

    public function store(Request $request)
    {
        $missedTrade = MissedTrade::create($this->validatePayload($request));

        return response()->json($missedTrade, 201);
    }

    public function show(MissedTrade $missedTrade)
    {
        if (!Schema::hasTable('missed_trade_images')) {
            $missedTrade->setAttribute('images_count', 0);
            $missedTrade->setRelation('images', collect());
            return response()->json($missedTrade);
        }

        $missedTrade->load([
            'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $disk = (string) config('filesystems.trade_images_disk', 'public');
        $serialized = $missedTrade->images->map(
            fn (MissedTradeImage $image): array => $this->serializeMissedTradeImage($image, $disk)
        )->values();

        $missedTrade->setRelation('images', $serialized);

        return response()->json($missedTrade);
    }

    public function update(Request $request, MissedTrade $missedTrade)
    {
        $missedTrade->update($this->validatePayload($request, true));

        return response()->json($missedTrade->fresh());
    }

    public function destroy(MissedTrade $missedTrade)
    {
        $missedTrade->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'pair' => [$required, 'string', 'max:30'],
            'model' => [$required, 'string', 'max:120'],
            'reason' => [$required, 'string', 'max:255'],
            'date' => [$required, 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array{id:int,image_url:string,thumbnail_url:string,file_size:int,file_type:string,sort_order:int}
     */
    private function serializeMissedTradeImage(MissedTradeImage $image, string $disk): array
    {
        return [
            'id' => (int) $image->id,
            'image_url' => $this->storageUrl($image->image_url, $disk),
            'thumbnail_url' => $this->storageUrl($image->thumbnail_url, $disk),
            'file_size' => (int) $image->file_size,
            'file_type' => (string) $image->file_type,
            'sort_order' => (int) $image->sort_order,
        ];
    }

    private function storageUrl(string $path, string $disk): string
    {
        $requestBase = rtrim((string) (request()?->getSchemeAndHttpHost() ?: config('app.url')), '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->normalizeLocalStorageUrl($path, $requestBase);
        }

        $url = Storage::disk($disk)->url($path);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $this->normalizeLocalStorageUrl($url, $requestBase);
        }

        if (str_starts_with($url, '/')) {
            return $requestBase . $url;
        }

        return $requestBase . '/' . ltrim($url, '/');
    }

    private function normalizeLocalStorageUrl(string $url, string $requestBase): string
    {
        if ($requestBase === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return $url;
        }

        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $requestBase . $path . $query;
    }
}
