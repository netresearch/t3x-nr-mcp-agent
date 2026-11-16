-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- Two conversations of the admin user whose runs handed a step to a remote
-- agent (nr-llm ADR-281). Loaded by ../seed-and-test.sh for the `configured`
-- E2E variant, after nr-llm-task-seed.sql; agent-wait.spec.ts opens them by
-- their titles.
--
-- No model and no remote agent are involved: the state is stored the way a
-- run leaves it. `tx_nrllm_agentrun` holds one run still in
-- `waiting_for_dependency` and one that nr-llm's worker has continued to
-- `completed` without keeping an answer; each conversation points at its run
-- through `approval_run_uuid` and is `awaiting_dependency`. The waiting run
-- has no checkpoint: nr-llm's cancel moves it to `cancelled` all the same and
-- has no remote task to stop.
SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);
SET @waiting_run = 'e2e00000-0000-4000-8000-0000000000a1';
SET @finished_run = 'e2e00000-0000-4000-8000-0000000000a2';

INSERT INTO tx_nrllm_agentrun
    (pid, uuid, status, configuration_uid, configuration_identifier, be_user, iterations, suspended_state, queued_request, crdate, tstamp, started_at)
VALUES
    (0, @waiting_run, 'waiting_for_dependency', 1, 'e2e-chat', @admin_uid, 1, '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @finished_run, 'completed', 1, 'e2e-chat', @admin_uid, 2, '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E remote agent waits', '', 1, 'awaiting_dependency', '', '', '', '', '', '', '', @waiting_run, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
VALUES (0, LAST_INSERT_ID(), 0, 'user', JSON_OBJECT('role', 'user', 'content', 'Let the translation agent translate the teaser.'), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E remote agent answered', '', 1, 'awaiting_dependency', '', '', '', '', '', '', '', @finished_run, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
VALUES (0, LAST_INSERT_ID(), 0, 'user', JSON_OBJECT('role', 'user', 'content', 'Ask the translation agent for the footer text.'), UNIX_TIMESTAMP());
