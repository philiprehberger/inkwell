<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Spam\SpamScorer;
use App\Services\Spam\SubmissionContext;
use App\Services\Spam\SubmissionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The spam-scoring corpus. Each case asserts the resulting score falls in a
 * range — range-based assertions are tolerant of signal-weight tuning. The
 * corpus catches regressions when a signal flips polarity or breaks
 * structurally (e.g. a JSON cast change silently re-shapes the metadata
 * column).
 *
 * Add a corpus row BEFORE changing signal weights or signal semantics. The
 * corpus is the contract.
 */
class SpamScorerCorpusTest extends TestCase
{
    use RefreshDatabase;

    public static function corpusCases(): array
    {
        $path = __DIR__.'/../corpus/spam-corpus.json';
        $data = json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($data['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    #[DataProvider('corpusCases')]
    public function test_corpus_case(array $case): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace, [
            'spam_threshold' => 50,
            'cors_origins' => ['https://example.com'],
        ]);

        $ctx = new SubmissionContext(
            form: $form,
            payload: $case['payload'],
            raw: array_merge($case['payload'], $case['raw'] ?? []),
            clientIp: '203.0.113.10',
            userAgent: 'corpus-runner/1.0',
            referer: null,
            renderedAtTimestamp: null,
            captchaToken: null,
        );

        $result = SpamScorer::fromConfig()->score($ctx);

        $this->assertGreaterThanOrEqual($case['score_min'], $result->score, "Corpus '{$case['name']}' score too low: {$result->score}");
        $this->assertLessThanOrEqual($case['score_max'], $result->score, "Corpus '{$case['name']}' score too high: {$result->score}");
        $this->assertSame($case['expected_state'], $result->state, "Corpus '{$case['name']}' state mismatch");
    }
}
