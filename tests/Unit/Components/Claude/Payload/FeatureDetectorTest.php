<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload;

use App\Components\Claude\Payload\FeatureDetector;
use App\Components\Claude\Payload\PayloadInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeatureDetectorTest extends TestCase
{
    private FeatureDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new FeatureDetector(new PayloadInspector);
    }

    #[Test]
    public function detect_empty_payload_returns_empty_features(): void
    {
        $this->assertSame([], $this->detector->detect([]));
    }

    #[Test]
    public function detect_thinking_feature(): void
    {
        $this->assertContains('thinking', $this->detector->detect(['thinking' => ['type' => 'enabled']]));
    }

    #[Test]
    public function detect_web_search_tool(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'web_search']]]);
        $this->assertContains('web_search', $features);
    }

    #[Test]
    public function detect_web_search_with_suffix(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'web_search_20250305']]]);
        $this->assertContains('web_search', $features);
    }

    #[Test]
    public function detect_code_execution_tool(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'code_execution']]]);
        $this->assertContains('code_execution', $features);
    }

    #[Test]
    public function detect_computer_use_tool(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'computer_20250124']]]);
        $this->assertContains('computer_use', $features);
    }

    #[Test]
    public function detect_bash_tool(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'bash']]]);
        $this->assertContains('bash', $features);
    }

    #[Test]
    public function detect_text_editor_tool(): void
    {
        $features = $this->detector->detect(['tools' => [['name' => 'text_editor']]]);
        $this->assertContains('text_editor', $features);
    }

    #[Test]
    public function detect_priority_tier(): void
    {
        $this->assertContains('priority_tier', $this->detector->detect(['service_tier' => 'auto']));
    }

    #[Test]
    public function detect_priority_tier_ignores_other_tier_values(): void
    {
        $this->assertNotContains('priority_tier', $this->detector->detect(['service_tier' => 'standard']));
    }

    #[Test]
    public function detect_citations_when_enabled(): void
    {
        $this->assertContains('citations', $this->detector->detect(['citations' => ['enabled' => true]]));
    }

    #[Test]
    public function detect_no_citations_when_disabled(): void
    {
        $this->assertNotContains('citations', $this->detector->detect(['citations' => ['enabled' => false]]));
    }

    #[Test]
    public function detect_prompt_caching_top_level(): void
    {
        $features = $this->detector->detect(['cache_control' => ['type' => 'ephemeral']]);
        $this->assertContains('prompt_caching', $features);
    }

    #[Test]
    public function detect_prompt_caching_in_system(): void
    {
        $features = $this->detector->detect([
            'system' => [
                ['type' => 'text', 'text' => 'sys', 'cache_control' => ['type' => 'ephemeral']],
            ],
        ]);
        $this->assertContains('prompt_caching', $features);
    }

    #[Test]
    public function detect_prompt_caching_in_message_content(): void
    {
        $features = $this->detector->detect([
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'hello', 'cache_control' => ['type' => 'ephemeral']],
                ]],
            ],
        ]);
        $this->assertContains('prompt_caching', $features);
    }

    #[Test]
    public function detect_structured_outputs(): void
    {
        $this->assertContains('structured_outputs', $this->detector->detect(['output_config' => ['type' => 'json']]));
    }

    #[Test]
    public function detect_multiple_features_uniquely(): void
    {
        $features = $this->detector->detect([
            'thinking' => ['type' => 'enabled'],
            'tools' => [
                ['name' => 'web_search'],
                ['name' => 'web_search_20250305'],
                ['name' => 'bash'],
            ],
            'service_tier' => 'auto',
            'cache_control' => ['type' => 'ephemeral'],
        ]);

        $this->assertSame($features, array_values(array_unique($features)));
        $this->assertContains('thinking', $features);
        $this->assertContains('web_search', $features);
        $this->assertContains('bash', $features);
        $this->assertContains('priority_tier', $features);
        $this->assertContains('prompt_caching', $features);
    }
}
