<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class BrandingFavicon implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Choose a valid favicon image.');

            return;
        }

        $contents = $value->get();
        if ($contents === false || $contents === '') {
            $fail('Choose a readable favicon image.');

            return;
        }

        if (str_starts_with($contents, "\x00\x00\x01\x00")) {
            $header = strlen($contents) >= 6 ? unpack('vcount', substr($contents, 4, 2)) : false;
            $count = $header === false ? 0 : $header['count'];
            if ($count < 1 || $count > 256 || strlen($contents) < 6 + $count * 16) {
                $fail('Choose a valid ICO file.');

                return;
            }
            for ($index = 0; $index < $count; $index++) {
                $entry = unpack('Vsize/Voffset', substr($contents, 6 + $index * 16 + 8, 8));
                if ($entry === false || $entry['size'] < 1 || $entry['offset'] < 6 + $count * 16 || strlen($contents) < $entry['offset'] + $entry['size']) {
                    $fail('Choose a valid ICO file.');

                    return;
                }
                $width = ord($contents[6 + $index * 16]) ?: 256;
                $height = ord($contents[7 + $index * 16]) ?: 256;
                if (! $this->validIconImage(substr($contents, $entry['offset'], $entry['size']), $width, $height)) {
                    $fail('Each ICO image must contain valid pixels matching its declared dimensions.');

                    return;
                }
            }

            return;
        }

        $dimensions = @getimagesizefromstring($contents);
        if ($dimensions === false || $dimensions[0] > 512 || $dimensions[1] > 512) {
            $fail('The favicon must be a valid image no larger than 512 × 512 pixels.');
        }
    }

    private function validIconImage(string $contents, int $width, int $height): bool
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            $dimensions = @getimagesizefromstring($contents);

            return $dimensions !== false && $dimensions[0] === $width && $dimensions[1] === $height;
        }

        if (strlen($contents) < 40) {
            return false;
        }

        $bitmap = unpack('Vheader/Vwidth/Vheight/vplanes/vbits/Vcompression/Vsize/Vx/Vy/Vcolors', substr($contents, 0, 36));
        if ($bitmap === false || ! in_array($bitmap['header'], [40, 108, 124], true)
            || $bitmap['width'] !== $width || $bitmap['height'] !== $height * 2
            || $bitmap['planes'] !== 1 || ! in_array($bitmap['bits'], [1, 4, 8, 16, 24, 32], true)
            || $bitmap['compression'] !== 0) {
            return false;
        }

        $colors = $bitmap['bits'] <= 8 ? ($bitmap['colors'] ?: 2 ** $bitmap['bits']) : $bitmap['colors'];
        $pixelBytes = intdiv($width * $bitmap['bits'] + 31, 32) * 4 * $height;
        $maskBytes = intdiv($width + 31, 32) * 4 * $height;

        return strlen($contents) >= $bitmap['header'] + $colors * 4 + $pixelBytes + $maskBytes;
    }
}
