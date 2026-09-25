-- The smallest nr-llm chain the chat panel runs on: Provider -> Model ->
-- Configuration -> Task. Loaded by ../seed-and-test.sh for the `configured`
-- E2E variant, which then points `llmTaskUid` at Task 1.
--
-- The rows are taken from nr-llm's own functional fixtures (Providers.csv,
-- Models.csv, LlmConfigurations.csv, Tasks.csv). The provider uses the OpenAI
-- adapter because it reports vision support, which the file-upload specs need
-- to reach the attachment menu at all. It carries no API key and points at a
-- closed local port, so no request leaves the runner; no spec waits for a
-- model answer.
INSERT INTO tx_nrllm_provider
    (uid, pid, identifier, name, description, adapter_type, endpoint_url, api_key, organization_id, api_timeout, max_retries, options, is_active, sorting, deleted, hidden)
VALUES
    (1, 0, 'e2e-openai', 'E2E OpenAI', 'Placeholder provider for the Playwright suite; never reachable', 'openai', 'http://127.0.0.1:9/v1', '', '', 5, 0, '', 1, 1, 0, 0);

INSERT INTO tx_nrllm_model
    (uid, pid, identifier, name, description, provider_uid, model_id, context_length, max_output_tokens, capabilities, default_timeout, cost_input, cost_output, is_active, is_default, sorting, deleted, hidden)
VALUES
    (1, 0, 'e2e-model', 'E2E Model', 'Placeholder model for the Playwright suite', 1, 'e2e-model', 8192, 1024, 'chat,vision,tools', 5, 0, 0, 1, 1, 1, 0, 0);

INSERT INTO tx_nrllm_configuration
    (uid, pid, identifier, name, description, model_uid, translator, system_prompt, temperature, max_tokens, top_p, frequency_penalty, presence_penalty, options, max_requests_per_day, max_tokens_per_day, max_cost_per_day, is_active, is_default, allowed_groups, tstamp, crdate, deleted, hidden, sorting)
VALUES
    (1, 0, 'e2e-chat', 'E2E Chat', 'Configuration the E2E chat Task runs on', 1, '', 'You are a helpful assistant.', 0.70, 1000, 1.00, 0.00, 0.00, '{}', 0, 0, 0.00, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0, 1);

INSERT INTO tx_nrllm_task
    (uid, pid, identifier, name, description, category, configuration_uid, prompt_template, input_type, input_source, output_format, is_active, is_system, sorting, tstamp, crdate, deleted, hidden)
VALUES
    (1, 0, 'e2e-chat-task', 'E2E Chat Task', 'Task the AI Chat runs on in the Playwright suite', 'general', 1, '{{input}}', 'manual', '', 'markdown', 1, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0);
