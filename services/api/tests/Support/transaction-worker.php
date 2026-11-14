<?php

// CLI worker for real PostgreSQL contention tests. Never an HTTP endpoint.
use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\AccountingCalendar;
use App\Domain\Accounting\AccountingCapture;
use App\Domain\Accounting\AccountingEntries;
use App\Domain\Accounting\AccountingLettering;
use App\Domain\Accounting\AccountingSources;
use App\Domain\Accounting\BalanceObservations;
use App\Domain\Accounting\ExpensePlans;
use App\Domain\Accounting\Financings;
use App\Domain\Accounting\TreasuryCoverage;
use App\Domain\Accounting\TreasuryOutstanding;
use App\Domain\Accounting\TreasuryReconciliation;
use App\Domain\Accounting\Withdrawals;
use App\Domain\Cart\Carts;
use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Delivery\DeliveryQuotes;
use App\Domain\Delivery\SupplierCollections;
use App\Domain\Documents\CustomerDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Loyalty\LoyaltyLedger;
use App\Domain\Orders\OrderPreparation;
use App\Domain\Orders\OrderPurchases;
use App\Domain\Orders\Orders;
use App\Domain\Orders\OrderSupply;
use App\Domain\Payments\Refunds;
use App\Domain\Procurement\Payables;
use App\Domain\Procurement\Records;
use App\Domain\Professional\BusinessProfiles;
use App\Domain\Professional\ProfessionalQuotes;
use App\Domain\Promotions\DeliveryOffers;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\OutboxProcessor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Imports must precede their use: PHP applies a `use` only to the code that follows it.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.connections.pgsql');
if (
    ! $app->environment('testing') ||
    config('database.default') !== 'pgsql' ||
    ! empty($connection['url']) ||
    $connection['database'] !== 'kora_test' ||
    $connection['host'] !== '127.0.0.1' ||
    (string) $connection['port'] !== '55432'
) {
    fwrite(STDERR, 'Test database guard refused the worker.');
    exit(3);
}
if (DB::selectOne('select current_database() as name')->name !== 'kora_test') {
    exit(3);
}
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (isset($payload['test_now'])) {
    Date::setTestNow(CarbonImmutable::parse($payload['test_now']));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($payload['test_now']));
}
DB::select("select set_config('application_name', ?, false)", [$payload['race_name']]);
DB::statement("SET lock_timeout = '15s'");
$user = User::findOrFail($payload['actor_id']);
try {
    if ($payload['kind'] === 'outbox') {
        $result = app(OutboxProcessor::class)->process($payload['event_id']);
        $status = match ($result) {
            'delivered' => 'ok',
            'skipped', 'suppressed' => 'rejected',
            default => 'error',
        };
        echo json_encode(['status' => $status, 'result' => $result], JSON_THROW_ON_ERROR);
        exit($status === 'error' ? 2 : 0);
    }
    $result = app(CommandBus::class)->execute(
        $user,
        $payload['command'],
        $payload['key'],
        $payload['data'],
        function () use ($payload, $user) {
            if (str_starts_with($payload['kind'], 'accounting_')) {
                $book = Records::row('accounting_books', $payload['data']['book_id']);
                $data = $payload['data'];

                return match ($payload['kind']) {
                    'accounting_financing_record' => app(Financings::class)->record($book, $data['id'], $data, $user),
                    'accounting_financing_reverse' => app(Financings::class)->reverse(
                        $book,
                        $data['id'],
                        $data['movement'],
                        $data,
                        $user,
                    ),
                    'accounting_balance_create' => app(BalanceObservations::class)->create($book, $data, $user),
                    'accounting_balance_approve' => app(BalanceObservations::class)->transition(
                        $book,
                        $data['id'],
                        'approve',
                        $data,
                        $user,
                    ),
                    'accounting_withdrawal_record' => app(Withdrawals::class)->record($book, $data['id'], $data, $user),
                    'accounting_withdrawal_cancel' => app(Withdrawals::class)->transition(
                        $book,
                        $data['id'],
                        'cancel',
                        $data,
                        $user,
                    ),
                    'accounting_expense_record' => app(ExpensePlans::class)->recordPayment(
                        $book,
                        $data['id'],
                        $data,
                        $user,
                    ),
                    'accounting_expense_cancel' => app(ExpensePlans::class)->transition(
                        $book,
                        $data['id'],
                        'cancel',
                        $data,
                        $user,
                    ),
                    'accounting_lettering_create' => app(AccountingLettering::class)->create($book, $data, $user),
                    'accounting_lettering_void' => app(AccountingLettering::class)->void(
                        $book,
                        $data['id'],
                        $data,
                        $user,
                    ),
                    'accounting_coverage_review' => app(TreasuryCoverage::class)->transition(
                        $book,
                        $data['id'],
                        'review',
                        $data,
                        $user,
                    ),
                    'accounting_outstanding_create' => app(TreasuryOutstanding::class)->create(
                        $book,
                        app(AccountingBooks::class)->row('treasury_statements', $data['id'], $book),
                        $data,
                        $user,
                    ),
                    'accounting_treasury_match' => app(TreasuryReconciliation::class)->match(
                        $book,
                        app(AccountingBooks::class)->row('treasury_statements', $data['id'], $book),
                        $data,
                        $user,
                    ),
                    'accounting_treasury_close' => app(TreasuryReconciliation::class)->transition(
                        $book,
                        app(AccountingBooks::class)->row('treasury_statements', $data['id'], $book),
                        'close',
                        $data,
                        $user,
                    ),
                    'accounting_capture' => [
                        'id' => $data['event_id'],
                        'result' => app(AccountingCapture::class)->process($book->id, $data['event_id']),
                    ],
                    'accounting_manual_capture' => app(AccountingSources::class)->capture(
                        $book,
                        $data['source_type'],
                        $data['resource_id'],
                        $user,
                    ),
                    'accounting_post' => app(AccountingEntries::class)->post(
                        $book,
                        app(AccountingBooks::class)->row('accounting_entries', $data['id'], $book),
                        $data['version'],
                        $user,
                    ),
                    'accounting_close' => app(AccountingCalendar::class)->transition(
                        $book,
                        $data['id'],
                        false,
                        false,
                        $data,
                        $user,
                    ),
                    'accounting_create' => app(AccountingEntries::class)->create($book, $data, $user),
                };
            }
            if ($payload['kind'] === 'loyalty_checkout') {
                $http = Request::create('/api/v1/orders', 'POST');
                $http->setUserResolver(fn () => $user);
                $cart = app(Carts::class)->current($http, true);
                $quote = app(DeliveryQuotes::class)->owned($payload['data']['quote_id'], $user, true);
                abort_unless((int) $quote->version === $payload['data']['quote_version'], 409);

                return app(Orders::class)->create(
                    $cart,
                    $quote,
                    $payload['data']['accepted_total'],
                    $user,
                    null,
                    $payload['data']['loyalty_quote_id'],
                );
            }
            if ($payload['kind'] === 'loyalty_delivered') {
                return app(LoyaltyLedger::class)->delivered(Records::row('orders', $payload['order_id'])) ?? [];
            }
            if ($payload['kind'] === 'loyalty_adjust') {
                return app(LoyaltyLedger::class)->adjust(
                    Records::row('loyalty_accounts', $payload['account_id']),
                    $payload['data'],
                    $user,
                );
            }
            if ($payload['kind'] === 'professional_convert') {
                $profile = app(BusinessProfiles::class)->authorized($payload['profile_id'], $user, true, false, true);
                $quotes = app(ProfessionalQuotes::class);

                return $quotes->convert($quotes->owned($profile, $payload['quote_id'], true), $payload['data'], $user);
            }
            if ($payload['kind'] === 'purchase_assign') {
                return app(OrderPurchases::class)->assign(
                    Records::row('orders', $payload['order_id']),
                    $payload['line_id'],
                    $payload['data'],
                    $user,
                );
            }
            if ($payload['kind'] === 'purchase_receive') {
                return app(OrderPurchases::class)->receive(
                    Records::row('orders', $payload['order_id']),
                    $payload['allocation_id'],
                    $payload['data'],
                    $user,
                );
            }
            if ($payload['kind'] === 'preparation_pick') {
                $view = app(OrderPreparation::class)->pick($payload['order_id'], $payload['data'], $user);

                return ['id' => $view['order_id'], ...$view];
            }
            if ($payload['kind'] === 'mission_handover') {
                return app(DeliveryMissions::class)->handover($payload['mission_id'], $payload['data'], $user);
            }
            if ($payload['kind'] === 'collection_create') {
                return app(SupplierCollections::class)->create($payload['purchase_id'], $payload['data'], $user);
            }
            if ($payload['kind'] === 'mission_assign') {
                return app(DeliveryMissions::class)->assign($payload['mission_id'], $payload['data'], $user);
            }
            if ($payload['kind'] === 'receipt_release') {
                return app(OrderPurchases::class)->releaseReceipt(
                    Records::row('orders', $payload['order_id']),
                    $payload['receipt_id'],
                    $payload['data'],
                    $user,
                );
            }
            if ($payload['kind'] === 'supply_choice') {
                return app(OrderSupply::class)->choose(
                    app(Orders::class)->owned($payload['order_id'], $user, true),
                    $payload['case_id'],
                    $payload['data']['version'],
                    $payload['data']['choice'],
                    $payload['data']['accepted_refund_amount'] ?? null,
                    $user,
                );
            }
            if ($payload['kind'] === 'cart_add') {
                $http = Request::create('/api/v1/cart/lines', 'POST');
                $http->setUserResolver(fn () => $user);
                $carts = app(Carts::class);
                $cart = $carts->current($http, true);
                $carts->version($cart, $payload['data']['version']);
                $carts->add($cart, $payload['data']['offer_id'], $payload['data']['quantity']);

                return $carts->dto($carts->bump($cart));
            }
            if ($payload['kind'] === 'offer_reserve') {
                $order = Records::row('orders', $payload['order_id']);
                $offers = app(DeliveryOffers::class);
                $promotion = $offers->evaluate(
                    (int) $order->user_id,
                    $payload['zone_id'],
                    [['unit_price' => 6000]],
                    [['code' => 'base', 'amount' => 1500]],
                    1500,
                    true,
                );
                if (! $promotion) {
                    DeliveryOffers::fail('offer', 'OFFER_UNAVAILABLE');
                }
                $offers->reserve($order->id, (int) $order->user_id, $promotion, now()->addMinutes(10));

                return ['id' => $order->id];
            }
            if ($payload['kind'] === 'document_issue') {
                Records::row('orders', $payload['order_id']);

                return app(CustomerDocuments::class)->issue(
                    Records::row('customer_documents', $payload['document_id']),
                    $user,
                );
            }
            if ($payload['kind'] === 'refund_request') {
                return app(Refunds::class)->request(
                    Records::row('orders', $payload['order_id']),
                    $payload['data']['amount'],
                    'Concurrency test only',
                    null,
                    $user,
                );
            }
            if ($payload['kind'] === 'slot_hold') {
                $quotes = app(DeliveryQuotes::class);
                $quote = $quotes->owned($payload['quote_id'], $user, true);

                return $quotes->holdSlot(
                    $quote,
                    Records::row('carts', $quote->cart_id, false),
                    $payload['data']['rule_id'],
                    $payload['data']['date'],
                );
            }

            return $payload['kind'] === 'reserve'
                ? app(Inventory::class)->reserve($payload['data'], $user)
                : app(Payables::class)->verifyPayment($payload['payment_id'], $payload['data'], $user);
        },
    );
    echo json_encode(['status' => 'ok', 'id' => $result['id']], JSON_THROW_ON_ERROR);
    exit(0);
} catch (ValidationException) {
    echo json_encode(['status' => 'rejected']);
    exit(0);
} catch (HttpExceptionInterface $error) {
    // A courier who lost the mission sees it as absent: for mission races that is a refusal, not a fault.
    if (
        $error->getStatusCode() === 409 ||
        ($error->getStatusCode() === 404 && str_starts_with($payload['kind'], 'mission_'))
    ) {
        echo json_encode(['status' => 'rejected']);
        exit(0);
    }
    echo json_encode(['status' => 'error', 'type' => $error::class]);
    exit(2);
} catch (Throwable $error) {
    // Where it failed, so an intermittent fault in a full run can be traced without rerunning blind.
    echo json_encode([
        'status' => 'error',
        'type' => $error::class,
        'at' => basename($error->getFile()).':'.$error->getLine(),
        'message' => mb_substr($error->getMessage(), 0, 300),
    ]);
    exit(2);
}
