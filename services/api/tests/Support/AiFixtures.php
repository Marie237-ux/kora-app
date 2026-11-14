<?php

namespace Tests\Support;

use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\EmbeddingProvider;
use App\Domain\Ai\TextGenerator;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scripted AI providers: nothing leaves the machine. Fake vectors are the average colour of an image (first
 * three dimensions) or a separate fourth dimension for text, so similarities are predictable.
 */
trait AiFixtures
{
    protected FakeEmbeddings $embeddings;

    protected FakeGenerator $generator;

    protected function useFakeAi(bool $ready = true): void
    {
        $this->embeddings = new FakeEmbeddings;
        $this->generator = new FakeGenerator;
        $this->app->instance(EmbeddingProvider::class, $this->embeddings);
        $this->app->instance(TextGenerator::class, $this->generator);
        config([
            'kora.ai.monthly_budget_usd' => $ready ? 5 : 0,
            'kora.ai.prices' => ['generator_input' => 3, 'generator_output' => 15, 'embeddings' => 0.12],
        ]);
    }

    protected function png(int $r, int $g, int $b, int $size = 64): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    protected function photo(int $r, int $g, int $b): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', $this->png($r, $g, $b));
    }

    /** One more published offer in the category, with its own public image of one colour. */
    protected function colourOffer(
        array $ctx,
        array $rgb,
        array $attributes = [],
        int $price = 1000,
        string $availability = 'available',
        ?string $category = null,
        string $name = 'Article de test',
    ): array {
        $media = (string) Str::uuid();
        $bytes = $this->png(...$rgb);
        Storage::disk('local')->put('media/'.$media.'.webp', $bytes);
        DB::table('media_assets')->insert([
            'id' => $media,
            'uploaded_by' => User::factory()->create()->id,
            'disk' => 'local',
            'object_key' => 'media/'.$media.'.webp',
            'mime' => 'image/webp',
            'width' => 64,
            'height' => 64,
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'alt' => json_encode(['fr' => '', 'en' => '']),
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (string) Str::uuid();
        $variant = (string) Str::uuid();
        $offer = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $product,
            'category_id' => $category ?? $ctx['category'],
            'slug' => 'p-'.$product,
            'name' => json_encode(['fr' => $name, 'en' => $name.' (en)']),
            'description' => json_encode(['fr' => 'Description de test.', 'en' => 'Test description.']),
            'status' => 'published',
            'published_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('variants')->insert([
            'id' => $variant,
            'product_id' => $product,
            'sku' => 'TEST-'.$variant,
            'attributes' => json_encode((object) $attributes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offers')->insert([
            'id' => $offer,
            'variant_id' => $variant,
            'shop_id' => $ctx['shop'],
            'reference_price' => $price,
            'selling_price' => $price,
            'status' => 'published',
            'availability' => $availability,
            'warranty' => json_encode(['fr' => 'Garantie de test', 'en' => 'Test warranty']),
            'image_path' => '/api/v1/media/'.$media.'/public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('media', 'product', 'variant', 'offer');
    }
}

final class FakeEmbeddings implements EmbeddingProvider
{
    public int $calls = 0;

    public ?string $fail = null;

    public function name(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return true;
    }

    public function model(): string
    {
        return 'fake-colour-1';
    }

    public function embed(array $inputs, string $type): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new AiUnavailable($this->fail);
        }

        return [
            'vectors' => array_map(function ($input) {
                if (! isset($input['image'])) {
                    return [0, 0, 0, 1];
                }
                $image = imagecreatefromstring($input['image']);
                [$r, $g, $b, $n] = [0, 0, 0, 0];
                for ($x = 0; $x < imagesx($image); $x += 8) {
                    for ($y = 0; $y < imagesy($image); $y += 8) {
                        $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                        [$r, $g, $b, $n] = [$r + $c['red'], $g + $c['green'], $b + $c['blue'], $n + 1];
                    }
                }

                return [$r / $n, $g / $n, $b / $n, 0];
            }, $inputs),
            'units' => 100 * count($inputs),
        ];
    }
}

final class FakeGenerator implements TextGenerator
{
    public array $answer = [];

    public array $received = [];

    public int $calls = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return true;
    }

    public function model(): string
    {
        return 'fake-writer-1';
    }

    public function structured(string $system, array $content, string $tool, array $schema, int $maxTokens): array
    {
        $this->calls++;
        $this->received = compact('system', 'content', 'tool', 'schema');

        return ['data' => $this->answer, 'input_units' => 1000, 'output_units' => 500];
    }
}
