<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

class CheckOpenApiDrift extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    public $signature = '2fauth:openapi-drift
        {--spec= : Absolute path to 2fauth-api-latest.yaml (defaults to ../2FA-Vault-API/2fauth-api-latest.yaml)}
        {--path=api/v1 : Only compare Laravel routes whose URI starts with this prefix}';

    /**
     * The console command description.
     *
     * @var string
     */
    public $description = 'Compare registered Laravel API routes against the OpenAPI spec paths and report drift.';

    /**
     * Methods normalised for comparison (HEAD is implicit on GET and is ignored).
     */
    protected array $ignoreMethods = ['HEAD'];

    /**
     * Execute the console command.
     */
    public function handle() : int
    {
        $specPath = (string) ($this->option('spec') ?: base_path('../2FA-Vault-API/2fauth-api-latest.yaml'));
        $prefix   = (string) $this->option('path');

        if (! is_readable($specPath)) {
            $this->error(sprintf('Cannot read OpenAPI spec at "%s". Pass --spec=<path> to override.', $specPath));

            return self::FAILURE;
        }

        $laravelRoutes = $this->laravelRoutes($prefix);
        $specRoutes    = $this->specRoutes($specPath);

        $missingFromSpec  = array_diff_key($laravelRoutes, $specRoutes);
        $missingFromApp   = array_diff_key($specRoutes, $laravelRoutes);
        $methodMismatches = $this->methodMismatches($laravelRoutes, $specRoutes);

        $totalDrift = count($missingFromSpec) + count($missingFromApp) + count($methodMismatches);

        if (count($missingFromSpec)) {
            $this->warn(sprintf('Routes in Laravel but missing from the spec (%d):', count($missingFromSpec)));
            foreach (array_keys($missingFromSpec) as $path) {
                $this->line('  + ' . $path . '  [' . implode(',', $laravelRoutes[$path]) . ']');
            }
        }

        if (count($missingFromApp)) {
            $this->warn(sprintf('Paths in the spec but not registered in Laravel (%d):', count($missingFromApp)));
            foreach (array_keys($missingFromApp) as $path) {
                $this->line('  - ' . $path . '  [' . implode(',', $specRoutes[$path]) . ']');
            }
        }

        if (count($methodMismatches)) {
            $this->warn(sprintf('Method mismatches (%d):', count($methodMismatches)));
            foreach ($methodMismatches as $path => $diff) {
                $this->line(sprintf('  ~ %s  app=[%s] spec=[%s]', $path, implode(',', $diff['app']), implode(',', $diff['spec'])));
            }
        }

        if ($totalDrift === 0) {
            $this->info('No drift: every Laravel route under ' . $prefix . '/ is modelled in the spec (and vice versa).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('%d drift item(s) found. Resolve them or document the intentional exceptions.', $totalDrift));

        // Non-zero but informative: callers can treat this as a CI warning gate.
        return (int) ($totalDrift > 0);
    }

    /**
     * Laravel API routes keyed by normalised path, value = sorted method list.
     *
     * @return array<string, array<string>>
     */
    protected function laravelRoutes(string $prefix) : array
    {
        $routes = [];

        // A handful of documented endpoints live outside the api/v1 prefix
        // (registered in routes/web.php). They are spec'd at their real paths,
        // so include them by exact match in addition to the prefix scan.
        $extraDocumented = ['metrics'];

        foreach (Route::getRoutes() as $route) {
            $uri               = $route->uri();
            $matchesPrefix     = str_starts_with($uri, $prefix);
            $isExtraDocumented = in_array(trim($uri, '/'), $extraDocumented, true);
            if (! $matchesPrefix && ! $isExtraDocumented) {
                continue;
            }

            // Skip catch-all SPA fallbacks (e.g. /api/v1/{any}) — they are not
            // real API endpoints and would always show up as drift.
            if (preg_match('#\{any\}|\{path\}#', $uri) && in_array('GET', $route->methods(), true)) {
                continue;
            }

            $methods = array_diff($route->methods(), $this->ignoreMethods);
            if (empty($methods)) {
                continue;
            }

            $path = '/' . $uri;
            // Normalise parameter names: Laravel routes use contextual names
            // ({twofaccount}, {tag}, {group}) while the spec uses {id}. Collapse
            // every {...} segment to a canonical {param} so the comparison keys
            // on path shape rather than parameter naming.
            $path = preg_replace('/\{[^}]+\}/', '{param}', $path);

            // MERGE methods across routes that share a URI (e.g. a GET index
            // and a POST store are registered as two separate Route objects).
            $existing      = $routes[$path] ?? [];
            $routes[$path] = array_values(array_unique(array_merge(
                $existing,
                array_map('strtoupper', $methods),
            )));
            sort($routes[$path]);
        }

        return $routes;
    }

    /**
     * OpenAPI paths keyed by normalised path, value = sorted method list.
     *
     * @return array<string, array<string>>
     */
    protected function specRoutes(string $specPath) : array
    {
        $parsed = Yaml::parseFile($specPath);
        $paths  = Arr::get($parsed, 'paths', []);

        $routes = [];
        foreach ($paths as $path => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $methods = [];
            foreach (array_keys($definition) as $method) {
                $method = strtolower((string) $method);
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                    $methods[] = strtoupper($method);
                }
            }
            $methods = array_values(array_unique($methods));
            sort($methods);
            if (! empty($methods)) {
                // Same parameter-name collapse applied to spec paths.
                $routes[preg_replace('/\{[^}]+\}/', '{param}', $path)] = $methods;
            }
        }

        return $routes;
    }

    /**
     * Paths present in both but with differing method sets.
     *
     * @param  array<string, array<string>>  $app
     * @param  array<string, array<string>>  $spec
     * @return array<string, array{app: array<string>, spec: array<string>}>
     */
    protected function methodMismatches(array $app, array $spec) : array
    {
        $both       = array_intersect_key($app, $spec);
        $mismatches = [];
        foreach ($both as $path => $methods) {
            if ($methods != $spec[$path]) {
                $mismatches[$path] = ['app' => $methods, 'spec' => $spec[$path]];
            }
        }

        return $mismatches;
    }
}
