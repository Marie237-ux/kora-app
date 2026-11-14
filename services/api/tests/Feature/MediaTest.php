<?php

namespace Tests\Feature;

use App\Domain\Media\MediaLibrary;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'catalogue'): User
    {
        $user = User::factory()->create(['roles' => [$role], 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user)
            ->withSession(['mfa_verified' => true])
            ->withHeaders(['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);

        return $user;
    }

    public function test_images_are_reencoded_stored_privately_and_publication_requires_rights_confirmation(): void
    {
        Storage::fake('local');
        $this->staff();
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('untrusted-name.png', 200, 300),
            'alt' => ['fr' => 'Photo test', 'en' => 'Test photo'],
        ])
            ->assertCreated()
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.object_key')
            ->json('data');
        $row = DB::table('media_assets')->find($media['id']);
        $bytes = Storage::disk('local')->get($row->object_key);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $this->assertStringNotContainsString('untrusted-name', $row->object_key);
        $this->get('/api/v1/media/'.$media['id'].'/public')->assertNotFound();
        $this->get($media['url'])->assertOk();
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 1,
            'is_public' => true,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 1,
            'is_public' => true,
            'rights_confirmed' => true,
        ])->assertOk();
        $this->get('/api/v1/media/'.$media['id'].'/public')->assertOk();
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 2,
            'is_public' => false,
        ])->assertOk();
        $this->get('/api/v1/media/'.$media['id'].'/public')->assertNotFound();
    }

    public function test_other_staff_cannot_read_or_publish_a_private_upload(): void
    {
        Storage::fake('local');
        $this->staff();
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('fixture.png'),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])
            ->assertCreated()
            ->json('data');
        $this->staff();
        $this->get($media['url'])->assertForbidden();
        $this->getJson('/api/v1/operations/media')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 1,
            'is_public' => true,
            'rights_confirmed' => true,
        ])->assertForbidden();
    }

    public function test_executable_svg_and_oversized_images_are_rejected_without_a_record(): void
    {
        Storage::fake('local');
        $this->staff();
        foreach (
            [
                UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
                UploadedFile::fake()->createWithContent(
                    'payload.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
                UploadedFile::fake()->create('too-large.png', 2049, 'image/png'),
            ] as $file
        ) {
            $this->post('/api/v1/operations/media', [
                'file' => $file,
                'alt' => ['fr' => 'Test', 'en' => 'Test'],
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_a_scene_cannot_publish_a_private_image_or_an_unregistered_static_path(): void
    {
        Storage::fake('local');
        $this->staff();
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('fixture.png'),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])
            ->assertCreated()
            ->json('data');
        $library = app(MediaLibrary::class);
        $this->assertFalse($library->approvedPath('/api/v1/media/'.$media['id'].'/public'));
        $this->assertFalse($library->approvedPath('/media/not-registered.png'));
        $this->assertTrue($library->approvedPath('/brand/logo.png'));
    }

    public function test_orientation_metadata_and_transparency_are_normalized_before_storage(): void
    {
        Storage::fake('local');
        $this->staff();
        $source = imagecreatetruecolor(20, 10);
        imagefill($source, 0, 0, imagecolorallocate($source, 200, 40, 40));
        ob_start();
        imagejpeg($source);
        $jpeg = ob_get_clean();
        imagedestroy($source);
        $tiff = "II\x2a\0".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $comment = 'PRIVATE-GPS-FIXTURE';
        $jpeg =
            substr($jpeg, 0, 2).
            "\xff\xe1".
            pack('n', strlen($exif) + 2).
            $exif.
            "\xff\xfe".
            pack('n', strlen($comment) + 2).
            $comment.
            substr($jpeg, 2);
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->createWithContent('fixture.jpg', $jpeg),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.width', 10)
            ->assertJsonPath('data.height', 20)
            ->json('data');
        $stored = Storage::disk('local')->get(DB::table('media_assets')->find($media['id'])->object_key);
        $this->assertStringNotContainsString($comment, $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $source = imagecreatetruecolor(3000, 1500);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->createWithContent('transparent.png', $png),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.width', 2400)
            ->assertJsonPath('data.height', 1200)
            ->json('data');
        $image = imagecreatefromstring(
            Storage::disk('local')->get(DB::table('media_assets')->find($media['id'])->object_key),
        );
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 127);
        imagedestroy($image);
    }

    public function test_scene_media_uses_the_registry_and_withdrawing_it_removes_the_public_scene(): void
    {
        Storage::fake('local');
        $this->staff();
        $media = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('fixture.png'),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])
            ->assertCreated()
            ->json('data');
        $draft = [
            'kind' => 'campaign',
            'target_id' => null,
            'category' => null,
            'title' => ['fr' => 'Test', 'en' => 'Test'],
            'description' => ['fr' => 'Test', 'en' => 'Test'],
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'cta' => ['fr' => 'Test', 'en' => 'Test'],
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
            'media' => '/api/v1/media/'.$media['id'].'/public',
            'mobile_media' => null,
            'decor' => 'circle',
            'preset' => 'signature',
            'hold_ms' => 8000,
            'autoplay' => false,
            'starts_at' => null,
            'ends_at' => null,
            'priority' => 0,
        ];
        $scene = $this->postJson('/api/v1/operations/content/scenes', ['name' => 'Fixture', 'draft' => $draft])
            ->assertCreated()
            ->json('data');
        $this->postJson('/api/v1/operations/content/scenes/'.$scene['id'].'/publish', [
            'version' => $scene['version'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 1,
            'is_public' => true,
            'rights_confirmed' => true,
        ])->assertOk();
        $this->postJson('/api/v1/operations/content/scenes/'.$scene['id'].'/publish', [
            'version' => $scene['version'],
        ])->assertOk();
        $this->getJson('/api/v1/content/hero')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media', $draft['media']);
        $this->postJson('/api/v1/operations/media/'.$media['id'].'/visibility', [
            'version' => 2,
            'is_public' => false,
        ])->assertOk();
        $this->getJson('/api/v1/content/hero')->assertJsonCount(0, 'data');
    }

    public function test_excessive_dimensions_are_rejected_and_failed_database_write_cleans_its_object(): void
    {
        Storage::fake('local');
        $this->staff();
        foreach ([[4097, 1], [3000, 3000]] as [$width, $height]) {
            $this->post('/api/v1/operations/media', [
                'file' => UploadedFile::fake()->image('fixture.png', $width, $height),
                'alt' => ['fr' => 'Test', 'en' => 'Test'],
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, 'audit_events')) {
                throw new \RuntimeException('Fixture DB write failure');
            }
        });
        $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('fixture.png'),
            'alt' => ['fr' => 'Test', 'en' => 'Test'],
        ])->assertStatus(500);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
