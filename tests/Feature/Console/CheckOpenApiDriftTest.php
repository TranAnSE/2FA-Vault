<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CheckOpenApiDrift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\FeatureTestCase;

/**
 * CheckOpenApiDriftTest test class
 *
 * The command introspects real Laravel routes and a real OpenAPI file, so
 * these tests exercise its pure helper methods (parameter normalisation and
 * method-mismatch detection) in isolation rather than driving the whole
 * command against the live route table.
 */
#[CoversClass(CheckOpenApiDrift::class)]
class CheckOpenApiDriftTest extends FeatureTestCase
{
    private function invoke(object $obj, string $method, array $args = [])
    {
        $m = new ReflectionMethod($obj, $method);

        return $m->invokeArgs($obj, $args);
    }

    #[Test]
    public function test_method_mismatches_reports_paths_with_different_method_sets() : void
    {
        $command = new CheckOpenApiDrift;

        $app = [
            '/api/v1/widgets'         => ['POST'],
            '/api/v1/widgets/{param}' => ['DELETE'],
            '/api/v1/shared'          => ['GET', 'POST'],
        ];
        $spec = [
            '/api/v1/widgets'         => ['GET', 'POST'],
            '/api/v1/widgets/{param}' => ['DELETE'],
            '/api/v1/shared'          => ['GET', 'POST'],
        ];

        $mismatches = $this->invoke($command, 'methodMismatches', [$app, $spec]);

        // Only /api/v1/widgets differs (app=POST, spec=GET,POST).
        $this->assertArrayHasKey('/api/v1/widgets', $mismatches);
        $this->assertCount(1, $mismatches);
        $this->assertSame(['POST'], $mismatches['/api/v1/widgets']['app']);
        $this->assertSame(['GET', 'POST'], $mismatches['/api/v1/widgets']['spec']);
    }

    #[Test]
    public function test_method_mismatches_is_empty_when_sets_match() : void
    {
        $command = new CheckOpenApiDrift;

        $both = [
            '/a' => ['GET'],
            '/b' => ['DELETE', 'GET', 'POST'],
        ];

        $this->assertSame([], $this->invoke($command, 'methodMismatches', [$both, $both]));
    }
}
