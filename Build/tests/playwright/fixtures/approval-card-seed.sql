-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- One conversation of the admin user that waits for an approval, with a
-- transcript long enough to overflow the message column. Loaded by
-- ../seed-and-test.sh for the `configured` E2E variant, after
-- nr-llm-task-seed.sql; approval-card-height.spec.ts opens it by its title.
--
-- No model is involved: the pause is stored the way a run leaves it.
-- `tx_nrllm_agentrun` holds the run in `waiting_for_approval` with its
-- suspended state, and the conversation points at it through
-- `approval_run_uuid`. nr-llm reads a suspended state without its encryption
-- marker as cleartext (AgentStateCodec::decode()), so the JSON is stored as
-- it is. The pending call is nr-llm's own `update_page_metadata` write tool,
-- with the preview lines a real run stores beside it.
--
-- Run, conversation and messages belong to the user `admin`, which the
-- reusable workflow's `typo3 setup` creates. The run must be the viewer's own:
-- only then does nr-llm show the stored preview lines on the card.
SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);
SET @run_uuid = 'e2e00000-0000-4000-8000-000000000001';
SET @messages = 24;

INSERT INTO tx_nrllm_agentrun
    (pid, uuid, status, configuration_uid, configuration_identifier, be_user, iterations, suspended_state, queued_request, crdate, tstamp, started_at)
VALUES
    (0, @run_uuid, 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1,
     '{"messages":[{"role":"user","content":"Set the title of page 1 to \\"Welcome\\"."},{"role":"assistant","content":"","tool_calls":[{"id":"call_e2e_1","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":1,\\"title\\":\\"Welcome\\"}"}}]}],"pendingCalls":[{"id":"call_e2e_1","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":1,\\"title\\":\\"Welcome\\"}"}}],"callPreviews":[{"index":0,"tool":"update_page_metadata","lines":["Page \\"Home\\" (uid 1)","Title: \\"Home\\" -> \\"Welcome\\""],"failed":false}],"iterations":1,"promptTokens":0,"completionTokens":0}',
     '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E approval card', '', @messages, 'awaiting_approval', '', '', '', '', '', '', '', @run_uuid, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

SET @conversation_uid = LAST_INSERT_ID();

-- Alternating user and assistant turns, each a few lines long.
INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
WITH RECURSIVE turn (n) AS (
    SELECT 0
    UNION ALL
    SELECT n + 1 FROM turn WHERE n + 1 < @messages
)
SELECT
    0,
    @conversation_uid,
    n,
    IF(n % 2 = 0, 'user', 'assistant'),
    JSON_OBJECT(
        'role', IF(n % 2 = 0, 'user', 'assistant'),
        'content', CONCAT(
            'Turn ', n + 1, ' of the E2E transcript. ',
            REPEAT('This sentence is here so that the message takes several lines in the chat. ', 4)
        )
    ),
    UNIX_TIMESTAMP()
FROM turn;
