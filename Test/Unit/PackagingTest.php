<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use PHPUnit\Framework\TestCase;

class PackagingTest extends TestCase
{
    private const OSL_LINE = ' * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private const SKIP_DIRS = ['.git', 'vendor', '.phpunit.cache'];

    private const REPO = 'https://github.com/dmlab-work/module-admin-sso-google';

    public function testRequireCoversMagento247AndPhp82(): void
    {
        self::assertSame(
            [
                'php' => '~8.2.0||~8.3.0||~8.4.0||~8.5.0',
                'magento/framework' => '^103.0.7',
                'magento/module-config' => '^101.2.7',
                'dmlab/module-sso-core' => '^0.2.0',
                'dmlab/module-admin-sso' => '^0.2.0',
            ],
            $this->composer()['require']
        );
    }

    public function testRequireDevAcceptsPhpunit11And12(): void
    {
        self::assertSame(['phpunit/phpunit' => '^11.5||^12.5'], $this->composer()['require-dev']);
    }

    public function testHomepageAndSupportLinks(): void
    {
        $composer = $this->composer();

        self::assertSame(self::REPO, $composer['homepage']);
        self::assertSame(
            ['issues' => self::REPO . '/issues', 'source' => self::REPO],
            $composer['support']
        );
    }

    public function testKeywords(): void
    {
        $keywords = $this->composer()['keywords'];

        self::assertContains('magento2', $keywords);
        self::assertContains('google workspace', $keywords);
    }

    public function testCiTestsPhp82To85(): void
    {
        self::assertStringContainsString("php: ['8.2', '8.3', '8.4', '8.5']", $this->ci());
    }

    public function testCiDisablesInstallNotifyBeforeComposerInstall(): void
    {
        $ci = $this->ci();
        $notify = strpos($ci, 'composer config notify-on-install false');
        $install = strpos($ci, 'uses: ramsey/composer-install');

        self::assertNotFalse($notify);
        self::assertNotFalse($install);
        self::assertLessThan($install, $notify);
    }

    public function testReadmeStatesRequirementsAndOneLineInstall(): void
    {
        $readme = $this->readme();

        self::assertStringContainsString('- Magento 2.4.7+ · PHP 8.2–8.5', $readme);
        self::assertStringContainsString(
            "## Install\n\n```bash\ncomposer require dmlab/module-admin-sso-google",
            $readme
        );
        self::assertDoesNotMatchRegularExpression('#composer require dmlab/module-admin-sso(\s|$)#m', $readme);
        self::assertStringNotContainsString('composer require dmlab/module-sso-core', $readme);
    }

    public function testReadmeScreenshotExists(): void
    {
        self::assertStringContainsString('](.github/images/config.png)', $this->readme());
        self::assertFileExists($this->root() . '/.github/images/config.png');
    }

    public function testReadmeSectionOrder(): void
    {
        $readme = $this->readme();
        $positions = array_map(
            static fn (string $heading): int|false => strpos($readme, "\n" . $heading . "\n"),
            [
                '## Install', '## Requirements', '## Create the Google OAuth client', '## Configuration',
                '## What it does', '## Architecture', '## License',
            ]
        );

        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function testReadmeDoesNotMentionDirectoryApi(): void
    {
        self::assertStringNotContainsString('Directory API', $this->readme());
    }

    public function testVersionIs021AndLeadsChangelog(): void
    {
        self::assertSame('0.2.1', $this->composer()['version'] ?? null);

        $changelog = (string)file_get_contents($this->root() . '/CHANGELOG.md');
        self::assertSame(1, preg_match('/^## (\S+)/m', $changelog, $match));
        self::assertSame('0.2.1', $match[1]);
    }

    public function testEveryPhpPhraseIsInEnUsCsv(): void
    {
        $phrases = [];
        foreach ($this->sourceFiles(['php']) as $file) {
            if (str_starts_with($this->relative($file), 'Test/')) {
                continue;
            }
            preg_match_all(
                "#__\\(\\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1#",
                (string)file_get_contents($file),
                $matches
            );
            foreach ($matches[2] as $phrase) {
                $phrases[] = stripcslashes($phrase);
            }
        }

        self::assertNotEmpty($phrases);
        $this->assertTranslated($phrases);
    }

    public function testEverySystemXmlTranslatableTextIsInEnUsCsv(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($this->root() . '/etc/adminhtml/system.xml'));
        $xpath = new \DOMXPath($dom);

        $phrases = [];
        foreach ($xpath->query('//*[@translate]') ?: [] as $element) {
            foreach (preg_split('/\s+/', trim($element->getAttribute('translate'))) ?: [] as $child) {
                foreach ($xpath->query($child, $element) ?: [] as $node) {
                    $phrases[] = $node->nodeValue;
                }
            }
        }

        self::assertNotEmpty($phrases);
        $this->assertTranslated($phrases);
    }

    public function testEveryPhpAndXmlFileCarriesOslNotice(): void
    {
        $files = $this->sourceFiles(['php', 'xml']);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $lines = explode("\n", (string)file_get_contents($file));
            $copyright = array_search(' * Copyright © DMLab. All rights reserved.', $lines, true);

            self::assertNotFalse($copyright, $this->relative($file) . ' lacks the copyright line');
            self::assertSame(
                self::OSL_LINE,
                $lines[$copyright + 1] ?? null,
                $this->relative($file) . ' lacks the OSL notice'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
        self::assertIsArray($composer);

        return $composer;
    }

    private function ci(): string
    {
        $ci = file_get_contents($this->root() . '/.github/workflows/ci.yml');
        self::assertIsString($ci);

        return $ci;
    }

    private function readme(): string
    {
        $readme = file_get_contents($this->root() . '/README.md');
        self::assertIsString($readme);

        return $readme;
    }

    /**
     * @param string[] $phrases
     */
    private function assertTranslated(array $phrases): void
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'rb');
        self::assertNotFalse($handle);
        $keys = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $keys[] = $row[0];
        }
        fclose($handle);

        foreach ($phrases as $phrase) {
            self::assertContains($phrase, $keys, sprintf('Missing from i18n/en_US.csv: "%s"', $phrase));
        }
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    private function sourceFiles(array $extensions): array
    {
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !$file->isDir()
                || !in_array($file->getFilename(), self::SKIP_DIRS, true)
        );

        $files = [];
        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen($this->root()) + 1);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
