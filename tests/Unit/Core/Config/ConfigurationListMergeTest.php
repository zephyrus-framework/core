<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;

final class ConfigurationListMergeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zephyrus-list-merge-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loaders(): array
    {
        return [
            'fromYamlFiles' => ['fromYamlFiles'],
            'fromOptionalYamlFiles' => ['fromOptionalYamlFiles'],
            'fromFiles' => ['fromFiles'],
            'fromOptionalFiles' => ['fromOptionalFiles'],
        ];
    }

    /**
     * @param list<string> $paths
     */
    private function load(string $loader, array $paths): Configuration
    {
        return Configuration::$loader($paths, ['custom' => ListMergeSection::class]);
    }

    #[DataProvider('loaders')]
    public function testALaterTrustedProxiesListReplacesTheEarlierOneEntirely(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  trusted_proxies: ['10.0.0.0/8', '0.0.0.0/0']\n");
        $later = $this->write('later.yml', "security:\n  trusted_proxies: ['10.1.2.3']\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['10.1.2.3'], $config->security->trustedProxies);
    }

    #[DataProvider('loaders')]
    public function testAShorterLaterListDropsTheSurplusEntriesOfTheEarlierOne(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  allowed_hosts: [a.example.com, b.example.com, c.example.com]\n");
        $later = $this->write('later.yml', "security:\n  allowed_hosts: [a.example.com]\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['a.example.com'], $config->security->allowedHosts);
    }

    #[DataProvider('loaders')]
    public function testALaterEmptyListEmptiesTheEarlierOne(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  trusted_proxies: ['10.0.0.0/8']\n");
        $later = $this->write('later.yml', "security:\n  trusted_proxies: []\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame([], $config->security->trustedProxies);
    }

    #[DataProvider('loaders')]
    public function testAListNestedInAListIsReplacedAsAWhole(string $loader): void
    {
        $base = $this->write('base.yml', "custom:\n  matrix: [[1, 2, 3], [4, 5]]\n");
        $later = $this->write('later.yml', "custom:\n  matrix: [[9]]\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame([[9]], $config->section('custom')?->getArray('matrix'));
    }

    #[DataProvider('loaders')]
    public function testAMappingStillMergesKeyByKey(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  trusted_proxies: ['10.0.0.0/8']\n  force_https: true\n");
        $later = $this->write('later.yml', "security:\n  allowed_hosts: [example.com]\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertTrue($config->security->forceHttps);
        self::assertSame(['10.0.0.0/8'], $config->security->trustedProxies);
        self::assertSame(['example.com'], $config->security->allowedHosts);
    }

    public function testAPhpFileListReplacesAnEarlierYamlListWhenFilesAreMixed(): void
    {
        $base = $this->write('base.yml', "security:\n  trusted_proxies: ['10.0.0.0/8', '0.0.0.0/0']\n");
        $later = $this->write('later.php', "<?php\nreturn ['security' => ['trusted_proxies' => ['10.1.2.3']]];\n");

        $config = Configuration::fromFiles([$base, $later]);

        self::assertSame(['10.1.2.3'], $config->security->trustedProxies);
    }

    public function testALaterListReplacesAnEarlierArrayWithNonContiguousIntegerKeys(): void
    {
        $base = $this->write(
            'base.php',
            "<?php\nreturn ['security' => ['trusted_proxies' => array_filter(['', '0.0.0.0/0'])]];\n",
        );
        $later = $this->write('later.yml', "security:\n  trusted_proxies: ['10.1.2.3']\n");

        $config = Configuration::fromFiles([$base, $later]);

        self::assertSame(['10.1.2.3'], $config->security->trustedProxies);
    }

    public function testTwoArraysWithNonContiguousIntegerKeysMergeKeyByKey(): void
    {
        $base = $this->write('base.php', "<?php\nreturn ['custom' => ['v' => [0 => 'a', 2 => 'b']]];\n");
        $later = $this->write('later.php', "<?php\nreturn ['custom' => ['v' => [2 => 'c', 4 => 'd']]];\n");

        $config = Configuration::fromFiles([$base, $later], ['custom' => ListMergeSection::class]);

        self::assertSame([0 => 'a', 2 => 'c', 4 => 'd'], $config->section('custom')?->getArray('v'));
    }

    #[DataProvider('loaders')]
    public function testALaterListReplacesAnEarlierMapping(string $loader): void
    {
        $base = $this->write('base.yml', "custom:\n  v: {a: 1, b: 2}\n");
        $later = $this->write('later.yml', "custom:\n  v: [x]\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['x'], $config->section('custom')?->getArray('v'));
    }

    #[DataProvider('loaders')]
    public function testALaterMappingReplacesAnEarlierList(string $loader): void
    {
        $base = $this->write('base.yml', "custom:\n  v: [x, y]\n");
        $later = $this->write('later.yml', "custom:\n  v: {a: 1}\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['a' => 1], $config->section('custom')?->getArray('v'));
    }

    #[DataProvider('loaders')]
    public function testALaterNullReplacesAnEarlierList(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  trusted_proxies: ['0.0.0.0/0']\n");
        $later = $this->write('later.yml', "security:\n  trusted_proxies: ~\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame([], $config->security->trustedProxies);
    }

    #[DataProvider('loaders')]
    public function testALaterEmptyOrMappingLiteralLeavesAnEarlierMappingUnchanged(string $loader): void
    {
        $base = $this->write('base.yml', "custom:\n  v: {a: 1}\n  w: {b: 2}\n");
        $later = $this->write('later.yml', "custom:\n  v: []\n  w: {}\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['a' => 1], $config->section('custom')?->getArray('v'));
        self::assertSame(['b' => 2], $config->section('custom')?->getArray('w'));
    }

    #[DataProvider('loaders')]
    public function testALaterEmptySectionLeavesTheEarlierSectionUnchanged(string $loader): void
    {
        $base = $this->write('base.yml', "security:\n  allowed_hosts: [app.example.com]\n  force_https: true\n");
        $later = $this->write('later.yml', "security:\n#  force_https: false\n");

        $config = $this->load($loader, [$base, $later]);

        self::assertSame(['app.example.com'], $config->security->allowedHosts);
        self::assertTrue($config->security->forceHttps);
    }

    public function testAnEmptyUnknownSectionAloneIsStillRefused(): void
    {
        $only = $this->write('only.yml', "secrity:\n");

        try {
            Configuration::fromYamlFiles([$only]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration key 'secrity' is not a known section: did you mean \"security\"? "
                . 'A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.',
                $e->getMessage(),
            );
        }
    }
}

final class ListMergeSection extends ConfigSection
{
    public static function fromArray(array $values): static
    {
        return new static($values);
    }
}
