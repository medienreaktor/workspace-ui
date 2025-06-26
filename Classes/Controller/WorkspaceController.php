<?php

/*
 * This file is part of the Neos.Workspace.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Workspace\Ui\Controller;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvent;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\Html\HtmlArrayRenderer;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Fusion\View\FusionView;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\WorkspaceClassification;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceRoleSubject;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\PendingChangesProjection\Changes;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Neos\Utility\NodeTypeWithFallbackProvider;
use Neos\Workspace\Ui\Service\DifferencesService;
use Neos\Workspace\Ui\ViewModel\ChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItems;
use Neos\Workspace\Ui\ViewModel\ContentChangeProperties;
use Neos\Workspace\Ui\ViewModel\ContentChanges\AssetContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\DateTimeContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\ImageContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TagContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TextContentChange;
use Neos\Workspace\Ui\ViewModel\DocumentChangeItem;
use Neos\Workspace\Ui\ViewModel\DocumentItem;
use Neos\Workspace\Ui\ViewModel\EditWorkspaceFormData;
use Neos\Workspace\Ui\ViewModel\PendingChanges;
use Neos\Workspace\Ui\ViewModel\Sorting;
use Neos\Workspace\Ui\ViewModel\WorkspaceListItem;
use Neos\Workspace\Ui\ViewModel\WorkspaceListItems;

/**
 * The Neos Workspace module controller
 *
 * @internal for communication within the Workspace UI only
 */
#[Flow\Scope('singleton')]
class WorkspaceController extends AbstractModuleController {
    use NodeTypeWithFallbackProvider;

    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected PropertyMapper $propertyMapper;

    #[Flow\Inject]
    protected Context $securityContext;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PackageManager $packageManager;

    #[Flow\Inject]
    protected WorkspacePublishingService $workspacePublishingService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected WorkspaceMetadataAndRoleRepository $workspaceMetadataAndRoleRepository;


    #[Flow\Inject]
    protected DifferencesService $differencesService;
    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected PolicyService $policyService;

    #[Flow\Inject]
    protected ContentRepositoryAuthorizationService $authorizationService;

    /**
     * Display a list of unpublished content
     */
    public function indexAction(Sorting|null $sorting = null): void {
        $sorting ??= new Sorting(
            sortBy: 'title',
            sortAscending: true
        );

        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1718308216);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspaceListItems = $this->getWorkspaceListItems($contentRepository);
        $workspaceListItems = match ($sorting->sortBy) {
            'title' => $workspaceListItems->sortByTitle($sorting->sortAscending),
        };

