<?php

declare(strict_types=1);

namespace App\Services\Google;

/** Source of Google's public signing keys (JWKS). Swappable for tests. */
interface JwksProvider
{
    /** @return array<string, array{kty:string,n:string,e:string,alg?:string}> keyed by kid */
    public function keys(bool $forceRefresh = false): array;
}
