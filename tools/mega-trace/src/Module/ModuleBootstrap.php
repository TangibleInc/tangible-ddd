<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tangible\Cred\MegaTrace\Domain\Behaviours\PrepareCredentialArtifacts;
use Tangible\Cred\MegaTrace\Domain\Behaviours\ReviewIssuanceEvidence;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;

use function TangibleDDD\WordPress\boot_module;

final class ModuleBootstrap
{
    /** @var array<string, ContainerBuilder> */
    private array $containers = [];

    /** @var list<string> */
    private array $missing_hosts = [];

    private readonly ScenarioFactObserver $observer;

    public function __construct(
        private readonly ModuleContainerFactory $factory,
        ?ScenarioFactObserver $observer = null,
    ) {
        $this->observer = $observer ?? new ScenarioFactObserver();
    }

    public function register(): void
    {
        BaseBehaviourConfig::register_type(ReviewIssuanceEvidence::TYPE, ReviewIssuanceEvidence::class);
        BaseBehaviourConfig::register_type(PrepareCredentialArtifacts::TYPE, PrepareCredentialArtifacts::class);

        $hosts = ConsumerRegistry::all();
        foreach (ModuleManifest::definitions() as $module) {
            if (!isset($hosts[$module->host_prefix])) {
                $this->missing_hosts[] = $module->host_prefix;
                continue;
            }

            boot_module(
                $module->host_prefix,
                $module->namespace_root,
                fn (): ContainerBuilder => $this->container($module),
            );
            $this->observer->register($module);
        }
    }

    /** @return list<string> */
    public function missing_hosts(): array
    {
        return array_values(array_unique($this->missing_hosts));
    }

    private function container(ModuleDefinition $module): ContainerBuilder
    {
        return $this->containers[$module->namespace_root]
            ??= $this->factory->build($module);
    }
}
