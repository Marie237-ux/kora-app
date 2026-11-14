<?php

namespace App\Http\Controllers;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Messaging\Conversations;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConversationsController extends OperationalController
{
    public function mine(Request $http, Conversations $conversations)
    {
        return $this->page(
            DB::table('conversations')->where('user_id', $http->user()->id)->orderByDesc('updated_at')->orderBy('id'),
            $http,
            fn ($c) => $conversations->dto($c, false),
        );
    }

    public function open(Request $http, Conversations $conversations)
    {
        $data = $http->validate([
            'subject' => ['required', 'string', 'max:150'],
            'context_type' => ['required', 'in:general,order,product,after_sales'],
            'context_id' => ['nullable', 'uuid', 'required_unless:context_type,general'],
            'body' => ['required', 'string', 'max:4000'],
            'client_ref' => ['required', 'string', 'max:64'],
        ]);

        return $this->command(
            $http,
            'conversation.open',
            $data,
            fn ($d) => $conversations->open($http->user(), $d),
            201,
        );
    }

    public function messages(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $c = $conversations->owned($id, $http->user());

        return [
            'data' => $conversations->messages($c, (int) ($d['after'] ?? 0), false),
            'meta' => $conversations->dto($c, false),
        ];
    }

    public function send(Request $http, string $id, Conversations $conversations)
    {
        $data = $http->validate([
            'body' => ['required', 'string', 'max:4000'],
            'client_ref' => ['required', 'string', 'max:64'],
        ]);

        return response()->json(
            [
                'data' => DB::transaction(
                    fn () => $conversations->send(
                        $conversations->owned($id, $http->user(), true),
                        $http->user(),
                        'customer',
                        'public',
                        $data['body'],
                        null,
                        $data['client_ref'],
                    ),
                    3,
                ),
            ],
            201,
        );
    }

    public function attach(Request $http, string $id, Conversations $conversations)
    {
        $data = $http->validate([
            'file' => ['required', 'file', 'max:10240'],
            'client_ref' => ['required', 'string', 'max:64'],
        ]);
        $c = $conversations->owned($id, $http->user());

        return response()->json(
            ['data' => $conversations->attach($c, $http->user(), 'customer', $data['file'], $data['client_ref'])],
            201,
        );
    }

    public function read(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['seq' => ['required', 'integer', 'min:0']]);
        $conversations->read($conversations->owned($id, $http->user()), $d['seq'], false);

        return ['data' => $conversations->dto($conversations->owned($id, $http->user()), false)];
    }

    /** A customer opens the attachments of their own conversation's public messages only. */
    public function attachment(Request $http, string $id, string $messageId, Conversations $conversations)
    {
        $conversations->owned($id, $http->user());
        $m = DB::table('conversation_messages')
            ->where('id', $messageId)
            ->where('conversation_id', $id)
            ->where('visibility', 'public')
            ->first();
        abort_unless($m && $m->document_id, 404);

        return app(PrivateDocuments::class)->download(
            Records::row('private_documents', $m->document_id, false),
            $http->user(),
            true,
        );
    }

    public function whatsapp(Request $http, Conversations $conversations)
    {
        $d = $http->validate(['reference' => ['nullable', 'string', 'max:40'], 'consent' => ['required', 'accepted']]);

        return ['data' => $conversations->whatsapp($http->user(), $d['reference'] ?? null)];
    }

    public function inbox(Request $http, Conversations $conversations)
    {
        $d = $http->validate(['status' => ['nullable', 'in:open,closed'], 'assignee_id' => ['nullable', 'integer']]);
        $q = DB::table('conversations')->orderByDesc('updated_at')->orderBy('id');
        foreach (['status', 'assignee_id'] as $f) {
            if (isset($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }

        return $this->page($q, $http, fn ($c) => $conversations->dto($c, true));
    }

    public function staffMessages(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $c = Records::row('conversations', $id, false);

        return [
            'data' => $conversations->messages($c, (int) ($d['after'] ?? 0), true),
            'meta' => $conversations->dto($c, true),
        ];
    }

    public function staffSend(Request $http, string $id, Conversations $conversations)
    {
        $data = $http->validate([
            'body' => ['required_without:quick_reply_id', 'nullable', 'string', 'max:4000'],
            'quick_reply_id' => ['nullable', 'uuid', 'exists:quick_replies,id'],
            'visibility' => ['required', 'in:public,internal'],
            'client_ref' => ['required', 'string', 'max:64'],
        ]);
        $body = $data['body'] ?? null;
        if (! $body) {
            $c = Records::row('conversations', $id, false);
            $locale = DB::table('users')->where('id', $c->user_id)->value('locale') === 'en' ? 'en' : 'fr';
            $body = json_decode(DB::table('quick_replies')->where('id', $data['quick_reply_id'])->value('body'), true)[
                $locale
            ];
        }

        return response()->json(
            [
                'data' => DB::transaction(
                    fn () => $conversations->send(
                        Records::row('conversations', $id),
                        $http->user(),
                        'staff',
                        $data['visibility'],
                        $body,
                        null,
                        $data['client_ref'],
                    ),
                    3,
                ),
            ],
            201,
        );
    }

    public function staffRead(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['seq' => ['required', 'integer', 'min:0']]);
        $conversations->read(Records::row('conversations', $id, false), $d['seq'], true);

        return ['data' => $conversations->dto(Records::row('conversations', $id, false), true)];
    }

    public function staffAttachment(Request $http, string $id, string $messageId)
    {
        $m = DB::table('conversation_messages')->where('id', $messageId)->where('conversation_id', $id)->first();
        abort_unless($m && $m->document_id, 404);

        return app(PrivateDocuments::class)->download(
            Records::row('private_documents', $m->document_id, false),
            $http->user(),
        );
    }

    public function assign(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['assignee_id' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'conversation.assign:'.$id,
            $d,
            fn ($x) => $conversations->assign($id, $x['assignee_id'], $http->user()),
        );
    }

    public function status(Request $http, string $id, Conversations $conversations)
    {
        $d = $http->validate(['status' => ['required', 'in:open,closed']]);

        return $this->command(
            $http,
            'conversation.status:'.$id,
            $d,
            fn ($x) => $conversations->status($id, $x['status'], $http->user()),
        );
    }

    public function convert(Request $http, string $id, Conversations $conversations)
    {
        $data = $http->validate([
            'order_line_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:missing,damaged,non_conforming,breakdown,other'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'identifiers' => ['present', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100'],
            'description' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'conversation.convert:'.$id,
            $data,
            fn ($d) => $conversations->convert($id, $d, $http->user()),
        );
    }

    public function quickReplies(Request $http)
    {
        return [
            'data' => DB::table('quick_replies')
                ->where('active', true)
                ->orderBy('title')
                ->get()
                ->map(fn ($q) => ['id' => $q->id, 'title' => $q->title, 'body' => json_decode($q->body, true)])
                ->values(),
        ];
    }

    public function createQuickReply(Request $http)
    {
        $d = $http->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'array:fr,en'],
            'body.fr' => ['required', 'string', 'max:2000'],
            'body.en' => ['required', 'string', 'max:2000'],
        ]);
        $id = Records::id();
        DB::table('quick_replies')->insert([
            'id' => $id,
            'title' => $d['title'],
            'body' => Records::json($d['body']),
            'created_by' => $http->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id, 'title' => $d['title'], 'body' => $d['body']]], 201);
    }
}
