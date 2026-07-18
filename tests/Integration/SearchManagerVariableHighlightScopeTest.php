<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;

/**
 * Regression coverage for field-scoped Twig/PHP highlighting.
 *
 * @since 5.54.0
 */
final class SearchManagerVariableHighlightScopeTest extends TestCase
{
    public function testTitleFieldIgnoresContentScopedTerms(): void
    {
        $highlighted = (new SearchManagerVariable())->highlight(
            'alpha beta gamma',
            'title:alpha content:beta gamma',
            ['field' => 'title', 'class' => ''],
        );

        self::assertSame('<mark>alpha</mark> beta <mark>gamma</mark>', $highlighted);
    }

    public function testContentFieldIgnoresTitleScopedTerms(): void
    {
        $highlighted = (new SearchManagerVariable())->highlight(
            'alpha beta gamma',
            'title:alpha content:beta gamma',
            ['field' => 'content', 'class' => ''],
        );

        self::assertSame('alpha <mark>beta</mark> <mark>gamma</mark>', $highlighted);
    }

    public function testOtherFieldOnlyQueryHighlightsNothing(): void
    {
        $highlighted = (new SearchManagerVariable())->highlight(
            'beta remains plain',
            'content:beta',
            ['field' => 'title', 'class' => ''],
        );

        self::assertSame('beta remains plain', $highlighted);
    }

    public function testPrefixPaintingRunsAfterFieldScopeFiltering(): void
    {
        $variable = new SearchManagerVariable();

        self::assertSame(
            '<mark>Test</mark>ing Tools',
            $variable->highlight('Testing Tools', 'title:test content:tool', ['field' => 'title', 'class' => '']),
        );
        self::assertSame(
            'Testing <mark>Tool</mark>s',
            $variable->highlight('Testing Tools', 'title:test content:tool', ['field' => 'content', 'class' => '']),
        );
    }

    public function testUnscopedAndDefaultBehaviorRemainUnchanged(): void
    {
        $variable = new SearchManagerVariable();

        self::assertSame(
            '<mark>alpha</mark> <mark>beta</mark>',
            $variable->highlight('alpha beta', 'alpha beta', ['field' => 'title', 'class' => '']),
        );
        self::assertSame(
            '<mark>alpha</mark> <mark>beta</mark>',
            $variable->highlight('alpha beta', 'title:alpha content:beta', ['class' => '']),
        );
    }
}
