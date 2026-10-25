-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- Conversations of the admin user that wait for an approval, for
-- editor-journey.spec.ts. Loaded by ../seed-and-test.sh for the `configured`
-- E2E variant, after nr-llm-task-seed.sql and pages-seed.sql. Stored the way
-- a paused run leaves them, like approval-card-seed.sql.
--
-- nr-llm 0.41 turns no writing tool on by default; an administrator enables
-- one in its Tools module, which writes the override row below. Without it a
-- run could never have proposed this call, and an approval of it is refused.
-- The chat shows that refusal as handBack.toolDisabled: nr-llm 0.41.5
-- exposes the reason (getDenialReason()), and its log message names
-- "update_page_metadata (toolDisabled)".
INSERT INTO tx_nrllm_tool_state (pid, tool_name, enabled)
VALUES (0, 'update_page_metadata', 1)
ON DUPLICATE KEY UPDATE enabled = 1;

SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);

-- One call renaming page 2 of pages-seed.sql, so an applied write leaves
-- page 1, which other specs select by its title, untouched.
--
-- The preview lines are exactly what nr-llm 0.41's update_page_metadata
-- writes for an English-speaking admin: an approval binds to them, and on
-- resume nr-llm compares them with a fresh preview byte for byte (its
-- ADR-184). Any other text makes the approval bounce with "This record
-- changed after the preview you approved" and nothing is written.
SET @rename = '{"messages":[{"role":"user","content":"Rename page 2 to \\"E2E Subpage renamed\\"."},{"role":"assistant","content":"","tool_calls":[{"id":"call_e2e_journey","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":2,\\"title\\":\\"E2E Subpage renamed\\"}"}}]}],"pendingCalls":[{"id":"call_e2e_journey","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":2,\\"title\\":\\"E2E Subpage renamed\\"}"}}],"callPreviews":[{"index":0,"tool":"update_page_metadata","lines":["Change page metadata","Page: \\u201cE2E Subpage\\u201d","Language: default language","Page title: currently \\u201cE2E Subpage\\u201d, proposed \\u201cE2E Subpage renamed\\u201d","Technical details: page UID 2, language UID 0, fields title"],"failed":false}],"iterations":1,"promptTokens":0,"completionTokens":0}';

-- One call of a writing tool that stays disabled: what a run waiting on a
-- tool an administrator switched off meanwhile looks like.
SET @disabled = '{"messages":[{"role":"user","content":"Set a social image for page 2."},{"role":"assistant","content":"","tool_calls":[{"id":"call_e2e_disabled","type":"function","function":{"name":"set_page_social_image","arguments":"{\\"uid\\":2,\\"field\\":\\"og_image\\",\\"fileUid\\":1}"}}]}],"pendingCalls":[{"id":"call_e2e_disabled","type":"function","function":{"name":"set_page_social_image","arguments":"{\\"uid\\":2,\\"field\\":\\"og_image\\",\\"fileUid\\":1}"}}],"callPreviews":[{"index":0,"tool":"set_page_social_image","lines":["Page \\"E2E Subpage\\" (uid 2)","Open Graph image: none -> file 1"],"failed":false}],"iterations":1,"promptTokens":0,"completionTokens":0}';

INSERT INTO tx_nrllm_agentrun
    (pid, uuid, status, configuration_uid, configuration_identifier, be_user, iterations, suspended_state, queued_request, crdate, tstamp, started_at)
VALUES
    (0, 'e2e00000-0000-4000-8000-000000000002', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @rename, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, 'e2e00000-0000-4000-8000-000000000003', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @rename, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, 'e2e00000-0000-4000-8000-000000000004', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @rename, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, 'e2e00000-0000-4000-8000-000000000005', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @disabled, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- "keep" has a skill: a conversation that runs a skill is a process run, and
-- there a write is proposed with "Another variant", "Keep" and "Skip"
-- (ADR-023). A skill unknown to the catalogue counts as a process skill.
INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, skill_identifier, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E journey approve', '', 2, 'awaiting_approval', '', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000002', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E journey cancel', '', 2, 'awaiting_approval', '', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000003', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E journey keep', '', 2, 'awaiting_approval', '', '', 'e2e-process', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000004', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E journey disabled tool', '', 2, 'awaiting_approval', '', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000005', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
SELECT 0, c.uid, t.n, IF(t.n = 0, 'user', 'assistant'),
       JSON_OBJECT('role', IF(t.n = 0, 'user', 'assistant'),
                   'content', IF(t.n = 0, 'Please change page 2.', 'I prepared the change; it needs your approval.')),
       UNIX_TIMESTAMP()
FROM tx_nrmcpagent_conversation c
JOIN (SELECT 0 AS n UNION ALL SELECT 1) t
WHERE c.title LIKE 'E2E journey %' AND c.be_user = @admin_uid;
