<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MissedTrade;
use App\Models\MissedTradeImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MissedTradeImageController extends Controller
{
    private const MAX_IMAGES_PER_ENTRY = 5;
    private const MAX_TOTAL_BYTES_PER_ENTRY = 20 * 1024 * 1024;
    private const MAX_FILE_KB = 5 * 1024;

    /**
     * @throws ValidationException
     */
    public function store(Request $request, MissedTrade $missedTrade)
    {
        $this->authorize('update', $missedTrade);

        if (!Schema::hasTable('missed_trade_images')) {
            return response()->json([
                'message' => 'Missed trade image storage is not ready. Run database migrations first.',
            ], 503);
        }

        $validated = Validator::make($request->all(), [
            'image' => ['required', 'file', 'max:' . self::MAX_FILE_KB, 'mimes:jpg,jpeg,png,webp,bmp'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $file = $request->file('image');
        if (!$file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'image' => ['Image upload is required.'],
            ]);
        }
        $incomingBytes = max(0, (int) $file->getSize());

        $disk = (string) config('filesystems.trade_images_disk', 'public');
        $extension = $this->normalizedExtension($file);
        $fileName = (string) Str::uuid() . '.' . $extension;
        $originalPath = "missed-trades/{$missedTrade->id}/original/{$fileName}";
        $thumbnailPath = "missed-trades/{$missedTrade->id}/thumbs/{$fileName}";
        $image = null;
        $storedOriginal = false;
        $storedThumbnail = false;

        try {
            DB::transaction(function () use (
                $missedTrade,
                $file,
                $incomingBytes,
                $disk,
                $fileName,
                $originalPath,
                $thumbnailPath,
                $extension,
                $validated,
                &$image,
                &$storedOriginal,
                &$storedThumbnail
            ): void {
                /** @var MissedTrade $lockedMissedTrade */
                $lockedMissedTrade = MissedTrade::query()
                    ->whereKey((int) $missedTrade->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingCount = (int) $lockedMissedTrade->images()->count();
                if ($existingCount >= self::MAX_IMAGES_PER_ENTRY) {
                    throw ValidationException::withMessages([
                        'image' => ['Maximum 5 images per missed trade allowed.'],
                    ]);
                }

                $existingBytes = (int) $lockedMissedTrade->images()->sum('file_size');
                if (($existingBytes + $incomingBytes) > self::MAX_TOTAL_BYTES_PER_ENTRY) {
                    throw ValidationException::withMessages([
                        'image' => ['Total image uploads per missed trade cannot exceed 20MB.'],
                    ]);
                }

                Storage::disk($disk)->putFileAs(
                    "missed-trades/{$lockedMissedTrade->id}/original",
                    $file,
                    $fileName
                );
                $storedOriginal = true;

                $this->generateThumbnail($file, $disk, $originalPath, $thumbnailPath, $extension);
                $storedThumbnail = true;

                $sortOrder = array_key_exists('sort_order', $validated)
                    ? (int) $validated['sort_order']
                    : ((int) ($lockedMissedTrade->images()->max('sort_order') ?? 0) + 1);

                $image = $lockedMissedTrade->images()->create([
                    'image_url' => $originalPath,
                    'thumbnail_url' => $thumbnailPath,
                    'file_size' => $incomingBytes,
                    'file_type' => (string) $file->getMimeType(),
                    'sort_order' => $sortOrder,
                ]);
            });
        } catch (ValidationException $exception) {
            if ($storedOriginal || $storedThumbnail) {
                Storage::disk($disk)->delete([$originalPath, $thumbnailPath]);
            }
            throw $exception;
        } catch (Throwable $throwable) {
            if ($storedOriginal || $storedThumbnail) {
                Storage::disk($disk)->delete([$originalPath, $thumbnailPath]);
            }
            throw $throwable;
        }

        abort_if(!$image instanceof MissedTradeImage, 500, 'Image mutation completed without persisted image row.');
        return response()->json($this->serializeMissedTradeImage($image, $disk), 201);
    }

    public function destroy(MissedTradeImage $missedTradeImage)
    {
        $missedTrade = $missedTradeImage->missedTrade()->firstOrFail();
        $this->authorize('delete', $missedTrade);

        $disk = (string) config('filesystems.trade_images_disk', 'public');

        Storage::disk($disk)->delete([
            $missedTradeImage->image_url,
            $missedTradeImage->thumbnail_url,
        ]);

        $missedTradeImage->delete();

        return response()->noContent();
    }

    private function normalizedExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }

        return match ($extension) {
            'jpeg' => 'jpg',
            'jpg', 'png', 'webp', 'bmp' => $extension,
            default => 'jpg',
        };
    }

    private function generateThumbnail(
        UploadedFile $file,
        string $disk,
        string $originalPath,
        string $thumbnailPath,
        string $extension
    ): void {
        $sourcePath = $file->getRealPath();
        if (!is_string($sourcePath) || $sourcePath === '') {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        if (!$this->canUseGdForResize()) {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        $imageInfo = @getimagesize($sourcePath);
        if (!is_array($imageInfo) || count($imageInfo) < 3) {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        [$width, $height, $imageType] = $imageInfo;
        if ($width <= 0 || $height <= 0) {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        $targetWidth = min(300, (int) $width);
        $targetHeight = max(1, (int) round(($targetWidth / (int) $width) * (int) $height));

        $sourceImage = $this->createImageResource($sourcePath, (int) $imageType);
        if ($sourceImage === null) {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        $thumbImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($thumbImage === false) {
            imagedestroy($sourceImage);
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        if ($extension === 'png' || $extension === 'webp') {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
            $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
            imagefilledrectangle($thumbImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $thumbImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            (int) $width,
            (int) $height
        );

        $imageBytes = $this->encodeImageResource($thumbImage, $extension);
        imagedestroy($thumbImage);
        imagedestroy($sourceImage);

        if ($imageBytes === null) {
            Storage::disk($disk)->copy($originalPath, $thumbnailPath);
            return;
        }

        Storage::disk($disk)->put($thumbnailPath, $imageBytes);
    }

    private function canUseGdForResize(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagejpeg');
    }

    private function createImageResource(string $sourcePath, int $imageType): mixed
    {
        if ($imageType === IMAGETYPE_JPEG) {
            return @imagecreatefromjpeg($sourcePath);
        }

        if ($imageType === IMAGETYPE_PNG) {
            return @imagecreatefrompng($sourcePath);
        }

        if (defined('IMAGETYPE_WEBP') && $imageType === IMAGETYPE_WEBP) {
            return function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($sourcePath)
                : null;
        }

        return null;
    }

    private function encodeImageResource(mixed $image, string $extension): ?string
    {
        ob_start();

        $result = match ($extension) {
            'png' => imagepng($image, null, 6),
            'webp' => function_exists('imagewebp')
                ? imagewebp($image, null, 82)
                : imagejpeg($image, null, 82),
            default => imagejpeg($image, null, 82),
        };

        $bytes = ob_get_clean();
        if ($result === false || !is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
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
        // Always prefer relative /storage URLs for local disks so frontend host/proxy
        // differences (e.g. Vite 5173, Docker service hostnames) do not break images.
        $relativeFromPath = $this->extractStoragePath($path);
        if ($relativeFromPath !== null) {
            return $relativeFromPath;
        }

        $url = Storage::disk($disk)->url($path);
        $relativeFromUrl = $this->extractStoragePath($url);
        if ($relativeFromUrl !== null) {
            return $relativeFromUrl;
        }

        return $url;
    }

    private function extractStoragePath(string $value): ?string
    {
        if (str_starts_with($value, '/storage/')) {
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return null;
        }

        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $path . $query;
    }
}
