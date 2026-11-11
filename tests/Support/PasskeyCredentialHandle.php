<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Ceremony handle over one converted credential: produces assertion payloads
 * for successive challenges with an incrementing signature counter.
 */
class PasskeyCredentialHandle
{
    protected int $counter = 1;

    public function __construct(
        public readonly string $credentialId,
        protected readonly OpenSSLAsymmetricKey $factoryKey,
    ) {
    }

    /**
     * Build a valid assertion payload for the given challenge.
     *
     * @return array{credential: array, credentialId: string}
     */
    public function assertion(string $challengeB64Url) : array
    {
        $assertion = $this->build($this->factoryKey, $challengeB64Url, $this->counter);

        $this->counter++;

        return $assertion;
    }

    /**
     * Assertion signed by a DIFFERENT keypair (must be rejected): same
     * credential id, attacker key.
     *
     * @return array{credential: array, credentialId: string}
     */
    public function wrongKeyAssertion(string $challengeB64Url) : array
    {
        return $this->build(PasskeyAssertionFactory::freshEs256Key(), $challengeB64Url, $this->counter);
    }

    /**
     * @return array{credential: array, credentialId: string}
     */
    protected function build(OpenSSLAsymmetricKey $key, string $challengeB64Url, int $counter) : array
    {
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challengeB64Url,
            'origin' => config('app.url'),
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authenticatorData = self::buildAuthenticatorData($counter);

        $signedData = $authenticatorData.hash('sha256', $clientDataJson, true);

        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        return [
            'credential' => [
                'id' => $this->credentialId,
                'rawId' => $this->credentialId,
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                    'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                    'signature' => Base64UrlSafe::encodeUnpadded($signature),
                    'userHandle' => null,
                ],
            ],
            'credentialId' => $this->credentialId,
        ];
    }

    /**
     * Minimal authenticator data: rpIdHash + UP|UV flags + counter.
     */
    protected static function buildAuthenticatorData(int $counter) : string
    {
        $rpIdHash = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true);

        return $rpIdHash.chr(0x05).pack('N', $counter);
    }
}
