<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use MediaWiki\JobQueue\Job;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Shell\Shell;
use MWExceptionHandler;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Wikimedia\Rdbms\IDatabase;

class PostCreateWikiJob extends Job {

    public const JOB_NAME = 'PostCreateWikiJob';

    private string $dbname;
    private string $requester;
    private array $extra;

    public function __construct(
        ExtensionRegistry $extensionRegistry,
        CreateWikiHookRunner $hookRunner,
        DBLoadBalancerFactory $lbFactory
    ) {
        parent::__construct( 'PostCreateWikiJob' );
        $this->extensionRegistry = $extensionRegistry;
        $this->hookRunner = $hookRunner;
        $this->lbFactory = $lbFactory;
    }

    public function run(): bool {
        $limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

        try {
            Shell::makeScriptCommand(
                'SetContainersAccess',
                [ '--wiki', $this->dbname ]
            )->limits( $limits )->execute();

            if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
                Shell::makeScriptCommand(
                    'PopulateMainPage',
                    [ '--wiki', $this->dbname ]
                )->limits( $limits )->execute();
            }

            if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
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