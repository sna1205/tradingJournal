<?php

namespace App\Services;

use App\Models\AccountRiskPolicy;
use App\Models\Trade;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TradeRiskPolicyService
{
    /**
     * @return array{
     *   account_id:int,
     *   max_risk_per_trade_pct:float,
     *   max_daily_loss_pct:float,
     *   max_total_drawdown_pct:float,
     *   max_open_risk_pct:float,
     *   enforce_hard_limits:bool,
     *   allow_override:bool
     * }
     */
    public function defaultPolicy(int $accountId): array
    {
        return [
            'account_id' => $accountId,
            'max_risk_per_trade_pct' => 1.0,
            'max_daily_loss_pct' => 5.0,
            'max_total_drawdown_pct' => 10.0,
            'max_open_risk_pct' => 2.0,
            'enforce_hard_limits' => true,
            'allow_override' => false,
        ];
    }

    public function getOrCreatePolicy(int $accountId): AccountRiskPolicy
    {
        $defaults = $this->defaultPolicy($accountId);

        return AccountRiskPolicy::query()->firstOrCreate(
            ['account_id' => $accountId],
            $defaults
        );
    }

    /**
     * @return array{
     *   allowed:bool,
     *   requires_override_reason:bool,
     *   policy:array<string,mixed>,
     *   violations:array<int, array{code:string,message:string,limit:float,actual:float}>,
     *   stats:array<string,float>
     * }
     */
    public function evaluate(array $input): array
    {
        $accountId = (int) $input['account_id'];
        $accountStartingBalance = (float) $input['account_starting_balance'];
        $accountCurrentBalance = (float) $input['account_current_balance'];
        $riskPercent = (float) $input['risk_percent'];
        $monetaryRisk = (float) $input['monetary_risk'];
        $derivedRiskPercent = $accountCurrentBalance > 0
            ? ($monetaryRisk / $accountCurrentBalance) * 100
            : 0.0;
        $effectiveRiskPercent = max($riskPercent, $derivedRiskPercent);
        $overrideReason = trim((string) ($input['risk_override_reason'] ?? ''));
        $excludeTradeId = isset($input['exclude_trade_id']) ? (int) $input['exclude_trade_id'] : null;

        $tradeDate = $this->toTradeDate($input['trade_date'] ?? null);
        $tradeDay = $tradeDate->toDateString();

        $policy = $this->getOrCreatePolicy($accountId);

        $dailyRealizedLoss = $this->dailyRealizedLoss($accountId, $tradeDay, $excludeTradeId);
        $projectedDailyLoss = $dailyRealizedLoss + max(0.0, $monetaryRisk);
        $projectedDailyLossPct = $accountStartingBalance > 0
            ? ($projectedDailyLoss / $accountStartingBalance) * 100
            : 0.0;

        $projectedBalanceAfterStop = $accountCurrentBalance - max(0.0, $monetaryRisk);
        $projectedDrawdown = max(0.0, $accountStartingBalance - $projectedBalanceAfterStop);
        $projectedDrawdownPct = $accountStartingBalance > 0
            ? ($projectedDrawdown / $accountStartingBalance) * 100
            : 0.0;

        $violations = [];
        $this->appendViolation(
            $violations,
            'max_risk_per_trade_pct',
            'Risk per trade exceeds account limit.',
            (float) $policy->max_risk_per_trade_pct,
            $effectiveRiskPercent,
            $effectiveRiskPercent > (float) $policy->max_risk_per_trade_pct
        );
        $this->appendViolation(
            $violations,
            'max_open_risk_pct',
            'Open risk cap exceeded for this execution.',
            (float) $policy->max_open_risk_pct,
            $effectiveRiskPercent,
            $effectiveRiskPercent > (float) $policy->max_open_risk_pct
        );
        $this->appendViolation(
            $violations,
            'max_daily_loss_pct',
            'Projected daily loss exceeds policy.',
            (float) $policy->max_daily_loss_pct,
            $projectedDailyLossPct,
            $projectedDailyLossPct > (float) $policy->max_daily_loss_pct
        );
        $this->appendViolation(
            $violations,
            'max_total_drawdown_pct',
            'Projected total drawdown exceeds policy.',
            (float) $policy->max_total_drawdown_pct,
            $projectedDrawdownPct,
            $projectedDrawdownPct > (float) $policy->max_total_drawdown_pct
        );

        $hasViolations = count($violations) > 0;
        $enforced = (bool) $policy->enforce_hard_limits;
        $canOverride = (bool) $policy->allow_override;
        $hasOverrideReason = $overrideReason !== '';
        $requiresOverrideReason = $hasViolations && $enforced && $canOverride && ! $hasOverrideReason;

        $allowed = ! $hasViolations
            || ! $enforced
            || ($canOverride && $hasOverrideReason);

        return [
            'allowed' => $allowed,
            'requires_override_reason' => $requiresOverrideReason,
            'policy' => [
                'account_id' => (int) $policy->account_id,
                'max_risk_per_trade_pct' => (float) $policy->max_risk_per_trade_pct,
                'max_daily_loss_pct' => (float) $policy->max_daily_loss_pct,
                'max_total_drawdown_pct' => (float) $policy->max_total_drawdown_pct,
                'max_open_risk_pct' => (float) $policy->max_open_risk_pct,
                'enforce_hard_limits' => (bool) $policy->enforce_hard_limits,
                'allow_override' => (bool) $policy->allow_override,
            ],
            'violations' => $violations,
            'stats' => [
                'risk_percent' => round($effectiveRiskPercent, 4),
                'monetary_risk' => round($monetaryRisk, 6),
                'daily_realized_loss' => round($dailyRealizedLoss, 6),
                'projected_daily_loss' => round($projectedDailyLoss, 6),
                'projected_daily_loss_pct' => round($projectedDailyLossPct, 4),
                'projected_drawdown' => round($projectedDrawdown, 6),
                'projected_drawdown_pct' => round($projectedDrawdownPct, 4),
            ],
        ];
    }

    /**
     * @param  array<int, array{code:string,message:string,limit:float,actual:float}>  $violations
     */
    private function appendViolation(
        array &$violations,
        string $code,
        string $message,
        float $limit,
        float $actual,
        bool $condition
    ): void {
        if (! $condition) {
            return;
        }

        $violations[] = [
            'code' => $code,
            'message' => $message,
            'limit' => round($limit, 4),
            'actual' => round($actual, 4),
        ];
    }

    private function dailyRealizedLoss(int $accountId, string $tradeDay, ?int $excludeTradeId): float
    {
        $query = Trade::query()
            ->where('account_id', $accountId)
            ->whereDate('date', $tradeDay)
            ->where('profit_loss', '<', 0);

        if ($excludeTradeId !== null && $excludeTradeId > 0) {
            $query->where('id', '!=', $excludeTradeId);
        }

        $sum = (float) ($query->sum('profit_loss') ?? 0.0);

        return abs($sum);
    }

    private function toTradeDate(mixed $value): CarbonInterface
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return now();
    }
}
