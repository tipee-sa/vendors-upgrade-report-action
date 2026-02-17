<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VendorsUpgradeReportTest extends TestCase
{
    // === getVendorName ===

    #[DataProvider('vendorNameProvider')]
    public function testGetVendorName(string $package, string $expected): void
    {
        self::assertSame($expected, getVendorName($package));
    }

    public static function vendorNameProvider(): iterable
    {
        yield 'composer package' => ['vendor/package', 'vendor'];
        yield 'npm scoped package' => ['@scope/package', '@scope'];
        yield 'npm unscoped package' => ['package', 'package'];
    }

    // === parseUpgrades ===

    public function testParseUpgrades(): void
    {
        $input = <<<'TXT'
          - symfony/console (v6.4.1 => v6.4.3)
          - doctrine/orm (3.0.0 => 3.1.0)
          - some/package (1.2.3-beta.1 => 1.2.3)
        TXT;

        $result = parseUpgrades($input);

        self::assertCount(3, $result);

        self::assertSame('symfony/console', $result[0]['package']);
        self::assertSame('6.4.1', $result[0]['from']);
        self::assertSame('6.4.3', $result[0]['to']);

        self::assertSame('doctrine/orm', $result[1]['package']);
        self::assertSame('3.0.0', $result[1]['from']);
        self::assertSame('3.1.0', $result[1]['to']);

        self::assertSame('some/package', $result[2]['package']);
        self::assertSame('1.2.3-beta.1', $result[2]['from']);
        self::assertSame('1.2.3', $result[2]['to']);
    }

    public function testParseUpgradesWithEmptyInput(): void
    {
        self::assertSame([], parseUpgrades(''));
        self::assertSame([], parseUpgrades('nothing useful here'));
    }

    // === resolveRelativeUrls ===

    #[DataProvider('relativeUrlProvider')]
    public function testResolveRelativeUrls(string $markdown, string $expected): void
    {
        self::assertSame($expected, resolveRelativeUrls($markdown, 'owner/repo', 'v1.0.0'));
    }

    public static function relativeUrlProvider(): iterable
    {
        yield 'relative link' => [
            '[CHANGELOG](CHANGELOG.md)',
            '[CHANGELOG](https://github.com/owner/repo/blob/v1.0.0/CHANGELOG.md)',
        ];

        yield 'relative link with leading slash' => [
            '[docs](/docs/README.md)',
            '[docs](https://github.com/owner/repo/blob/v1.0.0/docs/README.md)',
        ];

        yield 'absolute link unchanged' => [
            '[link](https://example.com/page)',
            '[link](https://example.com/page)',
        ];

        yield 'hash link unchanged' => [
            '[section](#heading)',
            '[section](#heading)',
        ];

        yield 'mailto link unchanged' => [
            '[email](mailto:test@example.com)',
            '[email](mailto:test@example.com)',
        ];

        yield 'mixed links' => [
            '[relative](file.md) and [absolute](https://example.com)',
            '[relative](https://github.com/owner/repo/blob/v1.0.0/file.md) and [absolute](https://example.com)',
        ];
    }

    // === parsePackagistResponse ===

    public function testParsePackagistResponse(): void
    {
        $json = json_encode([
            'packages' => [
                'vendor/package' => [
                    [
                        'version' => 'v2.1.0',
                        'source' => ['url' => 'https://github.com/vendor/package.git'],
                    ],
                    [
                        'version' => 'v2.0.0',
                        'source' => ['url' => 'https://github.com/vendor/package.git'],
                    ],
                ],
            ],
        ]);

        $result = parsePackagistResponse($json, 'vendor/package');

        self::assertSame('vendor/package', $result['repo']);
        self::assertSame('v', $result['tagPrefix']);
    }

    public function testParsePackagistResponseWithoutVPrefix(): void
    {
        $json = json_encode([
            'packages' => [
                'vendor/package' => [
                    [
                        'version' => '2.1.0',
                        'source' => ['url' => 'https://github.com/vendor/package.git'],
                    ],
                ],
            ],
        ]);

        $result = parsePackagistResponse($json, 'vendor/package');

        self::assertSame('', $result['tagPrefix']);
    }

    public function testParsePackagistResponseFallsBackToSupportUrls(): void
    {
        $json = json_encode([
            'packages' => [
                'vendor/package' => [
                    [
                        'version' => '1.0.0',
                        'source' => ['url' => 'https://gitlab.com/vendor/package.git'],
                        'support' => [
                            'issues' => 'https://github.com/actual-owner/actual-repo/issues',
                        ],
                    ],
                ],
            ],
        ]);

        $result = parsePackagistResponse($json, 'vendor/package');

        self::assertSame('actual-owner/actual-repo', $result['repo']);
    }

    public function testParsePackagistResponseReturnsNullForInvalidJson(): void
    {
        self::assertNull(parsePackagistResponse('not json', 'vendor/package'));
    }

    public function testParsePackagistResponseReturnsNullForMissingPackage(): void
    {
        $json = json_encode(['packages' => ['other/package' => []]]);

        self::assertNull(parsePackagistResponse($json, 'vendor/package'));
    }

    // === parseNpmResponse ===

    public function testParseNpmResponse(): void
    {
        $json = json_encode([
            'repository' => ['url' => 'git+https://github.com/owner/repo.git'],
        ]);

        $result = parseNpmResponse($json);

        self::assertSame('owner/repo', $result['repo']);
        self::assertSame('v', $result['tagPrefix']);
    }

    public function testParseNpmResponseWithoutGithubRepo(): void
    {
        $json = json_encode([
            'repository' => ['url' => 'git+https://gitlab.com/owner/repo.git'],
        ]);

        $result = parseNpmResponse($json);

        self::assertNull($result['repo']);
    }

    public function testParseNpmResponseReturnsNullForInvalidJson(): void
    {
        self::assertNull(parseNpmResponse('not json'));
    }

    // === filterReleasesInRange ===

    public function testFilterReleasesInRange(): void
    {
        $releases = [
            ['tag_name' => 'v1.0.0', 'body' => 'old'],
            ['tag_name' => 'v1.1.0', 'body' => 'first'],
            ['tag_name' => 'v1.2.0', 'body' => 'second'],
            ['tag_name' => 'v1.3.0', 'body' => 'third'],
            ['tag_name' => 'v2.0.0', 'body' => 'major'],
        ];

        $result = filterReleasesInRange($releases, '1.0.0', '1.3.0');

        self::assertCount(3, $result);
        self::assertSame('v1.1.0', $result[0]['tag_name']);
        self::assertSame('v1.2.0', $result[1]['tag_name']);
        self::assertSame('v1.3.0', $result[2]['tag_name']);
    }

    public function testFilterReleasesInRangeExcludesFromVersion(): void
    {
        $releases = [
            ['tag_name' => 'v1.0.0', 'body' => ''],
            ['tag_name' => 'v1.1.0', 'body' => ''],
        ];

        $result = filterReleasesInRange($releases, '1.0.0', '1.1.0');

        self::assertCount(1, $result);
        self::assertSame('v1.1.0', $result[0]['tag_name']);
    }

    public function testFilterReleasesInRangeIncludesToVersion(): void
    {
        $releases = [
            ['tag_name' => 'v1.0.0', 'body' => ''],
            ['tag_name' => 'v1.1.0', 'body' => ''],
        ];

        $result = filterReleasesInRange($releases, '0.9.0', '1.0.0');

        self::assertCount(1, $result);
        self::assertSame('v1.0.0', $result[0]['tag_name']);
    }

    public function testFilterReleasesInRangeSortsByVersionAscending(): void
    {
        $releases = [
            ['tag_name' => 'v1.3.0', 'body' => ''],
            ['tag_name' => 'v1.1.0', 'body' => ''],
            ['tag_name' => 'v1.2.0', 'body' => ''],
        ];

        $result = filterReleasesInRange($releases, '1.0.0', '1.3.0');

        self::assertSame('v1.1.0', $result[0]['tag_name']);
        self::assertSame('v1.2.0', $result[1]['tag_name']);
        self::assertSame('v1.3.0', $result[2]['tag_name']);
    }

    public function testFilterReleasesInRangeReturnsEmptyForNoMatches(): void
    {
        $releases = [
            ['tag_name' => 'v1.0.0', 'body' => ''],
            ['tag_name' => 'v2.0.0', 'body' => ''],
        ];

        self::assertSame([], filterReleasesInRange($releases, '1.0.0', '1.5.0'));
    }

    public function testFilterReleasesInRangeHandlesEmptyInput(): void
    {
        self::assertSame([], filterReleasesInRange([], '1.0.0', '2.0.0'));
    }

    // === githubApiHeaders ===

    public function testGithubApiHeadersWithToken(): void
    {
        $headers = githubApiHeaders('my-token');

        self::assertSame([
            'Accept: application/vnd.github.v3+json',
            'Authorization: Bearer my-token',
        ], $headers);
    }

    public function testGithubApiHeadersWithoutToken(): void
    {
        $headers = githubApiHeaders(null);

        self::assertSame([
            'Accept: application/vnd.github.v3+json',
        ], $headers);
    }

    // === parseYarnLock ===

    public function testParseYarnLock(): void
    {
        $content = <<<'YARN'
# yarn lockfile v1

"lodash@^4.17.21":
  version "4.17.21"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz"

"react@^18.2.0":
  version "18.2.0"
  resolved "https://registry.yarnpkg.com/react/-/react-18.2.0.tgz"
YARN;

        $result = parseYarnLock($content, 'test.lock');

        self::assertSame([
            'lodash' => '4.17.21',
            'react' => '18.2.0',
        ], $result);
    }

    public function testParseYarnLockWithScopedPackages(): void
    {
        $content = <<<'YARN'
# yarn lockfile v1

"@babel/core@^7.0.0":
  version "7.24.0"
  resolved "https://registry.yarnpkg.com/@babel/core/-/core-7.24.0.tgz"

"@types/react@^18.0.0":
  version "18.2.48"
  resolved "https://registry.yarnpkg.com/@types/react/-/react-18.2.48.tgz"
YARN;

        $result = parseYarnLock($content, 'test.lock');

        self::assertSame([
            '@babel/core' => '7.24.0',
            '@types/react' => '18.2.48',
        ], $result);
    }

    public function testParseYarnLockKeepsHighestVersion(): void
    {
        $content = <<<'YARN'
# yarn lockfile v1

"lodash@^4.0.0":
  version "4.17.20"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.20.tgz"

"lodash@^4.17.21":
  version "4.17.21"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz"
YARN;

        $result = parseYarnLock($content, 'test.lock');

        self::assertSame(['lodash' => '4.17.21'], $result);
    }
}
