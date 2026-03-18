<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

interface ChatCapabilitiesInterface
{
    /**
     * @return array{visionSupported: bool, maxFileSize: int, supportedFormats: list<string>}
     */
    public function getProviderCapabilities(): array;
}
