<?php

/**
 * Recovery Code Service.
 *
 * Generates and verifies one-time account recovery codes for users who
 * registered without an email (their only password-recovery channel).
 *
 * A code is high-entropy random, shown to the user once, and stored only as a
 * SHA-256 hash (same model as the email reset token). Display is grouped with
 * dashes for readability; verification canonicalises input (uppercase,
 * non-alphanumerics stripped) so the user can type it with or without the
 * dashes or in any case.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

/**
 * Generates and verifies one-time recovery codes.
 *
 * @since 3.1.2
 */
class RecoveryCodeService
{
    /** Random bytes of entropy per code (10 bytes = 20 hex chars = 80 bits). */
    private const ENTROPY_BYTES = 10;

    /** Characters per dash-separated group in the displayed code. */
    private const GROUP_SIZE = 5;

    private TokenHasher $tokenHasher;

    public function __construct(?TokenHasher $tokenHasher = null)
    {
        $this->tokenHasher = $tokenHasher ?? new TokenHasher();
    }

    /**
     * Generate a new recovery code.
     *
     * @return array{code: string, hash: string} The human-readable code to show
     *   the user once, and the hash to store.
     */
    public function generate(): array
    {
        $raw = strtoupper(bin2hex(random_bytes(self::ENTROPY_BYTES)));
        return [
            'code' => $this->format($raw),
            'hash' => $this->tokenHasher->hash($raw),
        ];
    }

    /**
     * Verify a user-entered code against a stored hash.
     *
     * @param string $input The code as typed by the user (any case/spacing).
     * @param string $hash  The stored hash to check against.
     */
    public function verify(string $input, string $hash): bool
    {
        $canonical = $this->canonicalize($input);
        if ($canonical === '') {
            return false;
        }
        return $this->tokenHasher->verify($canonical, $hash);
    }

    /** Group a raw code into dash-separated chunks for display. */
    private function format(string $raw): string
    {
        $chunks = str_split($raw, self::GROUP_SIZE);
        return implode('-', $chunks);
    }

    /** Reduce user input to the canonical form the hash is computed over. */
    private function canonicalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }
}
