<?php

declare(strict_types=1);

namespace MemoryPolicyAudit;

use Closure;
use Fedoseev\Hindsight\Responses\RecallResponse;
use Fedoseev\Hindsight\Responses\RecallResult;

final class RecallContext
{
    /** @param (Closure(array): void)|null $onDecision Receives the replay record before the model call. */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Audit $audit,
        private readonly ?string $asOf = null,
        private readonly ?string $knownOn = null,
        private readonly ?Closure $onDecision = null,
    ) {
    }

    public function __invoke(RecallResponse $response): ?string
    {
        if ($response->isEmpty()) {
            return null;
        }

        $recall = ['results' => array_map(static fn (RecallResult $fact): array => [
            'id' => $fact->id,
            'document_id' => $fact->documentId,
            'text' => $fact->text,
        ], $response->results)];
        $report = $this->audit->run(
            $this->catalog,
            $recall,
            $this->asOf ?? gmdate('Y-m-d'),
            $this->knownOn,
        );
        if ($this->onDecision !== null) {
            ($this->onDecision)(DecisionRecord::capture($this->catalog, $recall, $report));
        }

        $context = $report['context'];
        if ($context === []) {
            return null;
        }

        return implode("\n", array_map(static fn (array $fact): string => '- '.$fact['text'].' ('.$fact['citation'].')', $context));
    }
}
