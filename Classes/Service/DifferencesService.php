<?php

namespace Neos\Workspace\Ui\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
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
use Neos\Flow\Annotations\Inject;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\I18n\Translator;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\PendingChangesProjection\Changes;
use Neos\Workspace\Ui\ViewModel\ContentChangeItem;
use Neos\Workspace\Ui\ViewModel\PendingChanges;
use Neos\Workspace\Ui\ViewModel\ChangeItem;
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

class DifferencesService {

    #[Inject]
    public Translator $translator;
    #[Inject]
    public ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Inject]
    public NodeLabelGeneratorInterface $nodeLabelGenerator;
    /**
     * Computes the number of added, changed and removed nodes for the given workspace
     */
    public function computePendingChanges(Workspace $selectedWorkspace, ContentRepository $contentRepository): PendingChanges {
        $changesCount = ['new' => 0, 'changed' => 0, 'removed' => 0];
        foreach ($this->getChangesFromWorkspace($selectedWorkspace, $contentRepository) as $change) {
            if ($change->deleted) {
                $changesCount['removed']++;
            } elseif ($change->created) {
                $changesCount['new']++;
            } else {
                $changesCount['changed']++;
            }
        }
        return new PendingChanges(new: $changesCount['new'], changed: $changesCount['changed'], removed: $changesCount['removed']);
    }

    /**
     * Builds an array of changes for sites in the given workspace
     * @return array<string,mixed>
     */
    public function computeSiteChanges(Workspace $selectedWorkspace, ContentRepository $contentRepository): array {
        $siteChanges = [];
        $changes = $this->getChangesFromWorkspace($selectedWorkspace, $contentRepository);
        $contentGraph = $contentRepository->getContentGraph($selectedWorkspace->workspaceName);
        foreach ($changes as $change) {
            if ($change->originDimensionSpacePoint) {
                $subgraph = $contentGraph->getSubgraph(
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    VisibilityConstraints::createEmpty()
                );
                $node = $subgraph->findNodeById($change->nodeAggregateId);
            } else {
                // for changes like NodeAggregateNameWasChanged or NodeAggregateTypeWasChanged, get a random occupying node:
                $nodeAggregate = $contentGraph->findNodeAggregateById($change->nodeAggregateId);
                if ($nodeAggregate === null) {
                    continue;
                }
                $occupiedDimensionSpacePoints = $nodeAggregate->occupiedDimensionSpacePoints->getPoints();
                assert($occupiedDimensionSpacePoints !== []);
                $arbitraryDimensionSpacePoint = reset($occupiedDimensionSpacePoints);
                $node = $nodeAggregate->getNodeByOccupiedDimensionSpacePoint($arbitraryDimensionSpacePoint);
                $subgraph = $contentGraph->getSubgraph(
                    $arbitraryDimensionSpacePoint->toDimensionSpacePoint(),
                    VisibilityConstraints::createEmpty()
                );
            }
            if ($node) {
                $documentNode = null;
                $siteNode = null;
                $ancestors = $subgraph->findAncestorNodes(
                    $node->aggregateId,
                    FindAncestorNodesFilter::create()
                );
                $ancestors = Nodes::fromArray([$node])->merge($ancestors);

                $nodePathSegments = [];
                $documentPathSegments = [];
                $documentPathSegmentsNames = [];
                foreach ($ancestors as $ancestor) {
                    $pathSegment = $ancestor->name ?: NodeName::fromString($ancestor->aggregateId->value);
                    // Don't include `sites` path as they are not needed
                    // by the HTML/JS magic and won't be included as `$documentPathSegments`
                    if (!$this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_SITES)) {
                        $nodePathSegments[] = $pathSegment;
                    }
                    if ($this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_DOCUMENT)) {
                        $documentPathSegments[] = $pathSegment;
                        $documentPathSegmentsNames[] = $this->nodeLabelGenerator->getLabel($ancestor);
                        if (is_null($documentNode)) {
                            $documentNode = $ancestor;
                        }
                    }
                    if ($this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_SITE)) {
                        $siteNode = $ancestor;
                    }
                }

                // Neither $documentNode, $siteNode or its cannot really be null, this is just for type checks;
                // We should probably throw an exception though

                if ($documentNode !== null && $siteNode !== null && $siteNode->name) {
                    $siteNodeName = $siteNode->name->value;
                    // Reverse `$documentPathSegments` to start with the site node.
                    // The paths are used for grouping the nodes and for selecting a tree of nodes.
                    $documentPath = implode(
                        '/',
                        array_reverse(
                            array_map(
                                fn(NodeName $nodeName): string => $nodeName->value,
                                $documentPathSegments
                            )
                        )
                    );
                    // Reverse `$nodePathSegments` to start with the site node.
                    // The paths are used for grouping the nodes and for selecting a tree of nodes.
                    $relativePath = implode(
                        '/',
                        array_reverse(
                            array_map(
                                fn(NodeName $nodeName): string => $nodeName->value,
                                $nodePathSegments
                            )
                        )
                    );

                    if (!isset($siteChanges[$siteNodeName]['documents'][$documentPath]['document'])) {
                        $documentNodeAddress = NodeAddress::create(
                            $contentRepository->id,
                            $selectedWorkspace->workspaceName,
                            $documentNode->originDimensionSpacePoint->toDimensionSpacePoint(),
                            $documentNode->aggregateId
                        );
                        $documentType = $contentRepository->getNodeTypeManager()->getNodeType($documentNode->nodeTypeName);
                        $siteChanges[$siteNodeName]['documents'][$documentPath]['document'] = new DocumentItem(
                            documentBreadCrumb: array_reverse($documentPathSegmentsNames),
                            aggregateId: $documentNodeAddress->aggregateId->value,
                            documentNodeAddress: $documentNodeAddress->toJson(),
                            documentIcon: $documentType?->getFullConfiguration()['ui']['icon'] ?? null
                        );
                    }

                    if ($documentNode->equals($node)) {
                        $siteChanges[$siteNodeName]['documents'][$documentPath]['documentChanges'] = new DocumentChangeItem(
                            isRemoved: $change->deleted,
                            isNew: $change->created,
                            isMoved: $change->moved,
                            isHidden: $documentNode->tags->contain(NeosSubtreeTag::disabled()),
                        );
                    }

                    $nodeAddress = NodeAddress::fromNode($node);
                    $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
                    $dimensions = [];
                    foreach ($node->dimensionSpacePoint->coordinates as $id => $coordinate) {
                        $contentDimension = new ContentDimensionId($id);
                        $dimensions[] = $contentRepository->getContentDimensionSource()
                            ->getDimension($contentDimension)
                            ?->getValue($coordinate)
                            ?->configuration['label'] ?? $coordinate;
                    }
                    $siteChanges[$siteNodeName]['documents'][$documentPath]['changes'][$node->dimensionSpacePoint->hash][$relativePath] = new ChangeItem(
                        serializedNodeAddress: $nodeAddress->toJson(),
                        hidden: $node->tags->contain(NeosSubtreeTag::disabled()),
                        isRemoved: $change->deleted,
                        isNew: $change->created,
                        isMoved: $change->moved,
                        dimensions: $dimensions,
                        lastModificationDateTime: $node->timestamps->lastModified?->format('Y-m-d H:i'),
                        createdDateTime: $node->timestamps->created->format('Y-m-d H:i'),
                        label: $this->nodeLabelGenerator->getLabel($node),
                        icon: $nodeType?->getFullConfiguration()['ui']['icon'],
                        contentChanges: $this->renderContentChanges(
                            $node,
                            $change->contentStreamId,
                            $contentRepository
                        )
                    );
                }
            }

        }

        ksort($siteChanges);
        foreach ($siteChanges as $siteKey => $site) {
            foreach ($site['documents'] as $documentKey => $document) {
                ksort($siteChanges[$siteKey]['documents'][$documentKey]['changes']);
            }
            ksort($siteChanges[$siteKey]['documents']);
        }
        return $siteChanges;
    }

    public function computeDocumentChanges(Node $documentNode): array {
        $contentRepository = $this->contentRepositoryRegistry->get($documentNode->contentRepositoryId);
        $documentChanges = [];
        $workspace = $contentRepository->findWorkspaceByName($documentNode->workspaceName);
        $changes = $this->getChangesFromWorkspace($workspace, $contentRepository);
        $contentGraph = $contentRepository->getContentGraph($workspace->workspaceName);
        foreach ($changes as $change) {
            $changedNode = $contentGraph->getSubgraph($documentNode->dimensionSpacePoint, VisibilityConstraints::createEmpty())
                ->findNodeById($change->nodeAggregateId);

            $nodeAddress = NodeAddress::fromNode($changedNode);
            $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($changedNode->nodeTypeName);
            $dimensions = [];
            foreach ($changedNode->dimensionSpacePoint->coordinates as $id => $coordinate) {
                $contentDimension = new ContentDimensionId($id);
                $dimensions[] = $contentRepository->getContentDimensionSource()
                    ->getDimension($contentDimension)
                    ?->getValue($coordinate)
                    ?->configuration['label'] ?? $coordinate;
            }
            $documentChanges[$changedNode->aggregateId->value] = new ChangeItem(
                serializedNodeAddress: $nodeAddress->toJson(),
                hidden: $changedNode->tags->contain(NeosSubtreeTag::disabled()),
                isRemoved: $change->deleted,
                isNew: $change->created,
                isMoved: $change->moved,
                dimensions: $dimensions,
                lastModificationDateTime: $changedNode->timestamps->lastModified?->format('Y-m-d H:i'),
                createdDateTime: $changedNode->timestamps->created->format('Y-m-d H:i'),
                label: $this->nodeLabelGenerator->getLabel($changedNode),
                icon: $nodeType?->getFullConfiguration()['ui']['icon'],
                contentChanges: $this->renderContentChanges(
                    $changedNode,
                    $change->contentStreamId,
                    $contentRepository
                )
            );


        }

        ksort($documentChanges);

        return $documentChanges;
    }

    /**
     * Retrieves the given node's corresponding node in the base content stream
     * (that is, which would be overwritten if the given node would be published)
     */
    public function getOriginalNode(
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
     * Renders the difference between the original and the changed content of the given node and returns it, along
     * with meta information
     */
    public function renderContentChanges(
        Node              $changedNode,
        ContentStreamId   $contentStreamIdOfOriginalNode,
        ContentRepository $contentRepository,
    ): ContentChangeItems {
        $currentWorkspace = $contentRepository->findWorkspaces()->find(
            fn(Workspace $potentialWorkspace) => $potentialWorkspace->currentContentStreamId->equals($contentStreamIdOfOriginalNode)
        );
        $originalNode = null;
        if ($currentWorkspace !== null) {
            $baseWorkspace = $this->requireBaseWorkspace($currentWorkspace, $contentRepository);
            $originalNode = $this->getOriginalNode($changedNode, $baseWorkspace->workspaceName, $contentRepository);
        }

        $contentChanges = [];

        $changeNodePropertiesDefaults = $this->getNodeType($changedNode)->getDefaultValuesForProperties();

        $renderer = new HtmlArrayRenderer();

        $actualOriginalTags = $originalNode?->tags->withoutInherited()->all();
        $actualChangedTags = $changedNode->tags->withoutInherited()->all();

        if ($actualOriginalTags?->equals($actualChangedTags) === false) {
            $contentChanges['tags'] = new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'tags',
                    propertyLabel: $this->getModuleLabel('workspaces.changedTags'),
                ),
                changes: new TagContentChange(
                    addedTags: $actualChangedTags->difference($actualOriginalTags)->toStringArray(),
                    removedTags: $actualOriginalTags->difference($actualChangedTags)->toStringArray(),
                )
            );
        }
        foreach ($changedNode->properties as $propertyName => $changedPropertyValue) {
            if (
                ($originalNode === null && empty($changedPropertyValue))
                || (
                    isset($changeNodePropertiesDefaults[$propertyName])
                    && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName]
                )
            ) {
                continue;
            }

            $originalPropertyValue = ($originalNode?->getProperty($propertyName));

            if ($changedPropertyValue === $originalPropertyValue) {
                continue;
            }

            if (!is_object($originalPropertyValue) && !is_object($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($originalPropertyValue);
                $changedSlimmedDownContent = $this->renderSlimmedDownContent($changedPropertyValue);

                $diff = new Diff(
                    explode("\n", $originalSlimmedDownContent),
                    explode("\n", $changedSlimmedDownContent),
                    ['context' => 1]
                );
                $diffArray = $diff->render($renderer);
                $this->postProcessDiffArray($diffArray);

                if (count($diffArray) > 0) {

                    $contentChanges[$propertyName] = new ContentChangeItem(
                        properties: new ContentChangeProperties(
                            type: 'text',
                            propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                        ),
                        changes: new TextContentChange(
                            diff: $diffArray
                        )
                    );
                }
                // The && in belows condition is on purpose as creating a thumbnail for comparison only works
                // if actually BOTH are ImageInterface (or NULL).
            } elseif (
                ($originalPropertyValue instanceof ImageInterface || $originalPropertyValue === null)
                && ($changedPropertyValue instanceof ImageInterface || $changedPropertyValue === null)
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'text',
                        propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                    ),
                    changes: new ImageContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue
                    )
                );
            } elseif (
                $originalPropertyValue instanceof AssetInterface
                || $changedPropertyValue instanceof AssetInterface
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'text',
                        propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                    ),
                    changes: new AssetContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue
                    )
                );
            } elseif ($originalPropertyValue instanceof \DateTime || $changedPropertyValue instanceof \DateTime) {
                $changed = false;
                if (!$changedPropertyValue instanceof \DateTime || !$originalPropertyValue instanceof \DateTime) {
                    $changed = true;
                } elseif ($changedPropertyValue->getTimestamp() !== $originalPropertyValue->getTimestamp()) {
                    $changed = true;
                }
                if ($changed) {
                    $contentChanges[$propertyName] = new ContentChangeItem(
                        properties: new ContentChangeProperties(
                            type: 'text',
                            propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                        ),
                        changes: new DateTimeContentChange(
                            original: $originalPropertyValue,
                            changed: $changedPropertyValue
                        )
                    );
                }
            }
        }
        return ContentChangeItems::fromArray($contentChanges);
    }

    /**
     * Renders a slimmed down representation of a property of the given node. The output will be HTML, but does not
     * contain any markup from the original content.
     *
     * Note: It's clear that this method needs to be extracted and moved to a more universal service at some point.
     * However, since we only implemented diff-view support for this particular controller at the moment, it stays
     * here for the time being. Once we start displaying diffs elsewhere, we should refactor the diff rendering part.
     */
    public function renderSlimmedDownContent(mixed $propertyValue): string {
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
    public function getPropertyLabel(string $propertyName, Node $changedNode): string {
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
    public function postProcessDiffArray(array &$diffArray): void {
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

    public function getChangesFromWorkspace(Workspace $selectedWorkspace, ContentRepository $contentRepository): Changes {
        return $contentRepository->projectionState(ChangeFinder::class)
            ->findByContentStreamId(
                $selectedWorkspace->currentContentStreamId
            );
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

    public function getNodeType(Node $node) {
        $cr = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        return $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
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

}
