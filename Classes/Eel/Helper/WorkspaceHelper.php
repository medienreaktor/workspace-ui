<?php

namespace Neos\Workspace\Ui\Eel\Helper;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations\Inject;
use Neos\Workspace\Ui\Service\DifferencesService;

class WorkspaceHelper implements ProtectedContextAwareInterface {


    #[Inject]
    protected DifferencesService $differencesService;

    public function getDocumentDifferences(Node $documentNode) {


        return ($this->differencesService->computeDocumentChanges($documentNode));
    }

    public function allowsCallOfMethod($methodName) {
        return true;
    }
}
