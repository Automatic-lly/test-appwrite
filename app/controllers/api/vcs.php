<?php

use Utopia\App;
use Appwrite\Event\Build;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\Database\Helpers\ID;
use Appwrite\Extend\Exception;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;

App::get('/v1/vcs/github/installations')
    ->desc('Install GitHub App')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'createInstallation')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->inject('response')
    ->inject('project')
    ->action(function (Response $response, Document $project) {
        $projectId = $project->getId();
        //TODO: Update the name of the GitHub App
        $response->redirect("https://github.com/apps/demoappkh/installations/new?state=$projectId");
    });

App::get('/v1/vcs/github/incominginstallation')
    ->desc('Capture installation id and state after GitHub App Installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->param('installation_id', '', new Text(256), 'installation_id')
    ->param('setup_action', '', new Text(256), 'setup_action')
    ->param('state', '', new Text(256), 'state')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $setup_action, string $state, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $state);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $projectInternalId = $project->getInternalId();

        $vcsInstallation = $dbForConsole->findOne('vcs_installations', [
            Query::equal('installationId', [$installationId]),
            Query::equal('projectInternalId', [$projectInternalId])
        ]);

        if (!$vcsInstallation) {
            $github = new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'installationId' => $installationId,
                'projectId' => $state,
                'projectInternalId' => $projectInternalId,
                'provider' => "GitHub",
                'accessToken' => null
            ]);
    
            $github = $dbForConsole->createDocument('vcs_installations', $github);
        }

        $response
            ->redirect("http://localhost:3000/console/project-$state/settings");
    });

App::get('v1/vcs/github/installations/:vcsInstallationId/repositories')
    ->desc('List repositories')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'listRepositories')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('vcsInstallationId', '', new Text(256), 'VCS Installation Id')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $vcsInstallationId, Response $response, Database $dbForConsole) {
        $vcsInstallations = Authorization::skip(fn () => $dbForConsole
            ->getDocument('vcs_installations', $vcsInstallationId));
        $installationId = $vcsInstallations->getAttribute('installationId');

        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        //TODO: Update GitHub Username
        $github = new GitHub();
        $github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');
        $repos = $github->listRepositoriesForGitHubApp();
        $response->json($repos);
    });

App::post('/v1/vcs/github/incomingwebhook')
    ->desc('Captures GitHub Webhook Events')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('cache')
    ->inject('db')
    ->action(
        function (Request $request, Response $response, Database $dbForConsole, mixed $cache, mixed $db) {
            $cache = new Cache(new Redis($cache));
            $event = $request->getHeader('x-github-event', '');
            $payload = $request->getRawPayload();
            $github = new GitHub();
            $parsedPayload = $github->parseWebhookEventPayload($event, $payload);
            $parsedPayload = json_decode($parsedPayload, true);

            if ($event == $github::EVENT_PUSH) {
                $branchName = $parsedPayload["branch"];
                $repositoryId = $parsedPayload["repositoryId"];
                $installationId = $parsedPayload["installationId"];

                //find functionId from functions table
                $resources = $dbForConsole->find('vcs_repos', [
                    Query::equal('repositoryId', [$repositoryId]),
                    Query::limit(100),
                ]);

                foreach ($resources as $resource) {
                    $resourceType = $resource->getAttribute('resourceType');

                    if ($resourceType == "function") {
                        // TODO: For cloud, we might have different $db
                        $dbForProject = new Database(new MariaDB($db), $cache);
                        $dbForProject->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                        $dbForProject->setNamespace("_{$resource->getAttribute('projectInternalId')}");

                        $functionId = $resource->getAttribute('resourceId');
                        //TODO: Why is Authorization::skip needed?
                        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));
                        $projectId = $resource->getAttribute('projectId');
                        //TODO: Why is Authorization::skip needed?
                        $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
                        $deploymentId = ID::unique();
                        $entrypoint = 'index.js'; //TODO: Read from function settings
                        $vcsRepoId = $resource->getId();
                        $vcsInstallationId = $resource->getAttribute('vcsInstallationId');
                        $activate = false;

                        if ($branchName == "main") {
                            $activate = true;
                        }

                        $deployment = $dbForProject->createDocument('deployments', new Document([
                            '$id' => $deploymentId,
                            '$permissions' => [
                                Permission::read(Role::any()),
                                Permission::update(Role::any()),
                                Permission::delete(Role::any()),
                            ],
                            'resourceId' => $functionId,
                            'resourceType' => 'functions',
                            'entrypoint' => $entrypoint,
                            'type' => "vcs",
                            'vcsInstallationId' => $vcsInstallationId,
                            'vcsRepoId' => $vcsRepoId,
                            'branch' => $branchName,
                            'search' => implode(' ', [$deploymentId, $entrypoint]),
                            'activate' => $activate,
                        ]));

                        $buildEvent = new Build();
                        $buildEvent
                            ->setType(BUILD_TYPE_DEPLOYMENT)
                            ->setResource($function)
                            ->setDeployment($deployment)
                            ->setProject($project)
                            ->trigger();

                        //TODO: Add event?
                    }
                }
            } else if ($event == $github::EVENT_INSTALLATION) {
                $installationId = $parsedPayload["installationId"];

                $vcsInstallations = $dbForConsole->find('vcs_installations', [
                    Query::equal('installationId', [$installationId]),
                    Query::limit(1000)
                ]);

                foreach ($vcsInstallations as $installation) {
                    $vcsRepos = $dbForConsole->find('vcs_repos', [
                        Query::equal('vcsInstallationId', [$installation->getId()]),
                        Query::limit(1000)
                    ]);

                    foreach ($vcsRepos as $repo) {
                        $dbForConsole->deleteDocument('vcs_repos', $repo->getId());
                    }

                    $dbForConsole->deleteDocument('vcs_installations', $installation->getId());
                }
            } else if ($event == $github::EVENT_PULL_REQUEST) {
                // find last push to this branch
                // find vcsRepoId using repoId
                // find deploymentId using vcsRepoId
                // find build status using deploymentId
            }

            $response->json($parsedPayload);
        }
    );
