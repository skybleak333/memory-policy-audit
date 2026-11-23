<?php

declare(strict_types=1);

use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Laravel\HindsightServiceProvider;
use Fedoseev\Hindsight\Testing\HindsightFake;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use MemoryPolicyAudit\Audit;
use MemoryPolicyAudit\Catalog;
use MemoryPolicyAudit\RecallContext;
use MemoryPolicyAudit\Relate;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__.'/../PolicyScenario.php';

final class PromptIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, HindsightServiceProvider::class];
    }

    #[DataProvider('hindsightClients')]
    public function test_the_agent_receives_only_the_current_fact_with_its_source(bool $live): void
    {
        $client = $live ? (new Hindsight(PolicyScenario::url()))->withTimeout(60) : new HindsightFake;
        $bank = 'memory-policy-laravel-'.bin2hex(random_bytes(8));

        try {
            PolicyScenario::seed($client, $bank);
            $catalog = json_decode(file_get_contents(__DIR__.'/../../examples/policies.json'), true, flags: JSON_THROW_ON_ERROR);
            $recallContext = new RecallContext(
                new Catalog($catalog),
                new Audit(new Relate(getenv('RELATE_BIN') ?: 'relate')),
                asOf: '2026-11-23',
            );

            PolicySupportAgent::fake(['Your annual plan has a 14-day refund window.']);
            $agent = new PolicySupportAgent([
                Memory::bank($bank)->using($client)->withoutRetain()->throwOnError()->formatRecallUsing($recallContext(...)),
                function (PendingStep $step, Closure $next) {
                    $instructions = $step->instructions ?? '';
                    $this->assertStringContainsString('Annual plans have a 14-day refund window.', $instructions);
                    $this->assertStringContainsString('https://policies.example/refunds/annual/v3', $instructions);
                    $this->assertStringNotContainsString('30-day', $instructions);
                    $this->assertStringNotContainsString('7-day', $instructions);
                    $this->assertStringNotContainsString('no refund deadline', $instructions);

                    return $next($step);
                },
            ]);

            $response = $agent->prompt('What is the refund deadline for annual plans?');
            $this->assertSame('Your annual plan has a 14-day refund window.', $response->text);
        } finally {
            try {
                $client->deleteBank($bank);
            } catch (NotFoundException) {
                // A failure before retain did not create a bank.
            }
        }
    }

    public static function hindsightClients(): array
    {
        return ['in-memory Hindsight' => [false], 'live Hindsight' => [true]];
    }
}

final class PolicySupportAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function __construct(private readonly array $middleware)
    {
    }

    public function instructions(): string
    {
        return 'You are a support agent. Cite the current refund policy in your answer.';
    }

    public function middleware(): array
    {
        return $this->middleware;
    }
}
