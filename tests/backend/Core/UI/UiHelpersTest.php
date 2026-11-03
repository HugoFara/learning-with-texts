<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\UI;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PageLayoutHelper (migrated from ui_helpers.php)
 */
final class UiHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__
            . '/../../../../src/Shared/Infrastructure/Globals.php';
        require_once __DIR__
            . '/../../../../src/Shared/Infrastructure/Utilities'
            . '/StringUtils.php';
        require_once __DIR__
            . '/../../../../src/Shared/Infrastructure/ApplicationInfo.php';
        require_once __DIR__
            . '/../../../../src/Shared/UI/Helpers/PageLayoutHelper.php';
    }

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
    }

    /**
     * Test PageLayoutHelper::buildNavbarPlaceholder() — the navbar is now
     * client-rendered (navbar_renderer.ts) from this empty mount point.
     */
    public function testNavbarPlaceholder(): void
    {
        $output = PageLayoutHelper::buildNavbarPlaceholder('texts');

        $this->assertStringContainsString('id="navbar-root"', $output);
        $this->assertStringContainsString('data-navbar-root', $output);
        $this->assertStringContainsString('data-current-page="texts"', $output);
        // No server-rendered <nav> markup anymore.
        $this->assertStringNotContainsString('<nav', $output);
    }

    /**
     * Test PageLayoutHelper::getNavbarData() — the payload behind GET /api/v1/navbar.
     */
    public function testGetNavbarData(): void
    {
        $data = PageLayoutHelper::getNavbarData();

        $this->assertArrayHasKey('basePath', $data);
        $this->assertArrayHasKey('languages', $data);
        $this->assertIsArray($data['languages']);
        $this->assertArrayHasKey('currentLanguageId', $data);
        $this->assertArrayHasKey('isMultiUser', $data);
        $this->assertArrayHasKey('showAdminItems', $data);
        $this->assertArrayHasKey('theme', $data);
        $this->assertArrayHasKey('mode', $data['theme']);
        $this->assertArrayHasKey('auto', $data['theme']);
    }

    /**
     * Test PageLayoutHelper::renderPageStartKernelNobody() function
     */
    public function testPagestartKernelNobody(): void
    {
        // Capture output
        ob_start();
        PageLayoutHelper::renderPageStartKernelNobody('Test Page');
        $output = ob_get_clean();

        // Should output HTML document structure
        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $output);
        $this->assertStringContainsString('<head>', $output);
        $this->assertStringContainsString('<title>LWT :: Test Page</title>', $output);

        // Should have meta tags
        $this->assertStringContainsString('charset=utf-8', $output);
        $this->assertStringContainsString('viewport', $output);
    }

    /**
     * The interface locale sets both lang and dir on <html> (#306).
     */
    public function testHtmlTagFollowsTheInterfaceLocale(): void
    {
        $translator = \Lwt\Shared\Infrastructure\Container\Container::getInstance()
            ->getTyped(\Lwt\Shared\I18n\Translator::class);
        $previous = $translator->getLocale();

        try {
            $translator->setLocale('fr');
            $this->assertSame('<html lang="fr" dir="ltr">', PageLayoutHelper::buildHtmlOpenTag());

            $translator->setLocale('ar');
            $this->assertSame('<html lang="ar" dir="rtl">', PageLayoutHelper::buildHtmlOpenTag());
        } finally {
            $translator->setLocale($previous);
        }
    }

    /**
     * Test PageLayoutHelper::renderPageEnd() function
     */
    public function testPageend(): void
    {
        // Capture output
        ob_start();
        PageLayoutHelper::renderPageEnd();
        $output = ob_get_clean();

        // Should close body and html tags
        $this->assertStringContainsString('</body>', $output);
        $this->assertStringContainsString('</html>', $output);
    }
}
