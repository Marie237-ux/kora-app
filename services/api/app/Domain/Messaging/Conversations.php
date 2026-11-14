<?php

namespace App\Domain\Messaging;

use App\Domain\AfterSales\AfterSales;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Notifications\Notifications;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer–support messaging (§26). Each conversation belongs to one customer; support sees all of them and
 * may add internal notes the customer never receives. Messages are numbered so a device fetches what follows
 * its last number, and a resent message (same client reference) is answered with the stored one.
 */
final class Conversations
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('messaging.'.$code)]);
    }

    public function open(User $customer, array $data): array
    {
        $this->checkContext($customer, $data['context_type'], $data['context_id'] ?? null);
        $id = Records::id();
        DB::table('conversations')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('conversation', 'MSG-', 7),
            'user_id' => $customer->id,
            'subject' => $data['subject'],
            'context_type' => $data['context_type'],
            'context_id' => $data['context_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->post(
            Records::row('conversations', $id),
            $customer,
            'customer',
            'public',
            $data['body'],
            null,
            $data['client_ref'],
        );

        return $this->dto(Records::row('conversations', $id, false), false);
    }

    /** The order, product or after-sales case a conversation refers to must be the customer's own. */
    private function checkContext(User $customer, string $type, ?string $id): void
    {
        $ok = match ($type) {
            'general' => true,
            'order' => DB::table('orders')->where('id', $id)->where('user_id', $customer->id)->exists(),
            'product' => DB::table('products')->where('id', $id)->exists(),
            'after_sales' => DB::table('after_sales_cases')
                ->where('id', $id)
                ->where('user_id', $customer->id)
                ->exists(),
        };
        if (! $ok) {
            self::fail('context_id', 'CONTEXT_INVALID');
        }
    }

    public function owned(string $id, User $customer, bool $lock = false): object
    {
        $c = Records::row('conversations', $id, $lock);
        abort_unless((int) $c->user_id === $customer->id, 404);

        return $c;
    }

    public function send(
        object $conversation,
        User $author,
        string $side,
        string $visibility,
        ?string $body,
        ?string $documentId,
        string $clientRef,
    ): array {
        $existing = DB::table('conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->where('author_id', $author->id)
            ->where('client_ref', $clientRef)
            ->first();
        if ($existing) {
            return $this->messageDto($existing, $side === 'staff');
        }
        $message = $this->post($conversation, $author, $side, $visibility, $body, $documentId, $clientRef);
        if ($side === 'staff' && $visibility === 'public') {
            // Message content stays out of a public notification preview.
            app(Notifications::class)->notify(
                (int) $conversation->user_id,
                'transactional',
                'message',
                'message:'.$message->id,
                [
                    'fr' => [
                        'Nouveau message de KORA',
                        'Le service client vous a répondu ('.$conversation->number.').',
                    ],
                    'en' => ['New message from KORA', 'Customer service replied ('.$conversation->number.').'],
                ],
                '/account/conversations/'.$conversation->id,
                true,
            );
        }

        return $this->messageDto($message, $side === 'staff');
    }

    private function post(
        object $conversation,
        User $author,
        string $side,
        string $visibility,
        ?string $body,
        ?string $documentId,
        string $clientRef,
    ): object {
        $seq = (int) $conversation->last_seq + 1;
        $id = Records::id();
        DB::table('conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversation->id,
            'seq' => $seq,
            'author_id' => $author->id,
            'side' => $side,
            'visibility' => $visibility,
            'body' => $body,
            'document_id' => $documentId,
            'client_ref' => $clientRef,
            'created_at' => now(),
        ]);
        DB::table('conversations')
            ->where('id', $conversation->id)
            ->update([
                'last_seq' => $seq,
                'status' => $side === 'customer' ? 'open' : $conversation->status,
                $side === 'customer' ? 'customer_read_seq' : 'staff_read_seq' => $seq,
                'updated_at' => now(),
            ]);

        return DB::table('conversation_messages')->where('id', $id)->first();
    }

    public function attach(
        object $conversation,
        User $author,
        string $side,
        UploadedFile $file,
        string $clientRef,
    ): array {
        $document = app(PrivateDocuments::class)->upload($file, 'message_attachment', null, $author, true);

        return DB::transaction(function () use ($conversation, $author, $side, $document, $clientRef) {
            $c = Records::row('conversations', $conversation->id);

            return $this->send($c, $author, $side, 'public', null, $document['id'], $clientRef);
        }, 3);
    }

    /** Messages after a number, oldest first; customers never receive internal notes. */
    public function messages(object $conversation, int $after, bool $staff): array
    {
        return DB::table('conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->where('seq', '>', $after)
            ->when(! $staff, fn ($q) => $q->where('visibility', 'public'))
            ->orderBy('seq')
            ->limit(100)
            ->get()
            ->map(fn ($m) => $this->messageDto($m, $staff))
            ->all();
    }

    public function read(object $conversation, int $seq, bool $staff): void
    {
        $column = $staff ? 'staff_read_seq' : 'customer_read_seq';
        DB::table('conversations')
            ->where('id', $conversation->id)
            ->where($column, '<', min($seq, (int) $conversation->last_seq))
            ->update([$column => min($seq, (int) $conversation->last_seq)]);
    }

    public function assign(string $id, int $assigneeId, User $actor): array
    {
        $c = Records::row('conversations', $id);
        $assignee = User::query()->find($assigneeId);
        if (! $assignee || ! in_array('support.write', $assignee->permissions(), true)) {
            self::fail('assignee_id', 'ASSIGNEE_INVALID');
        }
        DB::table('conversations')
            ->where('id', $id)
            ->update(['assignee_id' => $assigneeId, 'version' => $c->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'conversation.assigned', $id, ['assignee_id' => $assigneeId]);

        return $this->dto(Records::row('conversations', $id, false), true);
    }

    public function status(string $id, string $status, User $actor): array
    {
        $c = Records::row('conversations', $id);
        DB::table('conversations')
            ->where('id', $id)
            ->update(['status' => $status, 'version' => $c->version + 1, 'updated_at' => now()]);

        return $this->dto(Records::row('conversations', $id, false), true);
    }

    /** Support turns a conversation into a structured after-sales case for the customer's delivered line. */
    public function convert(string $id, array $data, User $actor): array
    {
        $c = Records::row('conversations', $id);
        if ($c->case_id) {
            self::fail('conversation', 'ALREADY_CONVERTED');
        }
        $customer = User::query()->findOrFail($c->user_id);
        $case = app(AfterSales::class)->open($data, $customer);
        DB::table('conversations')
            ->where('id', $id)
            ->update(['case_id' => $case['id'], 'version' => $c->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'conversation.converted', $id, ['case_id' => $case['id']]);

        return $this->dto(Records::row('conversations', $id, false), true);
    }

    /** Pre-filled WhatsApp link to the configured business number, only with the customer's consent to share. */
    public function whatsapp(User $customer, ?string $reference): array
    {
        $number = preg_replace('/\D+/', '', (string) config('kora.whatsapp_number'));
        if ($number === '') {
            return ['available' => false, 'url' => null];
        }
        $text = 'Bonjour KORA'.($reference ? ' — '.$reference : '');

        return [
            'available' => true,
            'url' => 'https://wa.me/'.$number.'?text='.rawurlencode($text),
            // The site cannot read a personal WhatsApp history; nothing is synchronised back.
            'history_synced' => false,
        ];
    }

    public function messageDto(object $m, bool $staff): array
    {
        return [
            'id' => $m->id,
            'seq' => (int) $m->seq,
            'side' => $m->side,
            ...$staff ? ['visibility' => $m->visibility, 'author_id' => (int) $m->author_id] : [],
            'body' => $m->body,
            'attachment' => $m->document_id !== null,
            'client_ref' => $m->client_ref,
            'created_at' => Records::iso($m->created_at),
        ];
    }

    public function dto(object $c, bool $staff): array
    {
        $publicLast = (int) DB::table('conversation_messages')
            ->where('conversation_id', $c->id)
            ->where('visibility', 'public')
            ->max('seq');

        return [
            'id' => $c->id,
            'number' => $c->number,
            'subject' => $c->subject,
            'context' => ['type' => $c->context_type, 'id' => $c->context_id],
            'status' => $c->status,
            'case_id' => $c->case_id,
            'last_seq' => $staff ? (int) $c->last_seq : $publicLast,
            'unread' => $staff
                ? (int) DB::table('conversation_messages')
                    ->where('conversation_id', $c->id)
                    ->where('side', 'customer')
                    ->where('seq', '>', $c->staff_read_seq)
                    ->count()
                : (int) DB::table('conversation_messages')
                    ->where('conversation_id', $c->id)
                    ->where('side', 'staff')
                    ->where('visibility', 'public')
                    ->where('seq', '>', $c->customer_read_seq)
                    ->count(),
            ...$staff
                ? [
                    'user_id' => (int) $c->user_id,
                    'assignee_id' => $c->assignee_id === null ? null : (int) $c->assignee_id,
                    'version' => (int) $c->version,
                ]
                : [],
            'updated_at' => Records::iso($c->updated_at),
        ];
    }
}
