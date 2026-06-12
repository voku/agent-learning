<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Handles the flow of importing consolidation results as candidate proposals.
 */
final class ProposalImporter
{
    public function __construct(
        private readonly ConsolidationResultParser $parser = new ConsolidationResultParser(),
        private readonly ConsolidationResultValidator $validator = new ConsolidationResultValidator(),
        private readonly ProposalFromConsolidationResult $mapper = new ProposalFromConsolidationResult(),
        private readonly ProposalIdGenerator $idGenerator = new ProposalIdGenerator(),
        private readonly ProposalValidator $proposalValidator = new ProposalValidator(),
        private readonly FindingLifecycle $findingLifecycle = new FindingLifecycle(),
        private readonly PathResolver $pathResolver = new PathResolver(),
    ) {
    }

    /**
     * Import a consolidation result file.
     *
     * @param string $root
     * @param string $resultJsonPath
     * @return string Generated proposal ID
     * @throws ValidationException
     */
    public function import(string $root, string $resultJsonPath): string
    {
        $root = $this->pathResolver->resolve($root);
        if (!is_file($resultJsonPath)) {
            throw new ValidationException($resultJsonPath, null, null, 'consolidation result file not found');
        }

        $content = file_get_contents($resultJsonPath);
        if ($content === false) {
            throw new ValidationException($resultJsonPath, null, null, 'cannot read consolidation result file');
        }

        // 1. Parse
        $data = $this->parser->parse($content);

        // Load findings
        $findingsById = [];
        $findingValidator = new FindingValidator();
        foreach ($this->findingLifecycle->findingFiles($root) as $file) {
            $finding = $findingValidator->validateFile($file);
            $this->findingLifecycle->assertPathMatchesStatus($finding, $file, $root);
            $findingsById[$finding->id] = $finding;
        }

        // 2. Validate against findings
        $result = $this->validator->validate($data, $findingsById);

        // 3. Generate proposal ID
        $proposalId = $this->idGenerator->generate($root);

        // 4. Map to proposal
        $proposal = $this->mapper->map($result, $proposalId);

        // 5. Write temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'prop_import_');
        if ($tempFile === false) {
            throw new ValidationException('', null, null, 'cannot create temp file');
        }

        try {
            $encoded = json_encode($proposal->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tempFile, $encoded) === false) {
                throw new ValidationException('', null, null, 'cannot write temp file');
            }

            // 6. Validate candidate proposal file
            $this->proposalValidator->validate($proposal, $tempFile, null, $findingsById);

            // 7. Rename to candidate destination
            $candidateDir = $root . '/proposals/candidate';
            if (!is_dir($candidateDir)) {
                if (!mkdir($candidateDir, 0777, true) && !is_dir($candidateDir)) {
                    throw new ValidationException($candidateDir, null, null, 'cannot create proposals/candidate directory');
                }
            }

            $targetPath = $candidateDir . '/' . $proposalId . '.json';
            if (is_file($targetPath)) {
                throw new ValidationException($targetPath, null, $proposalId, 'duplicate proposal ID');
            }

            if (!rename($tempFile, $targetPath)) {
                throw new ValidationException($targetPath, null, $proposalId, 'cannot rename temp file to target proposal file');
            }
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        return $proposalId;
    }
}