        $this->view->assignMultiple([
            'workspaceListItems' => $workspaceListItems,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'sorting' => $sorting,
        ]);
    }

    public function reviewAction(WorkspaceName $workspace): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1720371024);
        }
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspaceObj = $contentRepository->findWorkspaceByName($workspace);
        if (is_null($workspaceObj)) {
            $title = WorkspaceTitle::fromString($workspace->value);
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist', [$title->value]),
                '',
                Message::SEVERITY_ERROR
            );
            $this->forward('index');
        }

        $workspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $workspace, $this->securityContext->getRoles(), $currentUser->getId());
        if (!$workspacePermissions->read) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.changes.noPermissionToReadWorkspace'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->forward('index');
        }
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace);
        $baseWorkspaceMetadata = null;
        $baseWorkspacePermissions = null;
        $baseWorkspace = $workspaceObj->baseWorkspaceName !== null
            ? $contentRepository->findWorkspaceByName($workspaceObj->baseWorkspaceName)
            : null;
        if ($baseWorkspace !== null) {
            $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $baseWorkspace->workspaceName);
            $baseWorkspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $baseWorkspace->workspaceName, $this->securityContext->getRoles(), $currentUser->getId());
        }
        $computeSiteChanges = $this->differencesService->computeSiteChanges($workspaceObj, $contentRepository);
        $this->view->assignMultiple([
            'selectedWorkspaceName' => $workspaceObj->workspaceName->value,
            'selectedWorkspaceLabel' => $workspaceMetadata->title->value,
            'baseWorkspaceName' => $workspaceObj->baseWorkspaceName,
            'baseWorkspaceLabel' => $baseWorkspaceMetadata?->title->value,
            'canPublishToBaseWorkspace' => $baseWorkspacePermissions?->write ?? false,
            'canPublishToWorkspace' => $workspacePermissions->write,
            'siteChanges' => $computeSiteChanges,
            'contentDimensions' => $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority(),
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    public function reviewDocumentAction(string $nodeAddress = '{"contentRepositoryId":"default","workspaceName":"test","dimensionSpacePoint":[],"aggregateId":"7ca2d6c6-629d-4eb2-8cf7-77803251f8f7"}'): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1720371024);
        }
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $workspace = $contentRepository->findWorkspaceByName($nodeAddress->workspaceName);
        $node = $contentRepository->getContentSubgraph($workspace->workspaceName,
            $nodeAddress->dimensionSpacePoint)->findNodeById($nodeAddress->aggregateId);

        $computeDocumentChanges = $this->differencesService->computeDocumentChanges($node, $contentRepository);
        /** @var ContentChangeItems $contentChanges */
        $contentChanges = array_values($computeDocumentChanges['changes']);
        $this->view->assignMultiple([
            "changes" => $contentChanges
        ]);
    }

    public function newAction(): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $this->view->assign('baseWorkspaceOptions', $this->prepareBaseWorkspaceOptions($contentRepository, null));
    }

    public function createAction(
        WorkspaceTitle       $title,
        WorkspaceName        $baseWorkspace,
        WorkspaceDescription $description,
        string               $visibility = 'shared',
    ): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1718303756);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceName = $this->workspaceService->getUniqueWorkspaceName($contentRepositoryId, $title->value);

        $assignments = match ($visibility) {
            'shared' => WorkspaceRoleAssignments::createForSharedWorkspace($currentUser->getId()),
            'private' => WorkspaceRoleAssignments::createForPrivateWorkspace($currentUser->getId()),
            default => throw new \RuntimeException(sprintf('Invalid visibility %s given', $visibility), 1736343542)
        };

        try {
            $this->workspaceService->createSharedWorkspace(
                $contentRepositoryId,
                $workspaceName,
                $title,
                $description,
                $baseWorkspace,
                $assignments
            );
        } catch (WorkspaceAlreadyExists $exception) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceWithThisTitleAlreadyExists'),
                '',
                Message::SEVERITY_WARNING
            );
            $this->throwStatus(400, 'Workspace with this title already exists');
        } catch (\Exception $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                $this->getModuleLabel('workspaces.workspaceCouldNotBeCreated'),
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(500, 'Workspace could not be created');
        }
        $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceHasBeenCreated', [$title->value]));
        $this->forward('index');
    }

    /**
     * Edit a workspace
     *
     * @param WorkspaceName $workspaceName The name of the workspace that is being edited
     */
    public function editAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        $title = WorkspaceTitle::fromString($workspaceName->value);
        if (is_null($workspace)) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist', [$title->value]),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        if ($workspace->isRootWorkspace()) {
            throw new \RuntimeException(sprintf('Workspace %s does not have a base-workspace.', $workspace->workspaceName->value), 1734019485);
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $workspaceRoleAssignments = $this->workspaceService->getWorkspaceRoleAssignments($contentRepositoryId, $workspace->workspaceName);
        $isShared = false;
        if ($workspaceMetadata->classification === WorkspaceClassification::SHARED) {
            foreach ($workspaceRoleAssignments as $roleAssignment) {
                if ($roleAssignment->role === WorkspaceRole::COLLABORATOR) {
                    $isShared = true;
                }
            }
        }

        $editWorkspaceDto = new EditWorkspaceFormData(
            workspaceName: $workspace->workspaceName,
            workspaceTitle: $workspaceMetadata->title,
            workspaceDescription: $workspaceMetadata->description,
            workspaceHasChanges: $this->differencesService->computePendingChanges($workspace, $contentRepository)->total > 0,
            baseWorkspaceName: $workspace->baseWorkspaceName,
            baseWorkspaceOptions: $this->prepareBaseWorkspaceOptions($contentRepository, $workspaceName),
            isShared: $isShared,
        );

        $this->view->assign('editWorkspaceFormData', $editWorkspaceDto);
    }

    /**
     * Update a workspace
     *
     * @Flow\Validate(argumentName="title", type="\Neos\Flow\Validation\Validator\NotEmptyValidator")
     * @param WorkspaceName $workspaceName The name of the workspace that is being updated
     * @param WorkspaceTitle $title Human friendly title of the workspace, for example "Christmas Campaign"
     * @param WorkspaceDescription $description A description explaining the purpose of the new workspace
     * @param string $visibility Allow other editors to collaborate on this workspace if set to "shared"
     * @param WorkspaceName|null $baseWorkspace The base workspace to rebase this workspace onto if modified
     */
    public function updateAction(
        WorkspaceName        $workspaceName,
        WorkspaceTitle       $title,
        WorkspaceDescription $description,
        string               $visibility,
        WorkspaceName|null   $baseWorkspace = null,
    ): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user is authenticated', 1729505338);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        if ($title->value === '') {
            $title = WorkspaceTitle::fromString($workspaceName->value);
        }

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);

        $userCanManageWorkspace = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $workspaceName, $this->securityContext->getRoles(), $this->userService->getCurrentUser()?->getId())->manage;
        if (!$userCanManageWorkspace) {
            $this->throwStatus(403);
        }

        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        // Update Metadata
        $this->workspaceService->setWorkspaceTitle(
            $contentRepositoryId,
            $workspaceName,
            $title,
        );
        $this->workspaceService->setWorkspaceDescription(
            $contentRepositoryId,
            $workspaceName,
            $description,
        );

        $workspaceRoleAssignments = $this->workspaceService->getWorkspaceRoleAssignments($contentRepositoryId, $workspaceName);
        $sharedRoleAssignment = WorkspaceRoleAssignment::createForGroup(
            'Neos.Neos:AbstractEditor',
            WorkspaceRole::COLLABORATOR,
        );

        match ($visibility) {
            'shared' => !$workspaceRoleAssignments->contains($sharedRoleAssignment) && $this->workspaceService->assignWorkspaceRole(
                    $contentRepositoryId,
                    $workspaceName,
                    WorkspaceRoleAssignment::createForGroup(
                        'Neos.Neos:AbstractEditor',
                        WorkspaceRole::COLLABORATOR,
                    )
                ),
            'private' => $workspaceRoleAssignments->contains($sharedRoleAssignment) && $this->workspaceService->unassignWorkspaceRole(
                    $contentRepositoryId,
                    $workspaceName,
                    WorkspaceRoleSubject::createForGroup('Neos.Neos:AbstractEditor'),
                ),
            default => throw new \RuntimeException(sprintf('Invalid visibility %s given', $visibility), 1736339457)
        };

        if ($baseWorkspace !== null && $workspace->baseWorkspaceName?->equals($baseWorkspace) === false) {
            // Update Base Workspace
            $this->workspacePublishingService->changeBaseWorkspace(
                $contentRepositoryId,
                $workspaceName,
                $baseWorkspace
            );
        }

        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.workspaceHasBeenUpdated',
                [$title->value],
            )
        );

        $this->forward('index');
    }

    /**
     * Delete a workspace
     *
     * @throws StopActionException
     */
    public function deleteAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);

        if ($workspaceMetadata->classification === WorkspaceClassification::PERSONAL) {
            $this->throwStatus(403, 'Personal workspaces cannot be deleted');
        }

        $dependentWorkspaces = $contentRepository->findWorkspaces()->getDependantWorkspaces($workspaceName);
        if (!$dependentWorkspaces->isEmpty()) {
            $dependentWorkspaceTitles = [];
            /** @var Workspace $dependentWorkspace */
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $dependentWorkspace->workspaceName);
                $dependentWorkspaceTitles[] = $dependentWorkspaceMetadata->title->value;
            }

            $message = $this->getModuleLabel(
                'workspaces.workspaceCannotBeDeletedBecauseOfDependencies',
                [$workspaceMetadata->title->value, implode(', ', $dependentWorkspaceTitles)],
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(403, 'Workspace has dependencies');
        }

        $nodesCount = 0;

        try {
            $nodesCount = $contentRepository->projectionState(ChangeFinder::class)
                ->countByContentStreamId(
                    $workspace->currentContentStreamId
                );
        } catch (\Exception $exception) {
            $message = $this->getModuleLabel(
                'workspaces.notDeletedErrorWhileFetchingUnpublishedNodes',
                [$workspaceMetadata->title->value],
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(500, 'Error while fetching unpublished nodes');
        }
        if ($nodesCount > 0) {
            $message = $this->getModuleLabel(
                'workspaces.workspaceCannotBeDeletedBecauseOfUnpublishedNodes',
                [$workspaceMetadata->title->value, $nodesCount],
                $nodesCount,
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(403, 'Workspace has unpublished nodes');
            // delete workspace on POST -> TODO: Split this into 2 actions like the create or edit workflows
        } elseif ($this->request->getHttpRequest()->getMethod() === 'POST') {
            $this->workspaceService->deleteWorkspace($contentRepositoryId, $workspaceName);

            $this->addFlashMessage(
                $this->getModuleLabel(
                    'workspaces.workspaceHasBeenRemoved',
                    [$workspaceMetadata->title->value],
                )
            );
            // Render a confirmation form if the request is not a POST request
        } else {
            $this->view->assign('workspaceName', $workspace->workspaceName->value);
            $this->view->assign('workspaceTitle', $workspaceMetadata->title->value);
        }
    }

    /**
     * Publish a single document node
     */
    public function publishDocumentAction(string $nodeAddress, WorkspaceName $selectedWorkspace): void {
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $contentRepositoryId = $nodeAddress->contentRepositoryId;
        $this->workspacePublishingService->publishChangesInDocument(
            $contentRepositoryId,
            $selectedWorkspace,
            $nodeAddress->aggregateId
        );

        $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangeHasBeenPublished'));
        $this->forward('review', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    /**
     * Discard a single document node
     *
     * @throws WorkspaceRebaseFailed
     */
    public function discardDocumentAction(string $nodeAddress, WorkspaceName $selectedWorkspace): void {
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $contentRepositoryId = $nodeAddress->contentRepositoryId;
        $this->workspacePublishingService->discardChangesInDocument(
            $contentRepositoryId,
            $selectedWorkspace,
            $nodeAddress->aggregateId
        );

        $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangeHasBeenDiscarded'));
        $this->forward('review', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    /**
     * @psalm-param list<string> $nodes
     */
    public function publishOrDiscardNodesAction(array $nodes, string $action, WorkspaceName $workspace): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;

        switch ($action) {
            case 'publish':
                foreach ($nodes as $node) {
                    $nodeAddress = NodeAddress::fromJsonString($node);
                    $this->workspacePublishingService->publishChangesInDocument(
                        $contentRepositoryId,
                        $workspace,
                        $nodeAddress->aggregateId
                    );
                }
                $this->addFlashMessage(
                    $this->getModuleLabel('workspaces.selectedChangesHaveBeenPublished')
                );
                break;
            case 'discard':
                foreach ($nodes as $node) {
                    $nodeAddress = NodeAddress::fromJsonString($node);
                    $this->workspacePublishingService->discardChangesInDocument(
                        $contentRepositoryId,
                        $workspace,
                        $nodeAddress->aggregateId
                    );
                }
                $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangesHaveBeenDiscarded'));
                break;
            default:
                throw new \RuntimeException('Invalid action "' . htmlspecialchars($action) . '" given.', 1346167441);
        }
        $this->forward('review', null, null, ['workspace' => $workspace->value]);
    }

    /**
     * Publishes the whole workspace
     */
    public function publishWorkspaceAction(WorkspaceName $workspace): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $publishingResult = $this->workspacePublishingService->publishWorkspace(
            $contentRepositoryId,
            $workspace,
        );
        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.allChangesInWorkspaceHaveBeenPublished',
                [
                    htmlspecialchars($workspace->value),
                    htmlspecialchars($publishingResult->targetWorkspaceName->value)
                ],
            )
        );
        $this->forward('index');
    }

    public function confirmPublishAllChangesAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }

    public function confirmDiscardAllChangesAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }

    public function confirmPublishSelectedChangesAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $baseWorkspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'baseWorkspaceTitle' => $baseWorkspaceMetadata->title->value,
        ]);
    }

    public function confirmDiscardSelectedChangesAction(WorkspaceName $workspaceName): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }

    /**
     * Discards content of the whole workspace
     *
     * @param WorkspaceName $workspace
     */
    public function discardWorkspaceAction(WorkspaceName $workspace): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;

        $this->workspacePublishingService->discardAllWorkspaceChanges(
            $contentRepositoryId,
            $workspace,
        );
        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.allChangesInWorkspaceHaveBeenDiscarded',
                [htmlspecialchars($workspace->value)],
            )
        );
        $this->forward('review', null, null, ['workspace' => $workspace->value]);
    }

    /**
     * Rebase a workspace
     */
    public function rebaseAction(WorkspaceName $workspaceName, bool $force): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;

        try {
            $this->workspacePublishingService->rebaseWorkspace(
                $contentRepositoryId,
                $workspaceName,
                $force ? RebaseErrorHandlingStrategy::STRATEGY_FORCE : RebaseErrorHandlingStrategy::STRATEGY_FAIL
            );
            $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceHasBeenRebased'));
            $this->forward('index');

        } catch (WorkspaceRebaseFailed $e) {
            if ($force) {
                $this->addFlashMessage($this->getModuleLabel('workspaces.ForceRebaseWorkspaceFailed'));
                $this->forward('index');
            }
            $conflictInformation = array_map(fn(ConflictingEvent $conflictingEvent) => [
                'error' => $conflictingEvent->getException()->getMessage(),
                'affectedNode' => $conflictingEvent->getAffectedNodeAggregateId(),
                'event' => (new \ReflectionClass($conflictingEvent->getEvent()))->getShortName() . ' ' . $conflictingEvent->getSequenceNumber()->value,
                'eventPayload' => htmlentities(json_encode($conflictingEvent->getEvent(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), ENT_NOQUOTES),
            ], iterator_to_array($e->conflictingEvents));

        }
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        if ($workspace->baseWorkspaceName === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }
        $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->baseWorkspaceName);
        $this->response->addHttpHeader('HX-Retarget', '#popover-container');
        $this->response->addHttpHeader('HX-ReSwap', 'innerHTML');

        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
            'baseWorkspaceTitle' => $baseWorkspaceMetadata->title->value,
            'conflictInformation' => $conflictInformation,
        ]);


    }

    /**
     * Confirm force rebase a workspace
     */
    public function rebaseConfirmAction(WorkspaceName $workspaceName, int $conflictCount): void {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;

        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);

        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
            'conflictCount' => $conflictCount
        ]);
    }


    /**
     * Retrieves the given node's corresponding node in the base content stream
     * (that is, which would be overwritten if the given node would be published)
     */
    protected function getOriginalNode(
        Node              $modifiedNode,
        WorkspaceName     $baseWorkspaceName,
        ContentRepository $contentRepository,
    ): ?Node {
        $baseSubgraph = $contentRepository->getContentGraph($baseWorkspaceName)->getSubgraph(
            $modifiedNode->dimensionSpacePoint,
            VisibilityConstraints::createEmpty()
        );
        return $baseSubgraph->findNodeById($modifiedNode->aggregateId);
    }

    /**
     * Renders a slimmed down representation of a property of the given node. The output will be HTML, but does not
     * contain any markup from the original content.
     *
     * Note: It's clear that this method needs to be extracted and moved to a more universal service at some point.
     * However, since we only implemented diff-view support for this particular controller at the moment, it stays
     * here for the time being. Once we start displaying diffs elsewhere, we should refactor the diff rendering part.
     */
    protected function renderSlimmedDownContent(mixed $propertyValue): string {
        $content = '';
        if (is_string($propertyValue)) {
            $contentSnippet = preg_replace('/<br[^>]*>/', "\n", $propertyValue) ?: '';
            $contentSnippet = preg_replace('/<[^>]*>/', ' ', $contentSnippet) ?: '';
            $contentSnippet = str_replace('&nbsp;', ' ', $contentSnippet) ?: '';
            $content = trim(preg_replace('/ {2,}/', ' ', $contentSnippet) ?: '');
        }
        return $content;
    }

    /**
     * Tries to determine a label for the specified property
     */
    protected function getPropertyLabel(string $propertyName, Node $changedNode): string {
        $properties = $this->getNodeType($changedNode)->getProperties();
        $label = $properties[$propertyName]['ui']['label'] ?? null;
        if ($label === null) {
            return $propertyName;
        }

        // hack, we use the eel helper here to support the shorthand syntax: PackageKey:Source:trans-unit-id
        return (new TranslationHelper())->translate($label) ?: $label;
    }

    /**
     * A workaround for some missing functionality in the Diff Renderer:
     *
     * This method will check if content in the given diff array is either completely new or has been completely
     * removed and wraps the respective part in <ins> or <del> tags, because the Diff Renderer currently does not
     * do that in these cases.
     *
     * @param array<int|string,mixed> &$diffArray
     */
    protected function postProcessDiffArray(array &$diffArray): void {
        foreach ($diffArray as $index => $blocks) {
            foreach ($blocks as $blockIndex => $block) {
                $baseLines = trim(implode('', $block['base']['lines']), " \t\n\r\0\xC2\xA0");
                $changedLines = trim(implode('', $block['changed']['lines']), " \t\n\r\0\xC2\xA0");
                if ($baseLines === '') {
                    foreach ($block['changed']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['changed']['lines'][$lineIndex] = '<ins>' . $line . '</ins>';
                    }
                }
                if ($changedLines === '') {
                    foreach ($block['base']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['base']['lines'][$lineIndex] = '<del>' . $line . '</del>';
                    }
                }
            }
        }
    }

    /**
     * Creates an array of workspace names and their respective titles which are possible base workspaces
     *
     * If $editedWorkspace is set, this workspace and all its nested workspaces will be excluded from the list of returned workspaces
     *
     * @return array<string,string>
     */
    protected function prepareBaseWorkspaceOptions(
        ContentRepository  $contentRepository,
        WorkspaceName|null $editedWorkspaceName
    ): array {
        $user = $this->userService->getCurrentUser();
        $baseWorkspaceOptions = [];
        $workspaces = $contentRepository->findWorkspaces();
        $editedWorkspace = $editedWorkspaceName ? $workspaces->get($editedWorkspaceName) : null;
        if ($editedWorkspace?->baseWorkspaceName !== null) {
            // ensure that the current base workspace is always part of the list even if permissions are not granted
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata(
                $contentRepository->id,
                $editedWorkspace->baseWorkspaceName
            );
            $baseWorkspaceOptions[$editedWorkspace->baseWorkspaceName->value] = $workspaceMetadata->title->value;
        }

        foreach ($workspaces as $workspace) {
            if ($editedWorkspaceName !== null) {
                if ($workspace->workspaceName->equals($editedWorkspaceName)) {
                    continue;
                }
                if ($workspaces->getBaseWorkspaces($workspace->workspaceName)->get($editedWorkspaceName) !== null) {
                    continue;
                }
            }
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata(
                $contentRepository->id,
                $workspace->workspaceName
            );
            if (!in_array($workspaceMetadata->classification, [WorkspaceClassification::SHARED, WorkspaceClassification::ROOT], true)) {
                continue;
            }
            $permissions = $this->authorizationService->getWorkspacePermissions(
                $contentRepository->id,
                $workspace->workspaceName,
                $this->securityContext->getRoles(),
                $user?->getId()
            );
            if (!$permissions->read) {
                continue;
            }
            $baseWorkspaceOptions[$workspace->workspaceName->value] = $workspaceMetadata->title->value;
        }

        // Sort the base workspaces by title, but make sure the live workspace is always on top
        uksort($baseWorkspaceOptions, static function (string $a, string $b) {
            if ($a === 'live') {
                return -1;
            }
            if ($b === 'live') {
                return 1;
            }
            return strcasecmp($a, $b);
        });

        return $baseWorkspaceOptions;
    }

    private function requireBaseWorkspace(
        Workspace         $workspace,
        ContentRepository $contentRepository,
    ): Workspace {
        if ($workspace->isRootWorkspace()) {
            throw new \RuntimeException(sprintf('Workspace %s does not have a base-workspace.', $workspace->workspaceName->value), 1734019485);
        }
        $baseWorkspace = $contentRepository->findWorkspaceByName($workspace->baseWorkspaceName);
        if ($baseWorkspace === null) {
            throw new \RuntimeException(sprintf('Base-workspace %s of %s does not exist.', $workspace->baseWorkspaceName->value, $workspace->workspaceName->value), 1734019720);
        }
        return $baseWorkspace;
    }

    /**
     * @param array<int|string,mixed> $arguments
     */
    public function getModuleLabel(string $id, array $arguments = [], mixed $quantity = null): string {
        return $this->translator->translateById(
            $id,
            $arguments,
            $quantity,
            null,
            'Main',
            'Neos.Workspace.Ui'
        ) ?: $id;
    }

    protected function getWorkspaceListItems(
        ContentRepository $contentRepository,
    ): WorkspaceListItems {
        $workspaceListItems = [];
        $allWorkspaces = $contentRepository->findWorkspaces();

        // add other, accessible workspaces
        foreach ($allWorkspaces as $workspace) {
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName);
            $workspaceRoleAssignments = $this->workspaceService->getWorkspaceRoleAssignments($contentRepository->id, $workspace->workspaceName);
            $workspacesPermissions = $this->authorizationService->getWorkspacePermissions(
                $contentRepository->id,
                $workspace->workspaceName,
                $this->securityContext->getRoles(),
                $this->userService->getCurrentUser()?->getId()
            );

            // ignore root workspaces, because they will not be shown in the UI
            if ($workspace->isRootWorkspace()) {
                continue;
            }

            if ($workspacesPermissions->read === false) {
                continue;
            }

            $workspaceOwner = $workspaceMetadata->ownerUserId
                ? $this->userService->findUserById($workspaceMetadata->ownerUserId)
                : null;

            $workspaceListItems[$workspace->workspaceName->value] = new WorkspaceListItem(
                $workspace->workspaceName->value,
                $workspaceMetadata->classification->value,
                $workspace->status->value,
                $workspaceMetadata->title->value,
                $workspaceMetadata->description->value,
                $workspace->baseWorkspaceName->value,
                $this->differencesService->computePendingChanges($workspace, $contentRepository),
                !$allWorkspaces->getDependantWorkspaces($workspace->workspaceName)->isEmpty(),
                $workspaceOwner?->getLabel(),
                $workspacesPermissions,
                $workspaceRoleAssignments,
            );
        }
        return WorkspaceListItems::fromArray($workspaceListItems);
    }


}
