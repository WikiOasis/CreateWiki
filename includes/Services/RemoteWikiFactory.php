<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class RemoteWikiFactory {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataStore $dataStore,
		private readonly DeploymentGroupManager $deploymentGroupManager,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly ServiceOptions $options,
	) {
	}

	public function newInstance( string $dbname ): RemoteWiki {
		return new RemoteWiki(
			$this->databaseUtils,
			$this->dataStore,
			$this->deploymentGroupManager,
			$this->hookRunner,
			$this->jobQueueGroupFactory,
			$this->options,
			$dbname
		);
	}
}
