<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use Fedoseev\Hindsight\Responses\RecallResponse;
use Fedoseev\Hindsight\Responses\RecallResult;

final class RecallContext
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Audit $audit,
        private readonly ?string $asOf = null,
        private readonly ?string $knownOn = null,
    ) {
    }

    public function __invoke(RecallResponse $response): ?string
    {
        if ($response->isEmpty()) {
            return null;
        }

        $results = array_map(static fn (RecallResult $fact): array => [
            'id' => $fact->id,
            'document_id' => $fact->documentId,
            'text' => $fact->text,
        ], $response->results);
        $context = $this->audit->run(
            $this->catalog,
            ['results' => $results],
            $this->asOf ?? gmdate('Y-m-d'),
            $this->knownOn,
        )['context'];

        if ($context === []) {
            return null;
        }

        return implode("\n", array_map(static fn (array $fact): string => '- '.$fact['text'].' ('.$fact['citation'].')', $context));
    }
}
