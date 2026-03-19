<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\DeploymentGroupManager;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\DeploymentGroupManager
 */
class DeploymentGroupManagerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			ConfigNames::DeploymentGroupsDefaultDeployment => 'stable',
			ConfigNames::DeploymentGroupsDefaultGroup => 'default',
		] );
	}

	public function addDBDataOnce(): void {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		'@phan-var CreateWikiDatabaseUtils $databaseUtils';
		$dbw = $databaseUtils->getGlobalPrimaryDB();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->ignore()
			->row( [
				'wiki_dbname' => WikiMap::getCurrentWikiId(),
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'TestWiki',
				'wiki_language' => 'en',
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'test',
				'wiki_deployment_group' => 'default',
			] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->ignore()
			->row( [
				'wiki_dbname' => 'examplewiki',
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'ExampleWiki',
				'wiki_language' => 'en',
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'test',
				'wiki_deployment_group' => 'default',
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getManager(): DeploymentGroupManager {
		return $this->getServiceContainer()->get( 'DeploymentGroupManager' );
	}

	/**
	 * @covers ::ensureDefaultGroupExists
	 * @covers ::getDefaultDeployment
	 * @covers ::getDefaultGroup
	 * @covers ::groupExists
	 */
	public function testEnsureDefaultGroupExists(): void {
		$manager = $this->getManager();
		$this->assertSame( 'default', $manager->ensureDefaultGroupExists() );
		$this->assertTrue( $manager->groupExists( 'default' ) );
		$this->assertSame( 'stable', $manager->getGroupDeployment( 'default' ) );
	}

	/**
	 * @covers ::createGroup
	 * @covers ::getGroupDeployment
	 * @covers ::setGroupDeployment
	 */
	public function testCreateAndPinGroup(): void {
		$manager = $this->getManager();
		if ( !$manager->createGroup( 'canarytest', 'mw-1.45-canary' ) ) {
			$this->assertTrue( $manager->setGroupDeployment( 'canarytest', 'mw-1.45-canary' ) );
		}
		$this->assertTrue( $manager->setGroupDeployment( 'canarytest', 'mw-1.45-canary2' ) );
		$this->assertSame( 'mw-1.45-canary2', $manager->getGroupDeployment( 'canarytest' ) );
	}

	/**
	 * @covers ::assignWikiToGroup
	 * @covers ::resolveWikiDeployment
	 * @covers ::resolveWikiGroup
	 */
	public function testAssignWikiToGroup(): void {
		$manager = $this->getManager();
		if ( !$manager->createGroup( 'betatest', 'mw-1.45-beta' ) ) {
			$this->assertTrue( $manager->setGroupDeployment( 'betatest', 'mw-1.45-beta' ) );
		}
		$this->assertTrue( $manager->assignWikiToGroup( WikiMap::getCurrentWikiId(), 'betatest' ) );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( 'wiki_deployment_group' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => WikiMap::getCurrentWikiId() ] )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( 'betatest', $row->wiki_deployment_group );
		$this->assertSame( 'mw-1.45-beta', $manager->resolveWikiDeployment( 'betatest' ) );
	}

	/**
	 * @covers ::getWikisByGroup
	 */
	public function testGetWikisByGroup(): void {
		$manager = $this->getManager();
		$wikisByGroup = $manager->getWikisByGroup();

		$this->assertArrayHasKey( 'default', $wikisByGroup );
		$this->assertContains( WikiMap::getCurrentWikiId(), $wikisByGroup['default'] );
		$this->assertContains( 'examplewiki', $wikisByGroup['default'] );
	}

	/**
	 * @covers ::replaceGroupMembers
	 */
	public function testReplaceGroupMembers(): void {
		$manager = $this->getManager();
		if ( !$manager->createGroup( 'memberset', 'mw-1.45-memberset' ) ) {
			$this->assertTrue( $manager->setGroupDeployment( 'memberset', 'mw-1.45-memberset' ) );
		}

		$result = $manager->replaceGroupMembers( 'memberset', [ WikiMap::getCurrentWikiId(), 'examplewiki' ] );
		$this->assertNotNull( $result );
		$this->assertSame( [], $result['missing'] );
		$this->assertSameCanonicalizing( [ WikiMap::getCurrentWikiId(), 'examplewiki' ], $result['added'] );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( 'wiki_deployment_group' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => 'examplewiki' ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( 'memberset', $row->wiki_deployment_group );

		$result = $manager->replaceGroupMembers( 'memberset', [ WikiMap::getCurrentWikiId() ] );
		$this->assertNotNull( $result );
		$this->assertSame( [ 'examplewiki' ], $result['removed'] );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( 'wiki_deployment_group' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => 'examplewiki' ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( 'default', $row->wiki_deployment_group );

		$result = $manager->replaceGroupMembers( 'memberset', [ 'missingwiki' ] );
		$this->assertNotNull( $result );
		$this->assertSame( [ 'missingwiki' ], $result['missing'] );
	}

	/**
	 * @covers ::assignWikiToGroup
	 * @covers ::deleteGroup
	 */
	public function testDeleteGroup(): void {
		$manager = $this->getManager();
		if ( !$manager->createGroup( 'deleteme', 'mw-1.45-delete' ) ) {
			$this->assertTrue( $manager->setGroupDeployment( 'deleteme', 'mw-1.45-delete' ) );
		}

		$this->assertTrue( $manager->assignWikiToGroup( 'examplewiki', 'deleteme' ) );
		$this->assertTrue( $manager->deleteGroup( 'deleteme' ) );
		$this->assertFalse( $manager->groupExists( 'deleteme' ) );
		$this->assertFalse( $manager->deleteGroup( 'default' ) );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( 'wiki_deployment_group' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => 'examplewiki' ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( 'default', $row->wiki_deployment_group );
	}
}
