<?php

namespace App\Domain\Ai;

use App\Domain\Procurement\Records;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every AI call goes through here: configuration, quotas per role, monthly budget, timing and a minimal journal
 * (feature, units, estimated cost; never the prompt, image or answer). It holds no database lock while the
 * provider answers, and it has no access to any financial action.
 */
final class AiGate
{
    public function __construct(private EmbeddingProvider $embeddings, private TextGenerator $generator) {}

    public function embeddings(): EmbeddingProvider
    {
        return $this->embeddings;
    }

    public function generator(): TextGenerator
    {
        return $this->generator;
    }

    /** Enabled only with a key, a monthly budget and the provider prices; no value is invented. */
    public function ready(string $kind): bool
    {
        $prices = config('kora.ai.prices');
        $priced =
            $kind === 'embeddings'
                ? $prices['embeddings'] > 0
                : $prices['generator_input'] > 0 && $prices['generator_output'] > 0;

        return config('kora.ai.monthly_budget_usd') > 0 &&
            $priced &&
            ($kind === 'embeddings' ? $this->embeddings : $this->generator)->configured();
    }

    public function embed(string $feature, ?User $user, array $inputs, string $type): array
    {
        return $this->run($feature, $user, 'embeddings', fn () => $this->embeddings->embed($inputs, $type));
    }

    public function generate(
        string $feature,
        User $user,
        string $system,
        array $content,
        string $tool,
        array $schema,
        int $maxTokens,
    ): array {
        return $this->run(
            $feature,
            $user,
            'generator',
            fn () => $this->generator->structured($system, $content, $tool, $schema, $maxTokens),
        );
    }

    private function run(string $feature, ?User $user, string $kind, callable $call): array
    {
        $provider = $kind === 'embeddings' ? $this->embeddings : $this->generator;
        if (! $this->ready($kind)) {
            throw new AiUnavailable('not_configured');
        }
        if ($kind === 'generator' && $user) {
            $quota = $this->quota($user);
            $used = DB::table('ai_usage')
                ->where('user_id', $user->id)
                ->where('feature', $feature)
                ->whereIn('status', ['ok', 'failed'])
                ->where('created_at', '>=', $this->dayStart())
                ->count();
            if ($used >= $quota) {
                $this->record($feature, $provider->name(), $user, 'refused', 'quota');

                throw new AiUnavailable('quota');
            }
        }
        if ($this->monthCost() >= config('kora.ai.monthly_budget_usd') * 1_000_000) {
            $this->record($feature, $provider->name(), $user, 'refused', 'budget');

            throw new AiUnavailable('budget');
        }
        $started = hrtime(true);
        try {
            $result = $call();
        } catch (AiUnavailable $e) {
            $this->record($feature, $provider->name(), $user, 'failed', $e->reason, duration: $this->elapsed($started));

            throw $e;
        } catch (\Throwable $e) {
            $this->record(
                $feature,
                $provider->name(),
                $user,
                'failed',
                'provider_error',
                duration: $this->elapsed($started),
            );

            throw new AiUnavailable('provider_error', $e);
        }
        $prices = config('kora.ai.prices');
        [$in, $out] =
            $kind === 'embeddings' ? [$result['units'], 0] : [$result['input_units'], $result['output_units']];
        $cost =
            $kind === 'embeddings'
                ? $in * $prices['embeddings']
                : $in * $prices['generator_input'] + $out * $prices['generator_output'];
        // Prices are per million units: units × USD/million = micro-USD.
        $this->record(
            $feature,
            $provider->name(),
            $user,
            'ok',
            null,
            $in,
            $out,
            (int) ceil($cost),
            $this->elapsed($started),
        );

        return $result;
    }

    /** Highest quota among the person's roles; a role without quota gets none. */
    public function quota(User $user): int
    {
        $quotas = config('kora.ai.daily_quotas');

        return (int) collect($user->roles ?? [])
            ->map(fn ($role) => $quotas[$role] ?? 0)
            ->max();
    }

    public function dayStart(): CarbonImmutable
    {
        return CarbonImmutable::now('Africa/Douala')->startOfDay()->utc();
    }

    public function monthCost(): int
    {
        return (int) DB::table('ai_usage')
            ->where('created_at', '>=', CarbonImmutable::now('Africa/Douala')->startOfMonth()->utc())
            ->sum('estimated_cost_micro_usd');
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private function record(
        string $feature,
        string $provider,
        ?User $user,
        string $status,
        ?string $reason,
        int $in = 0,
        int $out = 0,
        int $cost = 0,
        int $duration = 0,
    ): void {
        DB::table('ai_usage')->insert([
            'id' => Records::id(),
            'feature' => $feature,
            'provider' => $provider,
            'user_id' => $user?->id,
            'status' => $status,
            'reason' => $reason,
            'input_units' => $in,
            'output_units' => $out,
            'estimated_cost_micro_usd' => $cost,
            'duration_ms' => $duration,
            'created_at' => now(),
        ]);
    }

    /** Owner view: month estimate against budget, by feature, and whether each provider is ready. */
    public function summary(): array
    {
        $since = CarbonImmutable::now('Africa/Douala')->startOfMonth()->utc();

        return [
            'ready' => ['embeddings' => $this->ready('embeddings'), 'generator' => $this->ready('generator')],
            'providers' => ['embeddings' => $this->embeddings->name(), 'generator' => $this->generator->name()],
            'monthly_budget_usd' => (string) config('kora.ai.monthly_budget_usd'),
            'month_estimated_usd' => number_format($this->monthCost() / 1_000_000, 4, '.', ''),
            'by_feature' => DB::table('ai_usage')
                ->where('created_at', '>=', $since)
                ->groupBy('feature', 'status')
                ->orderBy('feature')
                ->orderBy('status')
                ->selectRaw('feature, status, COUNT(*) AS calls, SUM(estimated_cost_micro_usd) AS cost')
                ->get()
                ->map(
                    fn ($r) => [
                        'feature' => $r->feature,
                        'status' => $r->status,
                        'calls' => (int) $r->calls,
                        'estimated_usd' => number_format((int) $r->cost / 1_000_000, 4, '.', ''),
                    ],
                )
                ->all(),
        ];
    }
}
