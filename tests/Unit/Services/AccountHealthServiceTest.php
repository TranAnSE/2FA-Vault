<?php

namespace Tests\Unit\Services;

use App\Models\TwoFAccount;
use App\Services\AccountHealthService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Table-driven score verification for AccountHealthService.
 *
 * The service is a pure function over model metadata, so each boundary is
 * verified with a minimal in-memory model (no DB round-trip needed).
 */
class AccountHealthServiceTest extends TestCase
{
    private AccountHealthService $health;

    protected function setUp() : void
    {
        parent::setUp();
        $this->health = new AccountHealthService;
    }

    private function makeAccount(array $attrs) : TwoFAccount
    {
        $account = new TwoFAccount;
        $account->setRawAttributes(array_merge([
            'id'           => 1,
            'otp_type'     => TwoFAccount::TOTP,
            'algorithm'    => TwoFAccount::SHA1,
            'digits'       => 6,
            'period'       => 30,
            'last_used_at' => null,
        ], $attrs));

        // last_used_at is cast to datetime on the model; emulate the cast
        if (isset($attrs['last_used_at']) && $attrs['last_used_at'] instanceof Carbon) {
            // already a Carbon instance, accessible directly via attribute
        }

        return $account;
    }

    #[Test]
    #[DataProvider('algorithmScores')]
    public function test_algorithm_scoring(?string $algorithm, int $expected) : void
    {
        $score = $this->health->computeServerScore($this->makeAccount(['algorithm' => $algorithm]));
        $this->assertSame($expected, $score['algorithm_score']);
    }

    public static function algorithmScores() : array
    {
        return [
            'md5'    => ['md5', 0],
            'sha1'   => ['sha1', 50],
            'sha256' => ['sha256', 100],
            'sha512' => ['sha512', 100],
        ];
    }

    #[Test]
    #[DataProvider('digitsScores')]
    public function test_digits_scoring(?int $digits, int $expected) : void
    {
        $score = $this->health->computeServerScore($this->makeAccount(['digits' => $digits]));
        $this->assertSame($expected, $score['digits_score']);
    }

    public static function digitsScores() : array
    {
        return [
            '4 digits' => [4, 50],
            '6 digits' => [6, 100],
            '8 digits' => [8, 100],
        ];
    }

    #[Test]
    #[DataProvider('freshnessScores')]
    public function test_freshness_scoring(?Carbon $lastUsedAt, int $expected) : void
    {
        $score = $this->health->computeServerScore($this->makeAccount(['last_used_at' => $lastUsedAt]));
        $this->assertSame($expected, $score['freshness_score']);
    }

    public static function freshnessScores() : array
    {
        return [
            'never used'   => [null, 50],
            '200 days ago' => [Carbon::now()->subDays(200), 60],
            '45 days ago'  => [Carbon::now()->subDays(45), 80],
            '15 days ago'  => [Carbon::now()->subDays(15), 100],
        ];
    }

    #[Test]
    #[DataProvider('periodScores')]
    public function test_period_scoring(?int $period, string $otpType, int $expected) : void
    {
        $score = $this->health->computeServerScore($this->makeAccount([
            'period'   => $period,
            'otp_type' => $otpType,
        ]));
        $this->assertSame($expected, $score['period_score']);
    }

    public static function periodScores() : array
    {
        return [
            'short period'   => [10, TwoFAccount::TOTP, 50],
            'normal period'  => [30, TwoFAccount::TOTP, 100],
            'hotp no period' => [null, TwoFAccount::HOTP, 100],
        ];
    }

    #[Test]
    public function test_top_account_gets_grade_a() : void
    {
        // sha256, 6 digits, fresh, period 30 -> 100 across the board
        $score = $this->health->computeServerScore($this->makeAccount([
            'algorithm'    => 'sha256',
            'digits'       => 6,
            'period'       => 30,
            'last_used_at' => Carbon::now()->subDays(5),
        ]));

        $this->assertSame(100, $score['server_total']);
        $this->assertSame('A', $score['grade']);
    }

    #[Test]
    public function test_md5_account_scores_low() : void
    {
        $score = $this->health->computeServerScore($this->makeAccount([
            'algorithm'    => 'md5',
            'digits'       => 4,
            'period'       => 10,
            'last_used_at' => Carbon::now()->subDays(400),
        ]));

        $this->assertLessThan(40, $score['server_total']);
        $this->assertSame('F', $score['grade']);
    }

    #[Test]
    public function test_summarize_aggregates_grades_and_weak_ids() : void
    {
        $strong = $this->makeAccount(['id' => 1, 'algorithm' => 'sha256', 'last_used_at' => Carbon::now()->subDays(5)]);
        $weak   = $this->makeAccount(['id' => 2, 'algorithm' => 'md5', 'last_used_at' => Carbon::now()->subDays(400)]);

        $summary = $this->health->summarize([$strong, $weak]);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['grade_counts']['A']);
        $this->assertContains(2, $summary['weak_account_ids']);
        $this->assertNotContains(1, $summary['weak_account_ids']);
    }
}
