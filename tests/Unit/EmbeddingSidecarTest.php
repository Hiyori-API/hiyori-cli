<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Ml\EmbeddingService;
use PHPUnit\Framework\TestCase;

/**
 * The embedding sidecar protocol: one JSON object per line on stdin, one
 * JSON array of floats per line on stdout.
 *
 * The sidecar is optional, so the protocol is checked against a stub that
 * speaks it. The real sidecar is checked when this checkout has a venv with
 * the model already in the Hugging Face cache (no network in a test).
 */
final class EmbeddingSidecarTest extends TestCase
{
    private const REAL_MODEL = 'intfloat/multilingual-e5-small';

    public function testTheSidecarProtocolIsUsedWhenACommandIsConfigured(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'emb') . '.json';

        // A stub that speaks the protocol: it answers with a fixed vector.
        $service = new EmbeddingService(64, "printf '[0.0,0.6,0.8]'", $file, 'stub-model', 'stub-1');
        $this->assertTrue($service->isExternal());

        $vector = $service->embed('Cowboy Bebop');
        $this->assertCount(3, $vector);
        $this->assertEqualsWithDelta(0.0, $vector[0], 1e-9);
        // Normalised: 0.6 / 0.8 -> 3-4-5 triangle.
        $this->assertEqualsWithDelta(0.6, $vector[1], 1e-9);
        $this->assertEqualsWithDelta(0.8, $vector[2], 1e-9);

        @unlink($file);
    }

    public function testWithoutACommandTheServiceFallsBackToTheLocalHasher(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'emb') . '.json';
        $service = new EmbeddingService(32, null, $file, 'local-ngram', 'local-ngram-1');

        $this->assertFalse($service->isExternal());
        $this->assertCount(32, $service->embed('Cowboy Bebop'));

        @unlink($file);
    }

    public function testTheRealSidecarAnswersWithAVectorWhenItIsInstalled(): void
    {
        $root = dirname(__DIR__, 2);
        $python = $root . '/.venv-ml/bin/python';
        $sidecar = $root . '/services/ml/embed.py';
        if (!is_file($python) || !is_file($sidecar)) {
            $this->markTestSkipped('No .venv-ml in this checkout.');
        }
        if (!$this->modelIsCached()) {
            $this->markTestSkipped('The model is not in the local Hugging Face cache.');
        }

        $file = tempnam(sys_get_temp_dir(), 'emb') . '.json';
        $service = new EmbeddingService(
            384,
            escapeshellarg($python) . ' ' . escapeshellarg($sidecar),
            $file,
            self::REAL_MODEL,
            'test-1',
        );

        $vector = $service->embed('Cowboy Bebop');
        $this->assertCount(384, $vector);
        $this->assertEqualsWithDelta(1.0, array_sum(array_map(static fn (float $v): float => $v * $v, $vector)), 1e-6);

        @unlink($file);
    }

    /**
     * Whether the model is already downloaded, so the test never needs the
     * network.
     */
    private function modelIsCached(): bool
    {
        $home = getenv('HF_HOME');
        if (!is_string($home) || $home === '') {
            $home = (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/huggingface';
        }
        $path = $home . '/hub/models--' . str_replace('/', '--', self::REAL_MODEL);

        return is_dir($path);
    }
}
