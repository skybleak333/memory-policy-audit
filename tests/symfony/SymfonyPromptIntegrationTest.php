<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Testing\HindsightFake;
use Fedoseev\Hindsight\Symfony\Ai\HindsightMemory;
use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\RecallContext;
use MemoryPolicyAudit\Relate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Memory\MemoryInputProcessor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\MockPlatformFactory;

require_once __DIR__.'/../PolicyScenario.php';

final class SymfonyPromptIntegrationTest extends TestCase
{
    #[DataProvider('recallScenarios')]
    public function test_the_model_receives_the_published_version_when_recall_only_finds_the_old_rule(
        bool $live,
        string $knownOn,
        int $days,
        string $revision,
        int $excludedDays,
        string $excludedRevision,
    ): void {
        $client = $live ? (new Hindsight(PolicyScenario::url()))->withTimeout(60) : new HindsightFake;
        $bank = 'memory-policy-symfony-'.bin2hex(random_bytes(8));

        try {
            PolicyScenario::seed($client, $bank, staleOnly: true);
            $policies = json_decode(file_get_contents(__DIR__.'/../../examples/policies.json'), true, flags: JSON_THROW_ON_ERROR);
            $context = new RecallContext(
                new Catalog($policies),
                new Audit(new Relate(getenv('RELATE_BIN') ?: 'relate')),
                asOf: '2026-11-05',
                knownOn: $knownOn,
            );
            $memory = new HindsightMemory($client, $bank, retain: false, timeout: 60, throw: true, recallFormatter: $context(...));
            $platform = MockPlatformFactory::createPlatform(function ($model, $payload) use ($days, $revision, $excludedDays, $excludedRevision): string {
                $prompt = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $this->assertStringContainsString('Conversation Memory', $prompt);
                $this->assertStringContainsString("Annual plans have a {$days}-day refund window. (https://policies.example/refunds/annual/{$revision})", $prompt);
                $this->assertStringNotContainsString('30-day', $prompt);
                $this->assertStringNotContainsString('https://policies.example/refunds/annual/v1', $prompt);
                $this->assertStringNotContainsString("{$excludedDays}-day", $prompt);
                $this->assertStringNotContainsString("https://policies.example/refunds/annual/{$excludedRevision}", $prompt);

                return 'See the cited refund policy.';
            });
            $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);
            $result = $agent->call(new MessageBag(
                Message::forSystem('Answer from the approved refund policy and cite the source.'),
                Message::ofUser('What is the refund deadline for annual plans?'),
            ));

            $this->assertSame('See the cited refund policy.', $result->getContent());
        } finally {
            try {
                $client->deleteBank($bank);
            } catch (NotFoundException) {
                // A failure before retain did not create a bank.
            }
        }
    }

    public static function recallScenarios(): array
    {
        return [
            'fake before publication' => [false, '2026-11-05', 21, 'v2', 14, 'v3'],
            'fake after publication' => [false, '2026-11-10', 14, 'v3', 21, 'v2'],
            'live before publication' => [true, '2026-11-05', 21, 'v2', 14, 'v3'],
            'live after publication' => [true, '2026-11-10', 14, 'v3', 21, 'v2'],
        ];
    }
}
