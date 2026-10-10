<?php
declare(strict_types=1);

namespace FloCMS\CLI\Routing;

use FloCMS\CLI\Project;

/**
 * Lets a package add its routes to `php flo route:list`. Register the class
 * in composer.json:
 *
 *     "extra": {"flocms": {"route-providers": ["Vendor\\Package\\Console\\Routes"]}}
 */
interface RouteProvider
{
    /** A short label for the source column, e.g. "api". */
    public function source(): string;

    /**
     * @return iterable<array{method: string, uri: string, name?: string|null, action: string, permission?: string|null, middleware?: list<string>}>
     */
    public function routes(Project $project): iterable;
}
