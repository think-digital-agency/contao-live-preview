<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Tests\Service;

use Contao\Controller;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolver;

/**
 * @covers \ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolver
 */
final class PreviewUrlResolverTest extends TestCase
{
    public function testResolveFromPageReturnsNullDefaultsForArticleContentAndNews(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->with('SELECT id, alias, language, dns FROM tl_page WHERE id = ?', [42])
            ->willReturn(['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => '']);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_page', 42);

        self::assertSame([
            'pageId'             => 42,
            'alias'              => 'home',
            'language'           => 'de',
            'dns'                => '',
            'articleId'          => null,
            'articleAlias'       => '',
            'articleCssId'       => '',
            'contentElementId'   => null,
            'contentElementType' => null,
            'newsId'             => null,
            'newsAlias'          => '',
            'newsTitle'          => '',
        ], $result);
    }

    public function testResolveFromPageReturnsNullForMissingPage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_page', 999));
    }

    public function testResolveFromArticleMergesArticleFieldsAndUnserializesCssId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
                [151],
                [
                    'pid'   => 42,
                    'alias' => 'intro',
                    'cssID' => serialize(['intro-id', 'intro-class']),
                    'title' => 'Intro',
                ],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_article', 151);

        self::assertNotNull($result);
        self::assertSame(151, $result['articleId']);
        self::assertSame('intro', $result['articleAlias']);
        self::assertSame('Intro', $result['articleTitle']);
        self::assertSame('intro-id', $result['articleCssId']);
        self::assertSame(42, $result['pageId']);
    }

    public function testResolveFromArticleCssIdIsEmptyWhenNotSet(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
                [151],
                ['pid' => 42, 'alias' => 'intro', 'cssID' => serialize(['', '']), 'title' => 'Intro'],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertSame('', $resolver->resolve('tl_article', 151)['articleCssId']);
    }

    public function testResolveFromArticleReturnsNullWhenParentPageIsGone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
                [151],
                ['pid' => 42, 'alias' => 'intro', 'cssID' => '', 'title' => 'Intro'],
            ],
            ['SELECT id, alias, language, dns FROM tl_page WHERE id = ?', [42], false],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_article', 151));
    }

    public function testResolveFromContentOfALeafArticleElementMergesContentFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
                [77],
                ['pid' => 151, 'ptable' => 'tl_article', 'type' => 'text'],
            ],
            [
                'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
                [151],
                ['pid' => 42, 'alias' => 'intro', 'cssID' => '', 'title' => 'Intro'],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_content', 77);

        self::assertSame(77, $result['contentElementId']);
        self::assertSame('text', $result['contentElementType']);
        self::assertSame(151, $result['articleId']);
    }

    /**
     * The DFS walk up through nested element groups (ptable='tl_content') until
     * the owning article is reached — the exact mechanism that, combined with a
     * missing ptable filter one layer up in the (now-removed) Twig marker
     * listener, caused a production bug (ADR-022 point 9). This resolver's own
     * walk was never the buggy part, but it was never under test either.
     */
    public function testResolveFromContentWalksUpNestedElementGroupsToTheOwningArticle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            // Leaf CE (1596) is a child of a group CE (1595), which is a direct
            // article child.
            [
                'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
                [1596],
                ['pid' => 1595, 'ptable' => 'tl_content', 'type' => 'text'],
            ],
            [
                'SELECT pid, ptable FROM tl_content WHERE id = ?',
                [1595],
                ['pid' => 151, 'ptable' => 'tl_article'],
            ],
            [
                'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
                [151],
                ['pid' => 42, 'alias' => 'intro', 'cssID' => '', 'title' => 'Intro'],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_content', 1596);

        // contentElementId is the leaf (1596), not the group it walked through.
        self::assertSame(1596, $result['contentElementId']);
        self::assertSame(151, $result['articleId']);
    }

    public function testResolveFromContentReturnsNullWhenTheNestedChainIsBroken(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
                [1596],
                ['pid' => 1595, 'ptable' => 'tl_content', 'type' => 'text'],
            ],
            ['SELECT pid, ptable FROM tl_content WHERE id = ?', [1595], false],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_content', 1596));
    }

    public function testResolveFromContentOfANewsElementResolvesThroughTheNewsArchive(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
                [574],
                ['pid' => 1, 'ptable' => 'tl_news', 'type' => 'text'],
            ],
            [
                'SELECT pid, alias, headline FROM tl_news WHERE id = ?',
                [1],
                ['pid' => 5, 'alias' => 'my-news', 'headline' => 'Headline'],
            ],
            [
                'SELECT jumpTo FROM tl_news_archive WHERE id = ?',
                [5],
                ['jumpTo' => 42],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'news-reader', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_content', 574);

        self::assertSame(574, $result['contentElementId']);
        self::assertSame(1, $result['newsId']);
        self::assertSame('my-news', $result['newsAlias']);
        self::assertSame(42, $result['pageId']);
    }

    public function testResolveFromContentWalksUpNestedElementGroupsToTheOwningNewsItem(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            // Leaf CE (890) is a child of a group CE (889) nested inside a
            // news item's content (ptable='tl_news') — e.g. an Accordion
            // placed in a news item's "text" field via the element-group
            // editor, not a direct top-level news CE.
            [
                'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
                [890],
                ['pid' => 889, 'ptable' => 'tl_content', 'type' => 'text'],
            ],
            [
                'SELECT pid, ptable FROM tl_content WHERE id = ?',
                [889],
                ['pid' => 1, 'ptable' => 'tl_news'],
            ],
            [
                'SELECT pid, alias, headline FROM tl_news WHERE id = ?',
                [1],
                ['pid' => 5, 'alias' => 'my-news', 'headline' => 'Headline'],
            ],
            [
                'SELECT jumpTo FROM tl_news_archive WHERE id = ?',
                [5],
                ['jumpTo' => 42],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'news-reader', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        $result = $resolver->resolve('tl_content', 890);

        // contentElementId is the leaf (890), not the group it walked through.
        self::assertSame(890, $result['contentElementId']);
        self::assertSame(1, $result['newsId']);
        self::assertSame(42, $result['pageId']);
    }

    public function testResolveDispatchesTopLevelNewsHighlightDirectlyToResolveFromNews(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                'SELECT pid, alias, headline FROM tl_news WHERE id = ?',
                [1],
                ['pid' => 5, 'alias' => 'my-news', 'headline' => 'Headline'],
            ],
            [
                'SELECT jumpTo FROM tl_news_archive WHERE id = ?',
                [5],
                ['jumpTo' => 42],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'news-reader', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        // Highlighting the tl_news record itself (not a CE inside it) — the
        // 'tl_news' match arm in resolve(), never exercised above since every
        // other news test reaches resolveFromNews() transitively through
        // resolveFromContent().
        $result = $resolver->resolve('tl_news', 1);

        self::assertSame(1, $result['newsId']);
        self::assertSame('my-news', $result['newsAlias']);
        self::assertSame(42, $result['pageId']);
        self::assertNull($result['contentElementId']);
    }

    public function testResolveFromContentReturnsNullForAnUnrecognisedPtable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'pid' => 1, 'ptable' => 'tl_module', 'type' => 'text',
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_content', 99));
    }

    public function testResolveFromNewsArchiveReturnsNullWhenJumpToIsNotSet(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['jumpTo' => 0]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_news_archive', 5));
    }

    public function testResolveRootPageReturnsNullWhenNoPublishedRootExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolveRootPage());
    }

    public function testResolveRootPageReturnsNullWhenRootHasNoPublishedRegularChild(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                "SELECT id FROM tl_page WHERE type = 'root' AND published = '1' ORDER BY sorting ASC LIMIT 1",
                [],
                ['id' => 1],
            ],
            [
                "SELECT id FROM tl_page WHERE pid = ? AND type = 'regular' AND published = '1' ORDER BY sorting ASC LIMIT 1",
                [1],
                false,
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolveRootPage());
    }

    public function testResolveRootPageResolvesTheFirstPublishedRegularChild(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnMap([
            [
                "SELECT id FROM tl_page WHERE type = 'root' AND published = '1' ORDER BY sorting ASC LIMIT 1",
                [],
                ['id' => 1],
            ],
            [
                "SELECT id FROM tl_page WHERE pid = ? AND type = 'regular' AND published = '1' ORDER BY sorting ASC LIMIT 1",
                [1],
                ['id' => 2],
            ],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [2],
                ['id' => 2, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertSame(2, $resolver->resolveRootPage()['pageId']);
    }

    public function testResolveUnknownTableReturnsNullWhenItIsNotInTheSchema(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['tl_page', 'tl_article']);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_unknown_bundle_table', 1));
    }

    public function testResolveUnknownTableReturnsNullForAnObviouslyInvalidTableName(): void
    {
        // Never even reaches the schema manager — guards against interpolating
        // something that isn't a plausible Contao table name into SQL.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('createSchemaManager');

        $resolver = new PreviewUrlResolver($connection, $this->createMock(ContaoFramework::class));

        self::assertNull($resolver->resolve('tl_content; DROP TABLE tl_page', 1));
    }

    public function testResolveChildTableWalksAStaticPtableChainToTheOwningPage(): void
    {
        $GLOBALS['TL_DCA']['tl_acme_widget']['config']['ptable'] = 'tl_page';

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['tl_page', 'tl_acme_widget']);
        $schemaManager->method('listTableColumns')->willReturn(['id' => null, 'pid' => null]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAssociative')->willReturnMap([
            ['SELECT pid FROM tl_acme_widget WHERE id = ?', [9], ['pid' => 42]],
            [
                'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
                [42],
                ['id' => 42, 'alias' => 'home', 'language' => 'de', 'dns' => ''],
            ],
        ]);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::once())->method('initialize');
        $framework->method('getAdapter')->willReturn($this->controllerAdapterStub());

        $resolver = new PreviewUrlResolver($connection, $framework);

        self::assertSame(42, $resolver->resolve('tl_acme_widget', 9)['pageId']);

        unset($GLOBALS['TL_DCA']['tl_acme_widget']);
    }

    public function testResolveChildTableReturnsNullWhenTheTableDeclaresNoParent(): void
    {
        $GLOBALS['TL_DCA']['tl_acme_root']['config'] = [];

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['tl_acme_root']);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->controllerAdapterStub());

        $resolver = new PreviewUrlResolver($connection, $framework);

        self::assertNull($resolver->resolve('tl_acme_root', 1));

        unset($GLOBALS['TL_DCA']['tl_acme_root']);
    }

    public function testResolveChildTableReturnsNullWhenThePidColumnIsMissing(): void
    {
        $GLOBALS['TL_DCA']['tl_acme_widget']['config']['ptable'] = 'tl_page';

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['tl_acme_widget']);
        // No 'pid' column — e.g. a root table with no parent column at all.
        $schemaManager->method('listTableColumns')->willReturn(['id' => null]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects(self::never())->method('fetchAssociative');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->controllerAdapterStub());

        $resolver = new PreviewUrlResolver($connection, $framework);

        self::assertNull($resolver->resolve('tl_acme_widget', 9));

        unset($GLOBALS['TL_DCA']['tl_acme_widget']);
    }

    public function testResolveChildTableStopsAtTheDepthCapInsteadOfLoopingForever(): void
    {
        // A pathological DCA chain where tl_a points back to tl_a's own table
        // name as its ptable — resolveFromChildTable must still terminate.
        $GLOBALS['TL_DCA']['tl_acme_loop']['config']['ptable'] = 'tl_acme_loop';

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['tl_acme_loop']);
        $schemaManager->method('listTableColumns')->willReturn(['id' => null, 'pid' => null]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAssociative')->willReturn(['pid' => 1]);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->controllerAdapterStub());

        $resolver = new PreviewUrlResolver($connection, $framework);

        self::assertNull($resolver->resolve('tl_acme_loop', 1));

        unset($GLOBALS['TL_DCA']['tl_acme_loop']);
    }

    /**
     * A stub standing in for ContaoFramework::getAdapter(Controller::class) —
     * loadDataContainer() is a legacy static call Adapter forwards via __call,
     * which createMock() cannot stub directly (it only sees __call itself, not
     * the methods forwarded through it). The resolver only ever calls
     * loadDataContainer() for its side effect of populating $GLOBALS['TL_DCA'],
     * which these tests populate directly instead, so a no-op is correct here.
     */
    private function controllerAdapterStub(): Adapter
    {
        $adapter = $this->getMockBuilder(Adapter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $adapter->method('__call')->willReturn(null);

        return $adapter;
    }
}
