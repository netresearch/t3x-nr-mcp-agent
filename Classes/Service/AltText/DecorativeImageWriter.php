<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Domain\Repository\ImageReferenceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sets or clears the "decorative image" mark of one image reference
 * (`sys_file_reference.tx_nrmcpagent_decorative`, ADR-026) through the
 * DataHandler as the given backend user, in the live workspace only, and
 * reads it back. Asked first, the way nr-llm's alt text writer asks:
 * ReferenceWriteAccess.
 *
 * The one write behind the tool `set_file_reference_decorative`, so the model's
 * call and anything else that marks an image go through the same checks and
 * leave the same record history.
 */
class DecorativeImageWriter
{
    public const COLUMN = 'tx_nrmcpagent_decorative';

    private const TABLE = 'sys_file_reference';

    public function __construct(
        private readonly ReferenceWriteAccess $access,
        private readonly ImageReferenceRepository $rows,
    ) {}

    /**
     * What the write would do, or the refusal. The workspace is not asked:
     * it describes the process that writes, not the reference.
     */
    public function plan(BackendUserAuthentication $user, int $referenceUid): WritableReference|string
    {
        return $this->access->resolve($user, $referenceUid, self::COLUMN);
    }

    /**
     * Write the mark; null on success, else why nothing (or not the value)
     * was stored.
     */
    public function write(BackendUserAuthentication $user, int $referenceUid, bool $decorative): ?string
    {
        if ($user->workspace !== 0) {
            return 'Refused: the decorative mark is set in the live workspace only. Switch out of the draft workspace and retry.';
        }

        $plan = $this->plan($user, $referenceUid);
        if (is_string($plan)) {
            return $plan;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$referenceUid => [self::COLUMN => $decorative ? 1 : 0]]], [], $user);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            return 'The update was refused by TYPO3: ' . implode(' / ', array_map(
                static fn (mixed $error): string => mb_substr(is_scalar($error) ? (string) $error : '', 0, 200),
                array_slice($dataHandler->errorLog, 0, 5),
            ));
        }

        // An empty error log is no proof the value landed: a hook can drop
        // it in silence.
        if ($this->rows->decorativeMark($referenceUid) !== $decorative) {
            return sprintf('The decorative mark did not take on file reference [%d]: the DataHandler dropped or changed the value.', $referenceUid);
        }

        return null;
    }
}
