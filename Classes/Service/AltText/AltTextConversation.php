<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * A conversation about one missing alt text (ADR-026): what the chat writes
 * into it, what the model reads of it, and which card it waits on.
 *
 * The transcript holds the chat's own lines as notices with arguments, so
 * the reader sees them in their language and the model never reads them:
 * the image card ("Alt-Text ergänzen"), the divider when the hit changes,
 * the editor's chip "Überspringen", and the confirmations. The chip "Als
 * dekorativ markieren" is the one the model reads: it asks for the mark. The model reads the latest image card as the image's
 * context, re-read from the database on every turn, with the scaled image
 * where it may see it.
 */
class AltTextConversation
{
    /** The image card, the chat's first message about an image. Data: the card's lines. */
    public const NOTICE_HIT = 'altTextHit';

    /** "Treffer gewechselt · {Datei} · {Seite bzw. Datensatz}". */
    public const NOTICE_SWITCHED = 'altTextSwitched';

    /** "Alt-Text gespeichert." */
    public const NOTICE_SAVED = 'altTextSaved';

    /** "Als dekorativ markiert." */
    public const NOTICE_DECORATIVE = 'altTextDecorative';

    /** "Übersprungen. Der Treffer bleibt in der Liste offen." */
    public const NOTICE_SKIPPED = 'altTextSkipped';

    /** The editor's chip, as their message; argument: `skip`. */
    public const NOTICE_ACTION = 'altTextAction';

    /**
     * "Als dekorativ markieren": the editor's request for the mark, which the
     * reader sees as the chip and the model reads as the request to propose
     * it through the tool's approval card.
     */
    public const NOTICE_ASK_DECORATIVE = 'altTextAskDecorative';

    /** An approved proposal whose write did not land; data: the proposal. */
    public const NOTICE_NOT_SAVED = 'altTextNotSaved';

    /**
     * "Erneut anbieten": the editor's request to offer that proposal again,
     * which the model reads.
     */
    public const NOTICE_RETRY = 'altTextRetry';

    /** The chat's lines the model does not read. */
    private const READER_ONLY = [self::NOTICE_SWITCHED, self::NOTICE_SAVED, self::NOTICE_DECORATIVE, self::NOTICE_SKIPPED, self::NOTICE_ACTION, self::NOTICE_NOT_SAVED];

    /** The card is a proposal of an alt text. */
    public const CARD_PROPOSAL = 'proposal';

    /** The card is the model's proposal to mark the image decorative (the specification's case 1). */
    public const CARD_DECORATIVE = 'decorative';

    public function __construct(
        private readonly MissingAltTextScanner $scanner,
        private readonly MissingAltTextList $list,
        private readonly AltTextContext $context,
        private readonly AltTextImage $image,
        // Lazy: the gate reaches ChatService through ToolAvailabilityInterface,
        // and ChatService needs this class.
        #[Autowire(lazy: true)]
        private readonly ?AltTextGate $gate = null,
    ) {}

    public static function isAbout(Conversation $conversation): bool
    {
        return $conversation->getAltTextHit()['reference'] > 0;
    }

    /**
     * The conversation takes up the hit: the card goes into the transcript,
     * after the divider when it replaces another hit, and the conversation
     * remembers the hit and its place in the list.
     *
     * @param bool $vision whether the provider can see images
     */
    public function takeUp(Conversation $conversation, MissingAltText $hit, bool $vision): void
    {
        $card = $this->list->describe($hit);
        // The title follows the image while it is the one this flow gave
        // the conversation; a title the editor chose stays.
        $title         = $conversation->getTitle();
        $previousImage = $this->latestCardFileName($conversation);
        $followsImage  = $title === '' || ($previousImage !== null && $title === mb_substr($this->title($previousImage), 0, 255));

        if (self::isAbout($conversation)) {
            $conversation->appendMessage(
                MessageRole::Assistant,
                sprintf('[The editor switched to another image: %s.]', $hit->fileName),
                self::NOTICE_SWITCHED,
                [$card['fileName'], $card['subject']],
            );
        }

        $conversation->appendMessage(
            MessageRole::Assistant,
            sprintf('[Image card: reference %d, file "%s".]', $hit->referenceUid, $hit->fileName),
            self::NOTICE_HIT,
            [],
            [...$card, 'reference' => $hit->referenceUid, ...$this->image->cardNote($hit->referenceUid, $vision)],
        );
        $conversation->setAltTextHit($hit->referenceUid, $this->list->position($hit));
        if ($followsImage) {
            $conversation->setTitle($this->title($hit->fileName));
        }
    }

