<?php

namespace Miraheze\CreateWiki\Specials;

use InvalidArgumentException;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\DeploymentGroupManager;
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
				],
			],
			'groupname' => [
				'type' => 'text',
				'label-message' => 'createwiki-deploygroups-label-groupname',
				'required' => false,
				'maxlength' => 64,
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
		$action = $formData['deployaction'];
		$groupName = trim( $formData['groupname'] );
		$deployment = trim( $formData['deployment'] );
		$dbname = trim( $formData['dbname'] );

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
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg( 'createwiki-deploygroups-success-created', $groupName )->escaped()
					) );
					return Status::newGood();
				case 'pin':
					if ( $groupName === '' || $deployment === '' ) {
						return Status::newFatal( 'createwiki-deploygroups-error-missing-fields' );
					}

					if ( !$this->deploymentGroupManager->setGroupDeployment( $groupName, $deployment ) ) {
						return Status::newFatal( 'createwiki-deploygroups-error-group-missing' );
					}

					$this->dataStore->resetDatabaseLists( isNewChanges: true );
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
					$this->getOutput()->addHTML( Html::successBox(
						$this->msg( 'createwiki-deploygroups-success-assigned', $dbname, $groupName )->escaped()
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
		$rows = '';

		foreach ( $groups as $groupName => $deployment ) {
			$rows .= Html::rawElement(
				'tr',
				[],
				Html::element( 'td', [], $groupName ) .
				Html::element( 'td', [], $deployment ) .
				Html::element( 'td', [], (string)$this->deploymentGroupManager->countWikisInGroup( $groupName ) )
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
				Html::element( 'th', [], $this->msg( 'createwiki-deploygroups-label-membercount' )->text() )
			) .
			$rows
		) );
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
