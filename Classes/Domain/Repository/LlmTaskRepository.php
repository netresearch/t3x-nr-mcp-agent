<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

/**
 * Resolves LLM model configuration by following the Task → Configuration → Model chain.
 *
 * Queries across nr-llm tables in a single join to avoid N+1 queries.
 */
class LlmTaskRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly DataMapper $dataMapper,
    ) {}

    /**
     * @return array{model: LlmModel, systemPrompt: string, promptTemplate: string}
     */
    public function resolveModelByTaskUid(int $taskUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_model');
        $row = $qb
            ->select('m.*', 'c.system_prompt AS _config_system_prompt', 't.prompt_template AS _task_prompt_template')
            ->from('tx_nrllm_task', 't')
            ->join('t', 'tx_nrllm_configuration', 'c', $qb->expr()->eq('c.uid', $qb->quoteIdentifier('t.configuration_uid')))
            ->join('c', 'tx_nrllm_model', 'm', $qb->expr()->eq('m.uid', $qb->quoteIdentifier('c.model_uid')))
            ->where($qb->expr()->eq('t.uid', $qb->createNamedParameter($taskUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new RuntimeException(sprintf('Could not resolve LLM model for task uid %d', $taskUid));
        }

        $systemPrompt = is_string($row['_config_system_prompt'] ?? null) ? $row['_config_system_prompt'] : '';
        $promptTemplate = is_string($row['_task_prompt_template'] ?? null) ? $row['_task_prompt_template'] : '';
        unset($row['_config_system_prompt'], $row['_task_prompt_template']);

        /** @var list<LlmModel> $models */
        $models = $this->dataMapper->map(LlmModel::class, [$row]);
        $model = $models[0] ?? null;

        if ($model === null) {
            throw new RuntimeException(sprintf('Could not map LLM model for task uid %d', $taskUid));
        }

        return [
            'model' => $model,
            'systemPrompt' => $systemPrompt,
            'promptTemplate' => $promptTemplate,
        ];
    }
}