    /** The conversation's title for an image, "Alt-Text: <file name>". */
    private function title(string $fileName): string
    {
        $language = $GLOBALS['LANG'] ?? null;
        $title    = $language instanceof LanguageService ? $language->sL('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:altText.title') : '';

        return sprintf($title !== '' ? $title : 'Alt text: %s', $fileName);
    }

    /** The file name on the conversation's latest image card, null before the first. */
    private function latestCardFileName(Conversation $conversation): ?string
    {
        $fileName = null;
        foreach ($conversation->getDecodedMessages() as $message) {
            $data = $message['data'] ?? null;
            if (($message['notice'] ?? null) === self::NOTICE_HIT && is_array($data) && is_string($data['fileName'] ?? null)) {
                $fileName = $data['fileName'];
            }
        }

        return $fileName;
    }

    /**
     * The open hit of the conversation's image for the current user, null
     * when it is fixed or no longer theirs.
     */
    public function openHit(Conversation $conversation, ?BackendUserAuthentication $user = null): ?MissingAltText
    {
        $reference = $conversation->getAltTextHit()['reference'];
        if ($reference <= 0) {
            return null;
        }

        $scan = $user instanceof BackendUserAuthentication ? $this->scanner->scan($user) : $this->scanner->scanForCurrentUser();

        return $scan->find($reference);
    }

    /**
     * The open hit after the conversation's image in list order, across the
     * tabs; null when no other hit is open.
     */
    public function next(Conversation $conversation, BackendUserAuthentication $user): ?MissingAltText
    {
        $hit = $conversation->getAltTextHit();
        if ($hit['reference'] <= 0) {
            return null;
        }

        $scan     = $this->scanner->scan($user);
        $position = $hit['position'];
        if ($position === null) {
            $current  = $scan->find($hit['reference']);
            $position = $current instanceof MissingAltText ? $this->list->position($current) : ['type' => MissingAltTextScan::PAGES, 'label' => '', 'key' => []];
        }

        return $this->list->next($scan, $position, $hit['reference']);
    }

    /**
     * Where a hit is listed, for the list to show it.
     *
     * @return array{tab: string, page: int}
     */
    public function locate(MissingAltText $hit, BackendUserAuthentication $user): array
    {
        return $this->list->locate($this->scanner->scan($user), $hit);
    }

    /**
     * The transcript as the model reads it. The chat's own lines are left
     * out; the latest card of the current image becomes the image's context,
     * read again now, with the scaled image where it may be sent; an earlier
     * card becomes a line saying the editor moved on.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function transcript(Conversation $conversation, array $messages, bool $vision): array
    {
        $reference = $conversation->getAltTextHit()['reference'];
        $latest    = null;
        foreach ($messages as $index => $message) {
            if (($message['notice'] ?? null) === self::NOTICE_HIT && $this->referenceOf($message) === $reference) {
                $latest = $index;
            }
        }

        $result = [];
        foreach ($messages as $index => $message) {
            $notice = $message['notice'] ?? null;
            if (in_array($notice, self::READER_ONLY, true)) {
                continue;
            }

            if ($notice !== self::NOTICE_HIT) {
                $result[] = $message;
                continue;
            }

            $data     = is_array($message['data'] ?? null) ? $message['data'] : [];
            $fileName = is_string($data['fileName'] ?? null) ? $data['fileName'] : '';
            // An image the card says the privacy setting keeps back is never
            // sent, also by a worker that started before the setting changed
            // and still reads the old one.
            $withheld = in_array($data['imageNote'] ?? null, [ImageAnalysisPolicy::NOTE_ANALYSIS_OFF, ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER], true);
            $result[] = $index === $latest
                ? ['role' => MessageRole::User->value, 'content' => $this->context($reference, $vision && !$withheld)]
                : ['role' => MessageRole::User->value, 'content' => sprintf('[An earlier image of this conversation, "%s". The editor has moved on to another image; do not work on it any more.]', $fileName)];
        }

        return $result;
    }

    /**
     * The proposal of the last approved write that did not land, while it is
     * the conversation's last word; null otherwise.
     */
    public function proposalToOfferAgain(Conversation $conversation): ?string
    {
        $messages = $conversation->getDecodedMessages();
        $last     = $messages[count($messages) - 1] ?? null;
        if (!is_array($last) || ($last['notice'] ?? null) !== self::NOTICE_NOT_SAVED) {
            return null;
        }

        $data     = is_array($last['data'] ?? null) ? $last['data'] : [];
        $proposal = is_string($data['proposal'] ?? null) ? trim($data['proposal']) : '';

        return $proposal !== '' ? $proposal : null;
    }

