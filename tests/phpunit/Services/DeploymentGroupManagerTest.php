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
}
