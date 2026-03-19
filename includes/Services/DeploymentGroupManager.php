<?php

namespace Miraheze\CreateWiki\Services;

use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\ConfigNames;
use stdClass;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

class DeploymentGroupManager {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::DeploymentGroupsDefaultDeployment,
		ConfigNames::DeploymentGroupsDefaultGroup,
	];

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function getDefaultDeployment(): string {
		return $this->normalizeDeployment( (string)$this->options->get(
			ConfigNames::DeploymentGroupsDefaultDeployment
		) );
	}

	public function getDefaultGroup(): string {
		return $this->normalizeGroupName( (string)$this->options->get(
			ConfigNames::DeploymentGroupsDefaultGroup
		) );
	}

	public function ensureDefaultGroupExists(): string {
		$defaultGroup = $this->getDefaultGroup();

		if ( $this->groupExists( $defaultGroup ) ) {
			return $defaultGroup;
		}

		$this->createGroup( $defaultGroup, $this->getDefaultDeployment() );
		return $defaultGroup;
	}

	public function getGroups(): array {
		$groups = [];
		$dbr = $this->databaseUtils->getGlobalReplicaDB();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cdg_name', 'cdg_deployment' ] )
			->from( 'cw_deployment_groups' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				continue;
			}

			$groups[$row->cdg_name] = $row->cdg_deployment;
		}

		$defaultGroup = $this->getDefaultGroup();
		if ( !array_key_exists( $defaultGroup, $groups ) ) {
			$groups[$defaultGroup] = $this->getDefaultDeployment();
		}

		return $groups;
	}

	public function getGroupDeployment( string $groupName ): ?string {
		$groupName = $this->normalizeGroupName( $groupName );
		$dbr = $this->databaseUtils->getGlobalReplicaDB();
		$row = $dbr->newSelectQueryBuilder()
			->select( 'cdg_deployment' )
			->from( 'cw_deployment_groups' )
			->where( [ 'cdg_name' => $groupName ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row ) {
			return $row->cdg_deployment;
		}

		if ( $groupName === $this->getDefaultGroup() ) {
			return $this->getDefaultDeployment();
		}

		return null;
	}

	public function groupExists( string $groupName ): bool {
		$groupName = $this->normalizeGroupName( $groupName );
		$dbr = $this->databaseUtils->getGlobalReplicaDB();
		$row = $dbr->newSelectQueryBuilder()
			->select( 'cdg_name' )
			->from( 'cw_deployment_groups' )
			->where( [ 'cdg_name' => $groupName ] )
			->caller( __METHOD__ )
			->fetchRow();

		return (bool)$row;
	}

	public function createGroup( string $groupName, ?string $deployment = null ): bool {
		$groupName = $this->normalizeGroupName( $groupName );
		$deployment = $deployment === null ? $this->getDefaultDeployment() : $this->normalizeDeployment( $deployment );

		if ( $this->groupExists( $groupName ) ) {
			return false;
		}

		$dbw = $this->databaseUtils->getGlobalPrimaryDB();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_deployment_groups' )
			->row( [
				'cdg_name' => $groupName,
				'cdg_deployment' => $deployment,
				'cdg_created' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	public function setGroupDeployment( string $groupName, string $deployment ): bool {
		$groupName = $this->normalizeGroupName( $groupName );
		$deployment = $this->normalizeDeployment( $deployment );
		if ( !$this->groupExists( $groupName ) && $groupName !== $this->getDefaultGroup() ) {
			return false;
		}

		$dbw = $this->databaseUtils->getGlobalPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'cw_deployment_groups' )
			->set( [ 'cdg_deployment' => $deployment ] )
			->where( [ 'cdg_name' => $groupName ] )
			->caller( __METHOD__ )
			->execute();

		if ( !$dbw->affectedRows() ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'cw_deployment_groups' )
				->ignore()
				->row( [
					'cdg_name' => $groupName,
					'cdg_deployment' => $deployment,
					'cdg_created' => $dbw->timestamp(),
				] )
				->caller( __METHOD__ )
				->execute();
		}

		return true;
	}

	public function assignWikiToGroup( string $dbname, string $groupName ): bool {
		$groupName = $this->normalizeGroupName( $groupName );
		if ( !$this->groupExists( $groupName ) ) {
			if ( $groupName !== $this->getDefaultGroup() ) {
				return false;
			}

			$this->ensureDefaultGroupExists();
		}

		$dbw = $this->databaseUtils->getGlobalPrimaryDB();
		$wiki = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $dbname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$wiki ) {
			return false;
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'cw_wikis' )
			->set( [ 'wiki_deployment_group' => $groupName ] )
			->where( [ 'wiki_dbname' => $dbname ] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	public function resolveWikiGroup( ?string $groupName ): string {
		$defaultGroup = $this->getDefaultGroup();
		if ( $groupName === null ) {
			return $defaultGroup;
		}

		$groupName = strtolower( trim( $groupName ) );
		if (
			$groupName === '' ||
			strlen( $groupName ) > 64 ||
			!preg_match( '/^[a-z0-9_-]+$/', $groupName )
		) {
			return $defaultGroup;
		}

		$groups = $this->getGroups();
		if ( !array_key_exists( $groupName, $groups ) ) {
			return $defaultGroup;
		}

		return $groupName;
	}

	public function resolveWikiDeployment( ?string $groupName ): string {
		$groups = $this->getGroups();
		$resolvedGroup = $this->resolveWikiGroup( $groupName );
		return $groups[$resolvedGroup] ?? $this->getDefaultDeployment();
	}

	public function countWikisInGroup( string $groupName ): int {
		$groupName = $this->normalizeGroupName( $groupName );
		$dbr = $this->databaseUtils->getGlobalReplicaDB();
		return $dbr->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_deployment_group' => $groupName ] )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	public function getWikisByGroup(): array {
		$groups = [];
		foreach ( $this->getGroups() as $groupName => $_deployment ) {
			$groups[$groupName] = [];
		}

		$defaultGroup = $this->getDefaultGroup();
		if ( !array_key_exists( $defaultGroup, $groups ) ) {
			$groups[$defaultGroup] = [];
		}

		$dbr = $this->databaseUtils->getGlobalReplicaDB();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'wiki_dbname', 'wiki_deployment_group' ] )
			->from( 'cw_wikis' )
			->orderBy( 'wiki_dbname' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				continue;
			}

			$resolvedGroup = $defaultGroup;
			try {
				$candidateGroup = $this->normalizeGroupName( (string)$row->wiki_deployment_group );
				if ( array_key_exists( $candidateGroup, $groups ) ) {
					$resolvedGroup = $candidateGroup;
				}
			} catch ( InvalidArgumentException ) {
				$resolvedGroup = $defaultGroup;
			}

			$groups[$resolvedGroup][] = $row->wiki_dbname;
		}

		return $groups;
	}

	public function replaceGroupMembers( string $groupName, array $wikiDbnames ): ?array {
		$groupName = $this->normalizeGroupName( $groupName );
		$defaultGroup = $this->getDefaultGroup();
		if ( !$this->groupExists( $groupName ) ) {
			if ( $groupName !== $defaultGroup ) {
				return null;
			}

			$this->ensureDefaultGroupExists();
		}

		$normalizedWikis = [];
		foreach ( $wikiDbnames as $wikiDbname ) {
			$wikiDbname = strtolower( trim( (string)$wikiDbname ) );
			if ( $wikiDbname === '' ) {
				continue;
			}

			$normalizedWikis[$wikiDbname] = true;
		}

		$wikiDbnames = array_keys( $normalizedWikis );
		$dbw = $this->databaseUtils->getGlobalPrimaryDB();

		$existingWikiMap = [];
		if ( $wikiDbnames ) {
			$existingWikisRes = $dbw->newSelectQueryBuilder()
				->select( 'wiki_dbname' )
				->from( 'cw_wikis' )
				->where( [ 'wiki_dbname' => $wikiDbnames ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $existingWikisRes as $row ) {
				if ( !$row instanceof stdClass ) {
					continue;
				}

				$existingWikiMap[$row->wiki_dbname] = true;
			}
		}

		$missing = [];
		foreach ( $wikiDbnames as $wikiDbname ) {
			if ( !array_key_exists( $wikiDbname, $existingWikiMap ) ) {
				$missing[] = $wikiDbname;
			}
		}

		if ( $missing ) {
			return [
				'added' => [],
				'missing' => $missing,
				'removed' => [],
			];
		}

		$currentWikisRes = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_deployment_group' => $groupName ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$currentWikis = [];
		foreach ( $currentWikisRes as $row ) {
			if ( !$row instanceof stdClass ) {
				continue;
			}

			$currentWikis[] = $row->wiki_dbname;
		}

		$currentWikiMap = array_fill_keys( $currentWikis, true );
		$targetWikiMap = array_fill_keys( $wikiDbnames, true );

		$toAdd = [];
		foreach ( $wikiDbnames as $wikiDbname ) {
			if ( !array_key_exists( $wikiDbname, $currentWikiMap ) ) {
				$toAdd[] = $wikiDbname;
			}
		}

		$toRemove = [];
		foreach ( $currentWikis as $wikiDbname ) {
			if ( !array_key_exists( $wikiDbname, $targetWikiMap ) ) {
				$toRemove[] = $wikiDbname;
			}
		}

		if ( $toAdd ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'cw_wikis' )
				->set( [ 'wiki_deployment_group' => $groupName ] )
				->where( [ 'wiki_dbname' => $toAdd ] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( $toRemove && $groupName !== $defaultGroup ) {
			if ( !$this->groupExists( $defaultGroup ) ) {
				$this->ensureDefaultGroupExists();
			}

			$dbw->newUpdateQueryBuilder()
				->update( 'cw_wikis' )
				->set( [ 'wiki_deployment_group' => $defaultGroup ] )
				->where( [ 'wiki_dbname' => $toRemove ] )
				->caller( __METHOD__ )
				->execute();
		} else {
			$toRemove = [];
		}

		return [
			'added' => $toAdd,
			'missing' => [],
			'removed' => $toRemove,
		];
	}

	private function normalizeGroupName( string $groupName ): string {
		$groupName = strtolower( trim( $groupName ) );
		if (
			$groupName === '' ||
			strlen( $groupName ) > 64 ||
			!preg_match( '/^[a-z0-9_-]+$/', $groupName )
		) {
			throw new InvalidArgumentException( 'Invalid deployment group name.' );
		}

		return $groupName;
	}

	private function normalizeDeployment( string $deployment ): string {
		$deployment = trim( $deployment );
		if (
			$deployment === '' ||
			strlen( $deployment ) > 128 ||
			!preg_match( '#^[A-Za-z0-9._:/-]+$#', $deployment )
		) {
			throw new InvalidArgumentException( 'Invalid deployment identifier.' );
		}

		return $deployment;
	}
}
