<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use MediaWiki\JobQueue\Job;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Shell\Shell;
use MWExceptionHandler;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class PostCreateWikiJob extends Job {

    public const JOB_NAME = 'PostCreateWikiJob';

    private string $dbname;
    private string $requester;
    private array $extra;

    public function __construct(
        array $params,
        private readonly ExtensionRegistry $extensionRegistry,
        private readonly CreateWikiHookRunner $hookRunner,
    ) {
        parent::__construct( self::JOB_NAME, $params );

        $this->dbname = $params['dbname'];
        $this->requester = $params['requester'];
        $this->extra = $params['extra'] ?? [];
    }

    public function run(): bool {
        $limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

        try {
            if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
                Shell::makeScriptCommand(
                    'CentralAuth:createLocalAccount',
                    [
                        $this->requester,
                        '--wiki', $this->dbname,
                    ]
                )->limits( $limits )->execute();

                Shell::makeScriptCommand(
                    'CreateAndPromote',
                    [
                        $this->requester,
                        '--bureaucrat',
                        '--interface-admin',
                        '--sysop',
                        '--force',
                        '--wiki', $this->dbname,
                    ]
                )->limits( $limits )->execute();
            }

            if ( $this->extra ) {
                $this->hookRunner->onCreateWikiAfterCreationWithExtraData( $this->extra, $this->dbname );
            }

            return true;
        } catch ( Exception $e ) {
            MWExceptionHandler::logException( $e );
            return false;
        }
    }
}