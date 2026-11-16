<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServerIconRequest extends FormRequest
{
    private const string PREFIX = 'data:image/png;base64,';

    /**
     * Minecraft shows a server's picture only when it is a PNG of exactly 64 by 64, so nothing
     * else is taken. The page scales what was chosen before it sends it.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return [
            // 40 kB of image, which base64 makes a third longer.
            'icon' => ['required', 'string', 'max:54000', 'starts_with:'.self::PREFIX, function (string $attribute, mixed $value, Closure $fail): void {
                $bytes = (string) base64_decode(substr((string) $value, strlen(self::PREFIX)), true);
                $size = @getimagesizefromstring($bytes);

                // The size is read from the start of the file; the end must be a PNG's own, so
                // nothing rides along behind the picture.
                if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] !== 64 || $size[1] !== 64 || ! str_ends_with($bytes, "IEND\xAE\x42\x60\x82")) {
                    $fail(__('The picture must be a PNG of 64 by 64 pixels.'));
                }
            }],
        ];
    }

    /**
     * The picture's bytes.
     */
    public function png(): string
    {
        return (string) base64_decode(substr((string) $this->validated('icon'), strlen(self::PREFIX)), true);
    }
}
