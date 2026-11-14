<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\ProductAssistant;
use App\Domain\ProductRequests\RequestInput;
use App\Domain\Search\ChoiceAssistant;
use App\Domain\Search\PhotoIndexer;
use App\Domain\Search\PhotoSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Photo search, choice assistant, comparator and the product form assistant. No financial action here. */
final class SmartSearchController extends OperationalController
{
    private function filters(Request $http): array
    {
        return $http->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);
    }

    public function photo(Request $http, PhotoSearch $search)
    {
        $http->validate(['file' => ['required', 'file']]);

        return response()->json(['data' => $search->search($http, $http->file('file'), $this->filters($http))], 201);
    }

    public function photoResults(Request $http, string $id, PhotoSearch $search)
    {
        return ['data' => $search->show($http, $id, $this->filters($http))];
    }

    public function photoImage(Request $http, string $id, PhotoSearch $search)
    {
        return $search->file($http, $id);
    }

    public function photoRequest(Request $http, string $id, PhotoSearch $search)
    {
        $data = $http->validate([
            'consent' => ['required', 'accepted'],
            'locale' => ['required', 'in:fr,en'],
            'details' => ['present', 'array:name,description,brand,reference,variant,quantity,budget,reference_url'],
        ]);
        $data = ['locale' => $data['locale'], 'details' => app(RequestInput::class)->details($data['details'])];

        return response()->json(['data' => $search->toRequest($http, $id, $data)], 201);
    }

    public function questions(string $slug, ChoiceAssistant $assistant)
    {
        return ['data' => $assistant->questions($slug)];
    }

    public function recommend(Request $http, ChoiceAssistant $assistant)
    {
        $data = $http->validate([
            'category' => ['required', 'string', 'max:120'],
            'budget' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'answers' => ['nullable', 'array', 'max:20'],
        ]);

        return ['data' => $assistant->recommend($data)];
    }

    public function compare(Request $http, ChoiceAssistant $assistant)
    {
        $data = $http->validate([
            'offers' => ['required', 'array', 'min:2', 'max:4'],
            'offers.*' => ['uuid', 'distinct'],
        ]);

        return ['data' => $assistant->compare(array_values($data['offers']))];
    }

    /** Proposal only; the provider is called outside any transaction. Unavailable AI answers 503, manual entry stays. */
    public function assist(Request $http, string $id, ProductAssistant $assistant)
    {
        $data = $http->validate([
            'fields' => ['required', 'array', 'min:1', 'max:5'],
            'fields.*' => ['in:'.implode(',', ProductAssistant::FIELDS), 'distinct'],
            'note' => ['nullable', 'string', 'max:500'],
            'supplier_text' => ['nullable', 'string', 'max:8000'],
        ]);
        try {
            return response()->json(['data' => $assistant->propose($id, $data, $http->user())], 201);
        } catch (AiUnavailable $e) {
            return response()->json(
                ['error' => ['code' => 'AI_UNAVAILABLE', 'reason' => $e->reason, 'message' => __('ai.UNAVAILABLE')]],
                503,
            );
        }
    }

    public function suggestions(Request $http, string $id, ProductAssistant $assistant)
    {
        abort_unless(DB::table('products')->where('id', $id)->exists(), 404);

        return $this->page(
            DB::table('ai_suggestions')->where('product_id', $id)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($s) => $assistant->dto($s),
        );
    }

    public function apply(Request $http, string $id, ProductAssistant $assistant)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'accept' => ['required', 'array', 'min:1', 'max:100'],
            'accept.*' => ['string', 'max:120', 'distinct'],
            'edits' => ['nullable', 'array', 'max:100'],
            'variant_ids' => ['nullable', 'array', 'max:50'],
            'variant_ids.*' => ['uuid'],
        ]);

        return $this->command($http, 'ai.apply:'.$id, $data, fn ($d) => $assistant->apply($id, $d, $http->user()));
    }

    public function discard(Request $http, string $id, ProductAssistant $assistant)
    {
        return $this->command($http, 'ai.discard:'.$id, [], fn () => $assistant->discard($id, $http->user()));
    }

    public function usage(AiGate $gate)
    {
        return ['data' => $gate->summary()];
    }

    public function indexStatus(PhotoIndexer $indexer)
    {
        return ['data' => $indexer->status()];
    }

    public function reindex(Request $http, PhotoIndexer $indexer)
    {
        return $this->command($http, 'search.reindex_photos', [], fn () => ['queued' => $indexer->reindexAll()]);
    }
}
