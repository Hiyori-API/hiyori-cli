<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Ml\FellegiSunterCalibration;
use PHPUnit\Framework\TestCase;

/**
 * The FS m/u calibration estimates P(agree | match) and P(agree | no match)
 * per field from labelled pairs, using the same agreement definition the
 * scorer uses. The tests use a synthetic JSONL where the truth is known so
 * the estimates can be checked against what was generated.
 */
final class FellegiSunterCalibrationTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'fscal') . '.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @param array<string, float> $features
     * @param array<string, mixed> $row
     */
    private function row(int $label, array $features, array $row = []): string
    {
        $full = array_merge([
            'exact_norm' => 0, 'jaro' => 0, 'token_jaccard' => 0, 'containment' => 0,
            'synonym_overlap' => 0, 'type_match' => 0, 'episodes_match' => 0,
            'status_match' => 0, 'season_match' => 0, 'year_similarity' => 0,
            'tags_jaccard' => 0, 'ref_overlap' => 0,
        ], $features);

        return (string) json_encode(array_merge(['label' => $label, 'features' => $full], $row)) . "\n";
    }

    public function testFitRecoversAGivenMAndU(): void
    {
        // Matches: title always agrees (1.0), type agrees most of the time (0.9).
        // Non-matches: title almost never (0.1), type agrees 30% of the time.
        $lines = '';
        for ($i = 0; $i < 100; $i++) {
            $lines .= $this->row(1, [
                'jaro' => 1.0,
                'type_match' => $i < 90 ? 1.0 : 0.0,
            ]);
        }
        for ($i = 0; $i < 100; $i++) {
            $lines .= $this->row(0, [
                'jaro' => $i < 10 ? 1.0 : 0.0,
                'type_match' => $i < 30 ? 1.0 : 0.0,
            ]);
        }
        file_put_contents($this->path, $lines);

        $r = FellegiSunterCalibration::fit($this->path, 0.85);

        // title: m ~1.0, u ~0.1 (with Laplace smoothing).
        $this->assertGreaterThan(0.9, $r['m']['title']);
        $this->assertLessThan(0.2, $r['u']['title']);
        // type: m ~0.9, u ~0.3.
        $this->assertGreaterThan(0.8, $r['m']['type']);
        $this->assertGreaterThan(0.2, $r['u']['type']);
        $this->assertLessThan(0.4, $r['u']['type']);
        // m must always exceed u (positive evidence).
        foreach ($r['m'] as $field => $m) {
            $this->assertGreaterThan($r['u'][$field], $m, "field {$field} must have m > u");
        }
    }

    public function testUnlabeledRowsAreIgnored(): void
    {
        file_put_contents(
            $this->path,
            $this->row(1, ['jaro' => 1.0])
            . $this->row(-1, ['jaro' => 1.0])   // unsure/unlabeled
            . "\n"                               // blank
            . 'not json',
        );

        $r = FellegiSunterCalibration::fit($this->path, 0.85);
        $this->assertSame(1, $r['counts']['title']['n_pos'], 'only labeled rows counted');
        $this->assertSame(0, $r['counts']['title']['n_neg']);
    }

    public function testLoadRoundTripsTheSavedCalibration(): void
    {
        $lines = $this->row(1, ['jaro' => 1.0]) . $this->row(0, ['jaro' => 0.0]);
        file_put_contents($this->path, $lines);
        $fitted = FellegiSunterCalibration::fit($this->path, 0.85);

        $saved = (string) tempnam(sys_get_temp_dir(), 'fsjson');
        file_put_contents($saved, (string) json_encode([
            'm' => $fitted['m'],
            'u' => $fitted['u'],
            'agreement_cutoff' => 0.85,
        ]));

        $loaded = FellegiSunterCalibration::load($saved);
        $this->assertNotNull($loaded);
        $this->assertSame($fitted['m']['title'], $loaded['m']['title']);
        $this->assertSame($fitted['u']['title'], $loaded['u']['title']);
        $this->assertSame(0.85, $loaded['agreement_cutoff']);
        @unlink($saved);
    }

    public function testLoadReturnsNullForMissingFile(): void
    {
        $this->assertNull(FellegiSunterCalibration::load($this->path . '.nope'));
    }

    public function testFitThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        FellegiSunterCalibration::fit($this->path . '.missing');
    }
}
