-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- The "AI Assistant" prompt widget on the admin's dashboard, for
-- prompt-widget.spec.ts. Loaded by ../seed-and-test.sh for the `configured`
-- E2E variant, after recent-chats-seed.sql.
--
-- The widget joins the dashboard recent-chats-seed.sql made (created here
-- when that seed did not run), first, as in the "AI Assistant" preset, and
-- that dashboard becomes the admin's only one: the dashboard shows the one
-- the user last opened, falls back to the first when that one is gone, and
-- creates the default dashboards only for a user who has none. An earlier
-- run on the same installation may have created those; removing them keeps
-- both widget specs independent of which dashboard was open last, in 13.4
-- and 14.3.
SET @admin_uid = (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1);

DELETE FROM be_dashboards WHERE cruser_id = @admin_uid AND identifier <> 'e2e-recent-chats';

INSERT INTO be_dashboards (pid, identifier, cruser_id, title, widgets, crdate, tstamp)
SELECT 0, 'e2e-recent-chats', @admin_uid, 'E2E', '{}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM be_dashboards WHERE identifier = 'e2e-recent-chats' AND cruser_id = @admin_uid);

UPDATE be_dashboards
SET widgets = '{"e2eprompt":{"identifier":"nrMcpAgentPrompt"},"e2erecentchats":{"identifier":"nrMcpAgentAiChat"}}'
WHERE identifier = 'e2e-recent-chats' AND cruser_id = @admin_uid;
