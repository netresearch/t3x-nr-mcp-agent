-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- Two conversations of the admin user that wait for an approval, for
-- editor-journey.spec.ts: one is approved, one is cancelled. Loaded by
-- ../seed-and-test.sh for the `configured` E2E variant, after
-- nr-llm-task-seed.sql and pages-seed.sql. Stored the way a paused run
-- leaves them, like approval-card-seed.sql; the pending call renames page 2
-- of pages-seed.sql, so an approved write leaves page 1, which other specs
-- select by its title, untouched.
SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);
SET @state = '{"messages":[{"role":"user","content":"Rename page 2 to \\"E2E Subpage renamed\\"."},{"role":"assistant","content":"","tool_calls":[{"id":"call_e2e_journey","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":2,\\"title\\":\\"E2E Subpage renamed\\"}"}}]}],"pendingCalls":[{"id":"call_e2e_journey","type":"function","function":{"name":"update_page_metadata","arguments":"{\\"uid\\":2,\\"title\\":\\"E2E Subpage renamed\\"}"}}],"callPreviews":[{"index":0,"tool":"update_page_metadata","lines":["Page \\"E2E Subpage\\" (uid 2)","Title: \\"E2E Subpage\\" -> \\"E2E Subpage renamed\\""],"failed":false}],"iterations":1,"promptTokens":0,"completionTokens":0}';

INSERT INTO tx_nrllm_agentrun
    (pid, uuid, status, configuration_uid, configuration_identifier, be_user, iterations, suspended_state, queued_request, crdate, tstamp, started_at)
VALUES
    (0, 'e2e00000-0000-4000-8000-000000000002', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @state, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, 'e2e00000-0000-4000-8000-000000000003', 'waiting_for_approval', 1, 'e2e-chat', @admin_uid, 1, @state, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E journey approve', '', 2, 'awaiting_approval', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000002', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SET @approve_uid = LAST_INSERT_ID();

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E journey cancel', '', 2, 'awaiting_approval', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000003', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SET @cancel_uid = LAST_INSERT_ID();

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
VALUES
    (0, @approve_uid, 0, 'user', JSON_OBJECT('role', 'user', 'content', 'Rename page 2 to "E2E Subpage renamed".'), UNIX_TIMESTAMP()),
    (0, @approve_uid, 1, 'assistant', JSON_OBJECT('role', 'assistant', 'content', 'I prepared the change; it needs your approval.'), UNIX_TIMESTAMP()),
    (0, @cancel_uid, 0, 'user', JSON_OBJECT('role', 'user', 'content', 'Rename page 2 to "E2E Subpage renamed".'), UNIX_TIMESTAMP()),
    (0, @cancel_uid, 1, 'assistant', JSON_OBJECT('role', 'assistant', 'content', 'I prepared the change; it needs your approval.'), UNIX_TIMESTAMP());
