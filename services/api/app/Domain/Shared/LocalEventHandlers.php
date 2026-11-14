<?php

namespace App\Domain\Shared;

use App\Domain\Notifications\PayableReminderHandler;
use App\Domain\Notifications\RequestNotificationHandler;

final class LocalEventHandlers
{
    public static function types(): array
    {
        return [
            PayableReminderHandler::TYPE,
            'ContentPublished',
            'ProductRequestOfferReady',
            'ProductRequestMessageAdded',
        ];
    }

    public static function name(string $type): string
    {
        return match ($type) {
            PayableReminderHandler::TYPE => PayableReminderHandler::NAME,
            'ContentPublished' => 'catalogue.request-projections',
            'ProductRequestOfferReady' => 'account.request-availability',
            'ProductRequestMessageAdded' => 'account.request-message',
            default => 'unsupported',
        };
    }

    public function __construct(
        private PayableReminderHandler $payables,
        private RequestNotificationHandler $requests,
    ) {}

    public function handle(object $event): string
    {
        return $event->type === PayableReminderHandler::TYPE
            ? $this->payables->handle($event)
            : $this->requests->handle($event);
    }
}
