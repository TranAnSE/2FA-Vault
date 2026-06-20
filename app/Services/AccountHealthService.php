<?php

namespace App\Services;

use App\Models\TwoFAccount;
use Illuminate\Support\Carbon;

/**
 * Computes a per-account security health score from server-visible metadata only.
 *
 * All inputs (algorithm, digits, period, last_used_at) are available to the
 * server even under E2EE — the plaintext secret is never used here. The score
 * is a pure function of the model, so it is trivially unit-testable and has no
 * side effects. Client-side secret checks (entropy, duplicates) are merged in
 * the frontend composable to form a combined score.
 */
class AccountHealthService
{
    /** Scoring weights (sum to 1.0). Centralized for easy tuning. */
    public const WEIGHT_ALGORITHM = 0.30;

    public const WEIGHT_DIGITS = 0.20;

    public const WEIGHT_FRESHNESS = 0.30;

    public const WEIGHT_PERIOD = 0.20;

    /** Freshness thresholds in days. */
    private const FRESH_NEVER = 50;

    private const FRESH_STALE_DAY = 180;

    private const FRESH_OK_DAY = 30;

    /**
     * Score a single account from its server-visible metadata.
     *
     * @return array{
     *     algorithm_score: int,
     *     digits_score: int,
     *     freshness_score: int,
     *     period_score: int,
     *     server_total: int,
     *     grade: string
     * }
     */
    public function computeServerScore(TwoFAccount $account) : array
    {
        $algorithm = $this->scoreAlgorithm($account->algorithm);
        $digits    = $this->scoreDigits($account->digits);
        $fresh     = $this->scoreFreshness($account->last_used_at);
        $period    = $this->scorePeriod($account->period, $account->otp_type);

        $total = (int) round(
            self::WEIGHT_ALGORITHM * $algorithm
            + self::WEIGHT_DIGITS * $digits
            + self::WEIGHT_FRESHNESS * $fresh
            + self::WEIGHT_PERIOD * $period
        );

        return [
            'algorithm_score' => $algorithm,
            'digits_score'    => $digits,
            'freshness_score' => $fresh,
            'period_score'    => $period,
            'server_total'    => $total,
            'grade'           => $this->grade($total),
        ];
    }

    /**
     * Aggregate health across all of a user's accounts.
     *
     * @param  iterable<int, TwoFAccount>  $accounts
     * @return array{
     *     total: int,
     *     grade_counts: array<string,int>,
     *     average_server_total: int,
     *     weak_account_ids: int[]
     * }
     */
    public function summarize(iterable $accounts) : array
    {
        $gradeCounts = array_fill_keys(['A', 'B', 'C', 'D', 'F'], 0);
        $weak        = [];
        $totalScore  = 0;
        $count       = 0;

        foreach ($accounts as $account) {
            $score = $this->computeServerScore($account);
            $gradeCounts[$score['grade']]++;
            $totalScore += $score['server_total'];
            $count++;

            // "Weak" = grade C or below
            if (in_array($score['grade'], ['C', 'D', 'F'], true)) {
                $weak[] = (int) $account->id;
            }
        }

        return [
            'total'                => $count,
            'grade_counts'         => $gradeCounts,
            'average_server_total' => $count ? (int) round($totalScore / $count) : 0,
            'weak_account_ids'     => $weak,
        ];
    }

    private function scoreAlgorithm(?string $algorithm) : int
    {
        return match (strtolower((string) $algorithm)) {
            'md5'  => 0,
            'sha1' => 50,
            'sha256', 'sha512' => 100,
            default => 50,
        };
    }

    private function scoreDigits(?int $digits) : int
    {
        $d = (int) $digits;

        return match (true) {
            $d <= 4  => 50,
            $d === 5 => 75,
            $d === 6 => 100,
            default  => 100, // 7+
        };
    }

    private function scoreFreshness(?Carbon $lastUsedAt) : int
    {
        if (! $lastUsedAt) {
            return self::FRESH_NEVER; // never used
        }

        $days = abs((int) $lastUsedAt->diffInDays(now()));

        return match (true) {
            $days > self::FRESH_STALE_DAY => 60,
            $days > self::FRESH_OK_DAY    => 80,
            default                       => 100, // <= 30 days
        };
    }

    private function scorePeriod(?int $period, ?string $otpType) : int
    {
        // HOTP has no period — treat as full marks
        if ($otpType === TwoFAccount::HOTP || $period === null) {
            return 100;
        }

        return $period < 15 ? 50 : 100;
    }

    /**
     * Map a 0–100 score to a letter grade.
     */
    public function grade(int $score) : string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default      => 'F',
        };
    }
}
