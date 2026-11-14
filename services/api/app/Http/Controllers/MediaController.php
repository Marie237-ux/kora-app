<?php

namespace App\Http\Controllers;

use App\Domain\Media\MediaLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaController extends Controller
{
    public function index(Request $request, MediaLibrary $library)
    {
        $query = DB::table('media_assets')->orderByDesc('created_at')->orderBy('id');
        if (! in_array('owner', $request->user()->roles ?? [], true)) {
            $query->where(fn ($q) => $q->where('uploaded_by', $request->user()->id)->orWhere('is_public', true));
        }
        $page = $query->paginate(24);

        return [
            'data' => collect($page->items())->map(fn ($row) => $library->dto($row)),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    public function store(Request $request, MediaLibrary $library)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'alt' => ['required', 'array:fr,en'],
            'alt.fr' => ['required', 'string', 'max:160'],
            'alt.en' => ['required', 'string', 'max:160'],
        ]);

        return response()->json(['data' => $library->upload($data['file'], $data['alt'], $request->user())], 201);
    }

    public function visibility(Request $request, string $id, MediaLibrary $library)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'is_public' => ['required', 'boolean'],
            'rights_confirmed' => ['required_if:is_public,true', 'accepted_if:is_public,true'],
        ]);

        return ['data' => $library->setPublic($id, $data['version'], $data['is_public'], $request->user())];
    }

    public function privateFile(Request $request, string $id, MediaLibrary $library)
    {
        $media = DB::table('media_assets')->find($id);
        abort_unless($media, 404);
        abort_unless($library->canRead($media, $request->user()), 403);

        return $library->file($media);
    }

    public function publicFile(string $id, MediaLibrary $library)
    {
        $media = DB::table('media_assets')
            ->where('id', $id)
            ->where('is_public', true)
            ->where('revoked', false)
            ->first();
        abort_unless($media, 404);

        return $library->file($media);
    }
}