    /**
     * The card the conversation's run waits on, when it is one of this flow:
     * one call, on the conversation's image, of nr-llm's alt text writer
     * (a proposal) or of the decorative mark set to true (case 1).
     *
     * @return array{kind: string, proposal: string}|null
     */
    public function card(Conversation $conversation, ?WaitingRunView $view): ?array
    {
        if (!$view instanceof WaitingRunView || !self::isAbout($conversation) || count($view->pendingCalls) !== 1) {
            return null;
        }

        $call      = $view->pendingCalls[0];
        $arguments = json_decode($call->argumentsJson, true);
        if (!is_array($arguments) || $this->referenceArgument($arguments) !== $conversation->getAltTextHit()['reference']) {
            return null;
        }

        return match (true) {
            $call->name === AltTextFlowPrompt::ALT_TEXT_TOOL && is_string($arguments['alternative'] ?? null) => ['kind' => self::CARD_PROPOSAL, 'proposal' => trim($arguments['alternative'])],
            $call->name === SetDecorativeImageTool::NAME && ($arguments['decorative'] ?? null) === true      => ['kind' => self::CARD_DECORATIVE, 'proposal' => ''],
            default                                                                                          => null,
        };
    }

    /**
     * Whether the run waits on a call of the flow's two tools for another
     * image than the conversation's. Only the system prompt binds the model
     * to the conversation's reference; such a call is not approved here.
     */
    public function callsAnotherImage(Conversation $conversation, ?WaitingRunView $view): bool
    {
        if (!$view instanceof WaitingRunView || !self::isAbout($conversation)) {
            return false;
        }

        $reference = $conversation->getAltTextHit()['reference'];
        foreach ($view->pendingCalls as $call) {
            if (!in_array($call->name, [AltTextFlowPrompt::ALT_TEXT_TOOL, SetDecorativeImageTool::NAME], true)) {
                continue;
            }

            $arguments = json_decode($call->argumentsJson, true);
            if (!is_array($arguments) || $this->referenceArgument($arguments) !== $reference) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any call the run waits on sets the decorative mark on the
     * conversation's image, however many calls the run waits on.
     */
    public function marksItsImageDecorative(Conversation $conversation, ?WaitingRunView $view): bool
    {
        if (!$view instanceof WaitingRunView || !self::isAbout($conversation)) {
            return false;
        }

        $reference = $conversation->getAltTextHit()['reference'];
        foreach ($view->pendingCalls as $call) {
            $arguments = json_decode($call->argumentsJson, true);
            if ($call->name === SetDecorativeImageTool::NAME && is_array($arguments)
                && $this->referenceArgument($arguments) === $reference && ($arguments['decorative'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The call's reference as a uid, also when the model passed it as a
     * numeric string; null for anything else.
     *
     * @param array<mixed> $arguments
     */
    private function referenceArgument(array $arguments): ?int
    {
        $reference = $arguments['reference'] ?? null;

        return is_numeric($reference) ? (int) $reference : null;
    }

    /**
     * The user message that carries the image's context, and the image.
     *
     * @return string|list<array<string, mixed>>
     */
    private function context(int $reference, bool $vision): string|array
    {
        $hit = $this->scanner->scanForCurrentUser()->find($reference);
        if (!$hit instanceof MissingAltText) {
            return sprintf("<image_context>\nThe image use (reference %d) is no longer open: it has an alt text or a decorative mark by now, or the editor may no longer change it.\n</image_context>", $reference);
        }

        $block = $this->image->modelBlock($reference, $vision);
        $user  = $GLOBALS['BE_USER'] ?? null;
        $mark  = $user instanceof BackendUserAuthentication && $this->gate instanceof AltTextGate && $this->gate->mayMarkDecorative($user, $reference);
        $text  = $this->context->describe($hit, $block !== null, $mark);

        return $block === null ? $text : [['type' => 'text', 'text' => $text], $block];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function referenceOf(array $message): int
    {
        $data = $message['data'] ?? null;

        return is_array($data) && is_int($data['reference'] ?? null) ? $data['reference'] : 0;
    }
}
