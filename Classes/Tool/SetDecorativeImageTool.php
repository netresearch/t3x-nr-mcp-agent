<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrMcpAgent\Service\AltText\DecorativeImageWriter;
use Netresearch\NrMcpAgent\Service\AltText\DecorativeWarning;
use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Service\AltText\WritableReference;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Mark one use of an image as decorative, or take the mark off (ADR-026):
 * `sys_file_reference.tx_nrmcpagent_decorative`, which the frontend renders
 * as `alt=""` and the check for missing alt texts counts as done.
 *
 * A tool of this extension, registered with nr-llm's tool registry like the
 * guided-state tools (ADR-020): nr-llm does not know the field, and the field
 * goes away with this extension. It is a write (IDEMPOTENT_WRITE), so nr-llm
 * pauses every call for an approval card (nr-llm ADR-134); the card shows the
 * image, its record and field, and — when the image belongs to a section or a
 * record — that it probably needs an alt text (the specification's case 2).
 *
 * The checks before the card are the ones nr-llm's
 * `set_file_reference_alternative_text` asks (ReferenceWriteAccess), with the
 * grant for this column, so no card is offered that the DataHandler would
 * then refuse. Like nr-llm's writers it ships disabled, in the writers' group
 * `editing`, live workspace only.
 */
final readonly class SetDecorativeImageTool implements ToolInterface, ToolDataClassInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface
{
    public const NAME = 'set_file_reference_decorative';

    /** nr-llm's group of the content writers (nr-llm ADR-135). */
    public const GROUP = 'editing';

    private const TABLE = 'sys_file_reference';

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:';

    public function __construct(
        private DecorativeImageWriter $writer,
        private DecorativeWarning $warning,
        private LanguageServiceFactory $languageServiceFactory,
        private ResourceFactory $resourceFactory,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::NAME,
            'Mark ONE existing file reference (sys_file_reference: one use of an image) as decorative, or take the mark'
            . ' off. A decorative image is rendered with an empty alt attribute and skipped by screen readers; use it'
            . ' only for images that carry no information, such as patterns, ornaments or dividers. The mark belongs to'
            . ' this use of the image only and keeps its language. Writes through the TYPO3 DataHandler as the acting'
            . ' backend user, in the live workspace only.',
            [
                'type'       => 'object',
                'properties' => [
                    'reference'  => ['type' => 'integer', 'description' => 'The sys_file_reference uid of the single image use.'],
                    'decorative' => ['type' => 'boolean', 'description' => 'true marks the image as decorative, false takes the mark off.'],
                ],
                'required' => ['reference', 'decorative'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(ReferenceWriteAccess::NOT_PERMITTED);
        }

        $call = $this->arguments($arguments);
        if (is_string($call)) {
            return ToolResult::error($call);
        }

        $refusal = $this->writer->write($user, $call['reference'], $call['decorative']);
        if ($refusal !== null) {
            return ToolResult::error($refusal);
        }

        return ToolResult::text(sprintf(
            $call['decorative']
                ? 'File reference [%d] is marked as decorative: it is rendered with alt="" and no longer listed as missing an alt text.'
                : 'File reference [%d] is no longer marked as decorative.',
            $call['reference'],
        ));
    }

    /**
     * What the card shows, in the acting user's language: what is set, the
     * record and its page, the field, the file and, for a decorative mark on
     * an image that belongs to a section or a record, the warning.
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [ReferenceWriteAccess::NOT_PERMITTED];
        }

        $call = $this->arguments($arguments);
        if (is_string($call)) {
            return [$call];
        }

        $plan = $this->writer->plan($user, $call['reference']);
        if (is_string($plan)) {
            return [$plan];
        }

        $language = $this->languageServiceFactory->createFromUserPreferences($user);
        $lines    = [$this->text($language, $call['decorative'] ? 'tool.heading.set' : 'tool.heading.unset')];
        $lines[]  = sprintf($this->text($language, 'tool.record'), $this->tableLabel($language, $plan->table), ReferenceWriteAccess::titleOf($plan->table, $plan->record));
        if ($plan->table !== 'pages' && $plan->page !== []) {
            $lines[] = sprintf($this->text($language, 'tool.page'), ReferenceWriteAccess::titleOf('pages', $plan->page));
        }

        $lines[] = sprintf($this->text($language, 'tool.field'), $this->fieldLabel($language, $plan->table, $plan->field));
        $lines[] = sprintf($this->text($language, 'tool.file'), $this->fileName($plan));
        if ($call['decorative']) {
            $warning = $this->warning->of($plan, $language);
            if ($warning !== '') {
                $lines[] = $warning;
            }
        }

        return $lines;
    }

    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        $call = $this->arguments($arguments);

        return !is_string($call) && ($viewer->isAdmin() || !is_string($this->writer->plan($viewer, $call['reference'])));
    }

    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::TABLE, $arguments['reference'] ?? null, [DecorativeImageWriter::COLUMN]);
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (nr-llm ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // The editor's own rights decide, here and in the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        return self::GROUP;
    }

    public function getDataClass(): ToolDataClass
    {
        // It reads and names editorial records: a page, a content element, a file.
        return ToolDataClass::EDITOR_CONTENT;
    }

    public function getEffect(): ToolEffect
    {
        // One flag set to a given value converges on repeat.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * The validated arguments, or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{reference: int, decorative: bool}|string
     */
    private function arguments(array $arguments): array|string
    {
        $unknown = array_diff(array_keys($arguments), ['reference', 'decorative']);
        if ($unknown !== []) {
            return sprintf('Refused: unknown argument(s) %s. This tool takes "reference" and "decorative" only.', implode(', ', $unknown));
        }

        $reference = $arguments['reference'] ?? null;
        if (!is_int($reference) && (!is_string($reference) || preg_match('/^\d+$/', $reference) !== 1) || (int) $reference < 1) {
            return 'Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.';
        }

        $decorative = $arguments['decorative'] ?? null;
        if (!is_bool($decorative)) {
            return 'Refused: "decorative" must be true or false.';
        }

        return ['reference' => (int) $reference, 'decorative' => $decorative];
    }

    private function fileName(WritableReference $plan): string
    {
        try {
            return $this->resourceFactory->getFileReferenceObject($plan->referenceUid)->getOriginalFile()->getName();
        } catch (Throwable) {
            return '';
        }
    }

    private function tableLabel(LanguageService $language, string $table): string
    {
        $tca   = $GLOBALS['TCA'] ?? null;
        $ctrl  = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null) ? $tca[$table]['ctrl'] : [];
        $label = is_string($ctrl['title'] ?? null) ? trim($language->sL($ctrl['title'])) : '';

        return $label !== '' ? $label : $table;
    }

    private function fieldLabel(LanguageService $language, string $table, string $field): string
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $columns = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['columns'] ?? null) ? $tca[$table]['columns'] : [];
        $column  = is_array($columns[$field] ?? null) ? $columns[$field] : [];
        $label   = is_string($column['label'] ?? null) ? trim($language->sL($column['label'])) : '';

        return $label !== '' ? $label : $field;
    }

    private function text(LanguageService $language, string $key): string
    {
        $text = $language->sL(self::LL . $key);

        return $text !== '' ? $text : $key;
    }
}
