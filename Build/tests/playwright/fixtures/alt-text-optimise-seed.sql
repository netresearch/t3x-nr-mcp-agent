-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- "Optimieren" in the chat (ADR-026), for alt-text-optimise.spec.ts. Loaded by
-- ../seed-and-test.sh for the `configured` E2E variant, after
-- alt-texts-seed.sql, whose images and user `e2e-alttexts` it uses.
--
-- Both tools of the flow are enabled, as an administrator enables them in
-- nr-llm's Tools module; without them the list says why "Optimieren" is not
-- available and the server refuses the start.
INSERT INTO tx_nrllm_tool_state (pid, tool_name, enabled)
VALUES (0, 'set_file_reference_alternative_text', 1), (0, 'set_file_reference_decorative', 1)
ON DUPLICATE KEY UPDATE enabled = 1;

SET @alttexts_uid = (SELECT uid FROM be_users WHERE username = 'e2e-alttexts' AND deleted = 0 LIMIT 1);

-- A conversation about the image of "E2E Hidden" (reference 9002) whose run
-- waits on a proposed alt text, as a run that proposed one leaves it. The
-- spec answers with the chat's own actions only ("Als dekorativ markieren"
-- up to the warning, "Doch Alt-Text verwenden", "Überspringen"), which write
-- nothing, so missing-alt-texts.spec.ts still finds three images. The preview
-- lines are therefore not bound by an approval here.
SET @proposal = '{"messages":[{"role":"user","content":"<image_context>\\nreference 9002\\n</image_context>"},{"role":"assistant","content":"","tool_calls":[{"id":"call_e2e_alt","type":"function","function":{"name":"set_file_reference_alternative_text","arguments":"{\\"reference\\":9002,\\"alternative\\":\\"Two people at a desk\\"}"}}]}],"pendingCalls":[{"id":"call_e2e_alt","type":"function","function":{"name":"set_file_reference_alternative_text","arguments":"{\\"reference\\":9002,\\"alternative\\":\\"Two people at a desk\\"}"}}],"callPreviews":[{"index":0,"tool":"set_file_reference_alternative_text","lines":["Set the alternative text","Image: e2e-pattern.jpg","Alternative text: currently empty, proposed \\u201cTwo people at a desk\\u201d"],"failed":false}],"iterations":1,"promptTokens":0,"completionTokens":0}';

INSERT INTO tx_nrllm_agentrun
    (pid, uuid, status, configuration_uid, configuration_identifier, be_user, iterations, suspended_state, queued_request, crdate, tstamp, started_at)
VALUES
    (0, 'e2e00000-0000-4000-8000-000000000a17', 'waiting_for_approval', 1, 'e2e-chat', @alttexts_uid, 1, @proposal, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, skill_identifier, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, alt_text_hit, tstamp, crdate)
VALUES
    (0, @alttexts_uid, 'E2E alt text proposal', '', 1, 'awaiting_approval', '', '', '', '', '', '', '', '', 'e2e00000-0000-4000-8000-000000000a17', '', '{"reference":9002}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- The image card the chat writes first: the notice and the card's lines.
INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
SELECT 0, c.uid, 0, 'assistant',
       JSON_OBJECT('role', 'assistant', 'content', '[Image card: reference 9002, file "e2e-pattern.jpg".]', 'notice', 'altTextHit',
                   'data', JSON_OBJECT('fileName', 'e2e-pattern.jpg', 'subjectType', '', 'subject', 'E2E Alt texts', 'partType', 'element',
                                       'part', 'Image – “E2E Hidden”', 'language', '', 'section', 'E2E Hidden', 'reference', 9002, 'imageNote', 'noVision')),
       UNIX_TIMESTAMP()
FROM tx_nrmcpagent_conversation c
WHERE c.title = 'E2E alt text proposal' AND c.be_user = @alttexts_uid;
