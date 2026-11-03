<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\Config\Backend\AdminSubject;
use DmLab\AdminSsoGoogle\Model\Config\Backend\AllowedDomain;
use DmLab\AdminSsoGoogle\Model\Config\Backend\ServiceAccountKey;
use DmLab\AdminSsoGoogle\Plugin\AddDirectoryGroups;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Config\Model\Config\Source\Yesno;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts adminhtml/system.xml exposes the Google fields under the admin-sso
 * section, at the exact paths {@see Config} reads, plus their config.xml/di.xml wiring.
 */
class SystemConfigTest extends TestCase
{
    /** @var \DOMXPath */
    private \DOMXPath $xpath;

    protected function setUp(): void
    {
        $systemXml = dirname(__DIR__, 2) . '/etc/adminhtml/system.xml';
        self::assertFileExists($systemXml);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($systemXml));
        $this->xpath = new \DOMXPath($dom);
    }

    public function testGoogleGroupLivesUnderAdminSsoSection(): void
    {
        $groups = $this->xpath->query(
            "/config/system/section[@id='dmlab_admin_sso']/group[@id='google']"
        );

        self::assertNotNull($groups);
        self::assertSame(1, $groups->length);
    }

    public function testAllowedDomainFieldMapsToConfigPath(): void
    {
        $fields = $this->xpath->query(
            "/config/system/section[@id='dmlab_admin_sso']"
            . "/group[@id='google']/field[@id='allowed_domain']"
        );

        self::assertNotNull($fields);
        self::assertSame(1, $fields->length, 'Missing google field allowed_domain.');

        /** @var \DOMElement $field */
        $field = $fields->item(0);
        self::assertSame(
            0,
            $this->xpath->query('config_path', $field)->length,
            'Field allowed_domain has a config_path override; its path is no longer id-derived.'
        );

        $group = $field->parentNode;
        $section = $group->parentNode;
        $derivedPath = $section->getAttribute('id') . '/'
            . $group->getAttribute('id') . '/'
            . $field->getAttribute('id');

        self::assertSame(Config::XML_PATH_ALLOWED_DOMAIN, $derivedPath);
    }

    /**
     * Cross-group depends must be fully-qualified section/group/field paths;
     * Magento only wildcard-pads single-segment relative paths, so a two-segment
     * path resolves to a non-existent field and the show/hide toggle never binds.
     */
    public function testGoogleGroupDependsAreFullyQualified(): void
    {
        $ids = $this->xpath->query(
            "/config/system/section[@id='dmlab_admin_sso']"
            . "/group[@id='google']/depends/field/@id"
        );

        $paths = [];
        foreach ($ids as $attr) {
            $paths[] = $attr->value;
        }

        self::assertContains('dmlab_admin_sso/general/enabled', $paths);
        self::assertContains('dmlab_admin_sso/general/active_provider', $paths);
        foreach ($paths as $path) {
            self::assertSame(3, count(explode('/', $path)), "Depend '$path' is not fully qualified.");
        }
    }

    public function testAllowedDomainDeclaresBackendModelAndIsRequired(): void
    {
        $field = "/config/system/section[@id='dmlab_admin_sso']"
            . "/group[@id='google']/field[@id='allowed_domain']";

        self::assertSame(
            AllowedDomain::class,
            trim((string)$this->xpath->evaluate("string($field/backend_model)"))
        );
        self::assertStringContainsString(
            'required-entry',
            (string)$this->xpath->evaluate("string($field/validate)")
        );

        $comment = strtolower((string)$this->xpath->evaluate("string($field/comment)"));
        self::assertStringNotContainsString('allow any', $comment);
        self::assertStringContainsString('required', $comment);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function directoryFields(): array
    {
        return [
            'enabled' => ['directory_groups_enabled', Config::XML_PATH_DIRECTORY_GROUPS_ENABLED],
            'key' => ['service_account_key', Config::XML_PATH_SERVICE_ACCOUNT_KEY],
            'subject' => ['directory_admin_subject', Config::XML_PATH_DIRECTORY_ADMIN_SUBJECT],
        ];
    }

    #[DataProvider('directoryFields')]
    public function testDirectoryFieldMapsToConfigPathInDefaultScopeOnly(string $id, string $path): void
    {
        $field = $this->googleField($id);

        self::assertSame(0, $this->xpath->query('config_path', $field)->length);
        self::assertSame('dmlab_admin_sso/google/' . $id, $path);
        self::assertSame('1', $field->getAttribute('showInDefault'));
        self::assertSame('0', $field->getAttribute('showInWebsite'));
        self::assertSame('0', $field->getAttribute('showInStore'));
    }

    public function testDirectoryGroupsEnabledIsYesNo(): void
    {
        $field = $this->googleField('directory_groups_enabled');

        self::assertSame('select', $field->getAttribute('type'));
        self::assertSame(Yesno::class, trim((string)$this->xpath->evaluate('string(source_model)', $field)));
        self::assertSame(0, $this->xpath->query('depends', $field)->length);
    }

    public function testServiceAccountKeyIsObscureEncryptedAndRequired(): void
    {
        $field = $this->googleField('service_account_key');

        self::assertSame('obscure', $field->getAttribute('type'));
        self::assertSame(
            ServiceAccountKey::class,
            trim((string)$this->xpath->evaluate('string(backend_model)', $field))
        );
        self::assertStringContainsString(
            'required-entry',
            (string)$this->xpath->evaluate('string(validate)', $field)
        );
    }

    public function testAdminSubjectHasBackendAndEmailValidation(): void
    {
        $field = $this->googleField('directory_admin_subject');

        self::assertSame('text', $field->getAttribute('type'));
        self::assertSame(
            AdminSubject::class,
            trim((string)$this->xpath->evaluate('string(backend_model)', $field))
        );
        $validate = (string)$this->xpath->evaluate('string(validate)', $field);
        self::assertStringContainsString('required-entry', $validate);
        self::assertStringContainsString('validate-email', $validate);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dependentFields(): array
    {
        return ['key' => ['service_account_key'], 'subject' => ['directory_admin_subject']];
    }

    #[DataProvider('dependentFields')]
    public function testFieldDependsOnDirectoryGroupsEnabled(string $id): void
    {
        $depends = $this->xpath->query('depends/field', $this->googleField($id));

        self::assertSame(1, $depends->length);
        /** @var \DOMElement $depend */
        $depend = $depends->item(0);
        self::assertSame('directory_groups_enabled', $depend->getAttribute('id'));
        self::assertSame('1', trim($depend->nodeValue));
    }

    public function testConfigXmlDefaultsDirectoryGroupsOff(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/etc/config.xml'));

        self::assertSame(
            '0',
            trim((string)(new \DOMXPath($dom))->evaluate(
                'string(/config/default/dmlab_admin_sso/google/directory_groups_enabled)'
            ))
        );
    }

    public function testServiceAccountKeyIsSensitiveInTypePool(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/etc/di.xml'));

        $items = (new \DOMXPath($dom))->query(
            "/config/type[@name='Magento\\Config\\Model\\Config\\TypePool']"
            . "/arguments/argument[@name='sensitive']/item[@name='" . Config::XML_PATH_SERVICE_ACCOUNT_KEY . "']"
        );

        self::assertSame(1, $items->length);
        self::assertSame('1', trim($items->item(0)->nodeValue));
    }

    public function testDirectoryGroupsPluginIsRegisteredOnIdentityFactory(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/etc/di.xml'));

        $type = (new \DOMXPath($dom))->evaluate(
            "string(/config/type[@name='" . IdentityFactory::class . "']"
            . "/plugin[@name='dmlab_admin_sso_google_directory_groups']/@type)"
        );

        self::assertSame(AddDirectoryGroups::class, $type);
    }

    private function googleField(string $id): \DOMElement
    {
        $fields = $this->xpath->query(
            "/config/system/section[@id='dmlab_admin_sso']/group[@id='google']/field[@id='$id']"
        );
        self::assertSame(1, $fields->length, "Missing google field $id.");

        /** @var \DOMElement $field */
        $field = $fields->item(0);

        return $field;
    }
}
