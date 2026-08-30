<?php

/**
 * ALTCHA proof-of-work captcha service.
 *
 * Self-hosted, dependency-free implementation of the ALTCHA protocol
 * (https://altcha.org): the server issues a challenge, the browser solves a
 * small SHA-256 proof-of-work, and the server verifies the solution. There is
 * no third-party service and no personal data — it just makes automated mass
 * sign-ups computationally expensive while staying invisible to real users.
 *
 * The challenge is stateless: an HMAC signature binds it to this server's
 * secret key, so a client cannot forge a challenge (e.g. one with trivial
 * work). Challenges also carry an expiry. Replaying a solved challenge is
 * bounded by the existing auth rate limiter, so no server-side nonce store is
 * needed here.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://altcha.org/docs/
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

/**
 * Issues and verifies ALTCHA proof-of-work challenges.
 *
 * @since 3.1.2
 */
class AltchaService
{
    /** Hash algorithm advertised to and required from the client. */
    private const ALGORITHM = 'SHA-256';

    /** The same algorithm as a PHP hash()/hash_hmac() identifier. */
    private const ALGORITHM_ID = 'sha256';

    /**
     * Upper bound for the secret number the client must brute-force. Larger =
     * more work per attempt. ~50k keeps a real user's solve well under a second
     * while still costing a bot meaningfully at scale.
     */
    private const MAX_NUMBER = 50000;

    /** How long an issued challenge stays valid, in seconds. */
    private const TTL_SECONDS = 600;

    private string $hmacKey;
    private bool $enabled;

    /**
     * @param string $hmacKey Secret key used to sign/verify challenges.
     * @param bool   $enabled When false, verification is skipped (returns true)
     *                        and the challenge endpoint advertises "disabled".
     */
    public function __construct(string $hmacKey, bool $enabled = true)
    {
        $this->hmacKey = $hmacKey;
        $this->enabled = $enabled;
    }

    /**
     * Build a service from environment configuration.
     *
     * - ALTCHA_ENABLED (default true) toggles the feature.
     * - ALTCHA_HMAC_KEY supplies the signing secret. When unset, a random key
     *   is generated once and persisted to the system temp dir so challenges
     *   remain verifiable across requests/workers. Production installs should
     *   set ALTCHA_HMAC_KEY explicitly for a stable, backed-up secret.
     */
    public static function fromEnvironment(): self
    {
        // Default ON; only an explicit falsy value (false/0/no/off) disables it.
        $enabledRaw = $_ENV['ALTCHA_ENABLED'] ?? getenv('ALTCHA_ENABLED');
        $enabled = ($enabledRaw === false || $enabledRaw === '')
            ? true
            : filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);

        $envKey = $_ENV['ALTCHA_HMAC_KEY'] ?? getenv('ALTCHA_HMAC_KEY');
        $key = is_string($envKey) ? trim($envKey) : '';
        if ($key === '') {
            $key = self::loadOrCreatePersistedKey();
        }

        return new self($key, $enabled);
    }

    /**
     * Whether challenges are being enforced.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Create a fresh challenge for the client to solve.
     *
     * @return array{algorithm: string, challenge: string, maxnumber: int,
     *               salt: string, signature: string}
     */
    public function createChallenge(): array
    {
        $expires = time() + self::TTL_SECONDS;
        // Salt carries the expiry as a query string (an ALTCHA convention), so
        // verification is fully stateless.
        $salt = bin2hex(random_bytes(12)) . '?expires=' . $expires;
        $secretNumber = random_int(0, self::MAX_NUMBER);

        $challenge = hash(self::ALGORITHM_ID, $salt . $secretNumber);
        $signature = hash_hmac(self::ALGORITHM_ID, $challenge, $this->hmacKey);

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => self::MAX_NUMBER,
            'salt' => $salt,
            'signature' => $signature,
        ];
    }

    /**
     * Verify a base64-encoded solution payload sent back by the client.
     *
     * @param string $payloadBase64 Base64 of the JSON {algorithm, challenge,
     *                              number, salt, signature} returned by the
     *                              ALTCHA widget/solver.
     *
     * @return bool True if the solution is valid (or the feature is disabled).
     */
    public function verify(string $payloadBase64): bool
    {
        if (!$this->enabled) {
            return true;
        }
        if ($payloadBase64 === '') {
            return false;
        }

        $json = base64_decode($payloadBase64, true);
        if ($json === false) {
            return false;
        }
        /** @var mixed $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return false;
        }

        $algorithm = (string) ($payload['algorithm'] ?? '');
        $challenge = (string) ($payload['challenge'] ?? '');
        $salt = (string) ($payload['salt'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');
        $number = $payload['number'] ?? null;
        if (
            $algorithm !== self::ALGORITHM || $challenge === '' || $salt === ''
            || $signature === '' || !is_int($number)
        ) {
            return false;
        }

        // The challenge must be the hash the client claims (proof of work) ...
        $expectedChallenge = hash(self::ALGORITHM_ID, $salt . $number);
        if (!hash_equals($expectedChallenge, $challenge)) {
            return false;
        }

        // ... and it must be one this server actually signed (not forged) ...
        $expectedSignature = hash_hmac(self::ALGORITHM_ID, $expectedChallenge, $this->hmacKey);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // ... and not expired.
        return !$this->isExpired($salt);
    }

    /**
     * Whether the salt's embedded `expires` timestamp is in the past. A salt
     * without an expiry is treated as valid (never expires).
     */
    private function isExpired(string $salt): bool
    {
        $query = strstr($salt, '?');
        if ($query === false) {
            return false;
        }
        parse_str(ltrim($query, '?'), $params);
        if (!isset($params['expires'])) {
            return false;
        }
        return (int) $params['expires'] < time();
    }

    /**
     * Load the persisted auto-generated HMAC key, creating it on first use.
     *
     * Used only when ALTCHA_HMAC_KEY is not configured. The key must be shared
     * across requests/workers, so it lives in a file rather than memory.
     */
    private static function loadOrCreatePersistedKey(): string
    {
        $dir = sys_get_temp_dir() . '/lwt_altcha';
        $file = $dir . '/hmac.key';

        $existing = @file_get_contents($file);
        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $key = bin2hex(random_bytes(32));
        @file_put_contents($file, $key, LOCK_EX);
        @chmod($file, 0600);

        // Re-read in case a concurrent worker wrote first, so everyone agrees.
        $written = @file_get_contents($file);
        return is_string($written) && trim($written) !== '' ? trim($written) : $key;
    }
}
