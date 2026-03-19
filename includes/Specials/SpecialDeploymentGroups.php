<?php

namespace Miraheze\CreateWiki\Specials;

use InvalidArgumentException;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\DeploymentGroupManager;
use function array_keys;
use function count;
use function implode;
use function preg_split;
use function trim;

class SpecialDeploymentGroups extends FormSpecialPage {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataStore $dataStore,
		private readonly DeploymentGroupManager $deploymentGroupManager,
	) {
		parent::__construct( 'DeploymentGroups', 'deploygroups' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		parent::execute( $par );
		$this->showGroupsTable();
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'deployaction' => [
				'type' => 'select',
				'label-message' => 'createwiki-deploygroups-label-action',
				'required' => true,
				'options-messages' => [
					'createwiki-deploygroups-action-create' => 'create',
					'createwiki-deploygroups-action-pin' => 'pin',
					'createwiki-deploygroups-action-assign' => 'assign',
					'createwiki-deploygroups-action-setmembers' => 'setmembers',
				],
			],
			'groupname' => [
				'type' => 'text',
				'label-message' => 'createwiki-deploygroups-label-groupname',
				'required' => false,
				'maxlength' => 64,
			],
			'wikis' => [
				'type' => 'textarea',
				'label-message' => 'createwiki-deploygroups-label-wikis',
				'required' => false,
				'rows' => 10,
				'useeditfont' => true,
				'help-message' => 'createwiki-deploygroups-help-wikis',
			],
			'deployment' => [
				'type' => 'text',
				'label-message' => 'createwiki-deploygroups-label-deployment',
				'required' => false,
				'maxlength' => 128,
				'default' => $this->deploymentGroupManager->getDefaultDeployment(),
			],
			'dbname' => [
				'type' => 'text',
				'label-message' => 'createwiki-deploygroups-label-dbname',
				'required' => false,
				'maxlength' => 64,
			],
		];
	}

	/** @inheritDoc */
	public function onSubmit( array $formData ): Status {

		$action = (string)( $formData['deployaction'] ?? '' );
		$groupName = trim( (string)( $formData['groupname'] ?? '' ) );
		$deployment = trim( (string)( $formData['deployment'] ?? '' ) );
		$dbname = trim( (string)( $formData['dbname'] ?? '' ) );
		$wikis = (string)( $formData['wikis'] ?? '' );

		try {
			switch ( $action ) {
				case 'create':
					if ( $groupName === '' || $deployment === '' ) {
						return Status::newFatal( 'createwiki-deploygroups-error-missing-fields' );
					}

					if ( !$this->deploymentGroupManager->createGroup( $groupName, $deployment ) ) {
						return Status::newFatal( 'createwiki-deploygroups-error-group-exists' );
					}

					$this->dataStore->resetDatabaseLists( isNewChanges: true );
					$this->logGroupAction(
						'create',
						[
							'4::group' => $groupName,
							'5::deployment' => $deployment,
						]
					);
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg( 'createwiki-deploygroups-success-created', $groupName )->escaped()
					) );
					return Status::newGood();
				case 'pin':
					if ( $groupName === '' || $deployment === '' ) {
						return Status::newFatal( 'createwiki-deploygroups-error-missing-fields' );
					}
					$previousDeployment = $this->deploymentGroupManager->getGroupDeployment( $groupName ) ?? '-';

					if ( !$this->deploymentGroupManager->setGroupDeployment( $groupName, $deployment ) ) {
						return Status::newFatal( 'createwiki-deploygroups-error-group-missing' );
					}

					$this->dataStore->resetDatabaseLists( isNewChanges: true );
					$this->logGroupAction(
						'pin',
						[
							'4::group' => $groupName,
							'5::deployment' => $deployment,
							'6::previous' => $previousDeployment,
						]
					);
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg( 'createwiki-deploygroups-success-pinned', $groupName, $deployment )->escaped()
					) );
					return Status::newGood();
				case 'assign':
					if ( $groupName === '' || $dbname === '' ) {
						return Status::newFatal( 'createwiki-deploygroups-error-missing-fields' );
					}

					if ( !$this->deploymentGroupManager->assignWikiToGroup( $dbname, $groupName ) ) {
						return Status::newFatal( 'createwiki-deploygroups-error-assign' );
					}

					$this->dataStore->resetDatabaseLists( isNewChanges: true );
					$this->logGroupAction(
						'assign',
						[
							'4::dbname' => $dbname,
							'5::group' => $groupName,
						]
					);
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg( 'createwiki-deploygroups-success-assigned', $dbname, $groupName )->escaped()
					) );
					return Status::newGood();
				case 'setmembers':
					if ( $groupName === '' ) {
						return Status::newFatal( 'createwiki-deploygroups-error-missing-fields' );
					}

					$membershipChange = $this->deploymentGroupManager->replaceGroupMembers(
						$groupName,
						$this->parseWikiList( $wikis )
					);
					if ( $membershipChange === null ) {
						return Status::newFatal( 'createwiki-deploygroups-error-group-missing' );
					}

					if ( $membershipChange['missing'] ) {
						return Status::newFatal(
							'createwiki-deploygroups-error-wikis-missing',
							implode( ', ', $membershipChange['missing'] )
						);
					}

					$addedCount = count( $membershipChange['added'] );
					$removedCount = count( $membershipChange['removed'] );

					$this->dataStore->resetDatabaseLists( isNewChanges: true );
					$this->logGroupAction(
						'setmembers',
						[
							'4::group' => $groupName,
							'5::added' => $addedCount,
							'6::removed' => $removedCount,
						]
					);
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg(
							'createwiki-deploygroups-success-setmembers',
							$groupName,
							$addedCount,
							$removedCount
						)->escaped()
					) );
					return Status::newGood();
				default:
					return Status::newFatal( 'createwiki-deploygroups-error-invalid-action' );
			}
		} catch ( InvalidArgumentException ) {
			return Status::newFatal( 'createwiki-deploygroups-error-invalid-input' );
		}
	}

	private function showGroupsTable(): void {
		$groups = $this->deploymentGroupManager->getGroups();
		$wikisByGroup = $this->deploymentGroupManager->getWikisByGroup();
		$rows = '';

		foreach ( $groups as $groupName => $deployment ) {
			$groupWikis = $wikisByGroup[$groupName] ?? [];
			$rows .= Html::rawElement(
				'tr',
				[],
				Html::element( 'td', [], $groupName ) .
				Html::element( 'td', [], $deployment ) .
				Html::element( 'td', [], (string)count( $groupWikis ) ) .
				Html::rawElement(
					'td',
					[],
					Html::rawElement(
						'pre',
						[ 'class' => 'mw-createwiki-deploygroups-wikilist' ],
						implode( "\n", $groupWikis )
					)
				)
			);
		}

		$this->getOutput()->addHTML( Html::rawElement(
			'h2',
			[],
			$this->msg( 'createwiki-deploygroups-current' )->text()
		) );
		$this->getOutput()->addHTML( Html::rawElement(
			'table',
			[ 'class' => 'wikitable' ],
			Html::rawElement(
				'tr',
				[],
				Html::element( 'th', [], $this->msg( 'createwiki-deploygroups-label-groupname' )->text() ) .
				Html::element( 'th', [], $this->msg( 'createwiki-deploygroups-label-deployment' )->text() ) .
				Html::element( 'th', [], $this->msg( 'createwiki-deploygroups-label-membercount' )->text() ) .
				Html::element( 'th', [], $this->msg( 'createwiki-deploygroups-label-wikis' )->text() )
			) .
			$rows
		) );
	}

	private function parseWikiList( string $wikiList ): array {
		$entries = preg_split( '/[\r\n,]+/', $wikiList ) ?: [];
		$parsedWikis = [];
		foreach ( $entries as $entry ) {
			$entry = trim( $entry );
			if ( $entry === '' ) {
				continue;
			}

			$parsedWikis[$entry] = true;
		}

		return array_keys( $parsedWikis );
	}

	private function logGroupAction( string $action, array $params ): void {
		$logEntry = new ManualLogEntry( 'deploymentgroups', $action );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setParameters( $params );
		$logId = $logEntry->insert( $this->databaseUtils->getCentralWikiPrimaryDB() );
		$logEntry->publish( $logId );
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}
}
