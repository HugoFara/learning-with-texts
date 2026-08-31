<?php

/**
 * Generate Recovery Code Use Case.
 *
 * Issues a fresh one-time recovery code for a user, stores its hash, and
 * returns the plaintext to be shown to the user exactly once.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\UseCases;

use Lwt\Modules\User\Application\Services\RecoveryCodeService;
use Lwt\Modules\User\Domain\User;
use Lwt\Modules\User\Domain\UserRepositoryInterface;

/**
 * Issue and persist a new recovery code for a user.
 *
 * @since 3.1.2
 */
class GenerateRecoveryCode
{
    private UserRepositoryInterface $repository;
    private RecoveryCodeService $recoveryCodes;

    public function __construct(
        UserRepositoryInterface $repository,
        ?RecoveryCodeService $recoveryCodes = null
    ) {
        $this->repository = $repository;
        $this->recoveryCodes = $recoveryCodes ?? new RecoveryCodeService();
    }

    /**
     * Generate, store, and return a new recovery code (plaintext, shown once).
     *
     * @param User $user The user to issue a code for.
     *
     * @return string The plaintext recovery code to display to the user.
     */
    public function execute(User $user): string
    {
        $generated = $this->recoveryCodes->generate();
        $user->setRecoveryCodeHash($generated['hash']);
        $this->repository->save($user);
        return $generated['code'];
    }
}
