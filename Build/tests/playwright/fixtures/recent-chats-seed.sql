-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- The "Recent chats" dashboard widget, for recent-chats-widget.spec.ts.
-- Loaded by ../seed-and-test.sh for the `configured` E2E variant, after
-- pages-seed.sql.
--
-- A page a conversation is about, and one that has been deleted since.
INSERT INTO pages (uid, pid, title, doktype, sorting, perms_userid, perms_user, perms_group, perms_everybody, deleted, crdate, tstamp)
VALUES
    (3, 1, 'E2E Recent chat page', 1, 512, 1, 31, 27, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (4, 1, 'E2E Deleted page', 1, 768, 1, 31, 27, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);

-- The admin's dashboard, holding the widget. With a dashboard of their own
-- the dashboard module creates none from the presets.
INSERT INTO be_dashboards (pid, identifier, cruser_id, title, widgets, crdate, tstamp)
VALUES (0, 'e2e-recent-chats', @admin_uid, 'E2E', '{"e2erecentchats":{"identifier":"nrMcpAgentAiChat"}}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- An interrupted guided process on page 3, at point 2 of 4, and a guided
-- process on the deleted page 4 (only a guided process is bound to its page). Their last message is dated far ahead, so they stay
-- among the five newest whatever the other specs add while they run.
INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, skill_identifier, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E recent tour', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '{"progress":{"label":"E2E","current":2,"total":4,"completed":false},"highlight":null}', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E recent deleted page', '', 2, 'idle', '', '{"pageId":4,"module":"web_layout"}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- Two chats with the same long title, started within one minute: the list
-- tells them apart by the second they were started, and a narrow widget must
-- cut the title short, not that time (recent-chats-widget.spec.ts). The
-- times are fixed epochs, 2026-10-29 11:27:01 and 11:27:44 UTC, so they do
-- not depend on the database's time zone.
INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, skill_identifier, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E recent twin with a title long enough to be cut short', '', 2, 'idle', '', '', '', '', '', '', '', '', '', '', 1793273221, 1793273221),
    (0, @admin_uid, 'E2E recent twin with a title long enough to be cut short', '', 2, 'idle', '', '', '', '', '', '', '', '', '', '', 1793273264, 1793273264);

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
SELECT 0, c.uid, t.n, IF(t.n = 0, 'user', 'assistant'),
       JSON_OBJECT('role', IF(t.n = 0, 'user', 'assistant'),
                   'content', IF(t.n = 0, 'Please look at this page.', 'Here is what I found.'),
                   'createdAt', '2099-01-01T10:00:00+00:00'),
       UNIX_TIMESTAMP()
FROM tx_nrmcpagent_conversation c
JOIN (SELECT 0 AS n UNION ALL SELECT 1) t
WHERE c.title LIKE 'E2E recent %' AND c.be_user = @admin_uid;

-- Six more guided processes on page 3, so the widget lists ten chats, eight
-- of them with a context line: more than its height holds (the list scrolls
-- in a region of its own, recent-chats-widget.spec.ts). Their last messages
-- were written at 09:51 to 09:56, before those of the four above, so those
-- stay at the top.
INSERT INTO tx_nrmcpagent_conversation
    (pid, be_user, title, messages, message_count, status, system_prompt, view_context, skill_identifier, activity, guided_state, approval_card, card_outcomes, error_message, approval_run_uuid, pending_input, tstamp, crdate)
VALUES
    (0, @admin_uid, 'E2E stacked tour 1', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E stacked tour 2', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E stacked tour 3', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E stacked tour 4', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E stacked tour 5', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (0, @admin_uid, 'E2E stacked tour 6', '', 2, 'idle', '', '{"pageId":3,"module":"web_layout","languageId":0}', 'e2e-process', '', '', '', '', '', '', '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tx_nrmcpagent_message (pid, conversation, sorting, role, payload, crdate)
SELECT 0, c.uid, t.n, IF(t.n = 0, 'user', 'assistant'),
       JSON_OBJECT('role', IF(t.n = 0, 'user', 'assistant'),
                   'content', IF(t.n = 0, 'Please look at this page.', 'Here is what I found.'),
                   'createdAt', CONCAT('2099-01-01T09:5', RIGHT(c.title, 1), ':00+00:00')),
       UNIX_TIMESTAMP()
FROM tx_nrmcpagent_conversation c
JOIN (SELECT 0 AS n UNION ALL SELECT 1) t
WHERE c.title LIKE 'E2E stacked tour %' AND c.be_user = @admin_uid;
