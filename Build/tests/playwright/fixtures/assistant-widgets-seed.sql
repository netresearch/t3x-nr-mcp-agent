-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH
--
-- The "KI-Assistent" dashboard (assistant-widgets.spec.ts): an administrator
-- of its own whose only dashboard holds the widgets of the preset, in its
-- order; a guided tour the chat can start, and the page it starts on first.
--
-- The tour is a process skill of a backend source, attached to the chat's
-- configuration on demand, under the slug the widgets look for
-- (`seo-optimieren`), so "Improve a page" offers it and the meta-description
-- tile starts it. Its body is a placeholder: no model is called, the run the
-- start dispatches fails against the unreachable provider. It declares no
-- tools, and its version is approved as an instruction (nr-llm ADR-214): an
-- attached skill that is not approved restricts every run of the
-- configuration to no tool at all, and the other specs need their tools. The
-- version digest is SkillVersionDigest's format 1, written as the demo's seed
-- writes it.
--
-- Page 9100 has no meta description and is the most recently changed page,
-- so the tile starts the tour on it.

INSERT INTO be_users (username, password, admin, tstamp, crdate)
SELECT 'e2e-widgets', password, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1;

SET @widgets_uid = (SELECT uid FROM be_users WHERE username = 'e2e-widgets' AND deleted = 0 LIMIT 1);

INSERT INTO be_dashboards (pid, identifier, cruser_id, title, widgets, crdate, tstamp)
VALUES (0, 'e2e-assistant', @widgets_uid, 'AI Assistant',
    '{"e2eprompt":{"identifier":"nrMcpAgentPrompt"},"e2eeditpage":{"identifier":"nrMcpAgentEditPage"},"e2ecreate":{"identifier":"nrMcpAgentCreateContent"},"e2echats":{"identifier":"nrMcpAgentAiChat"},"e2erecommendations":{"identifier":"nrMcpAgentRecommendations"},"e2eimprove":{"identifier":"nrMcpAgentImprovePage"},"e2echeck":{"identifier":"nrMcpAgentWebsiteCheck"}}',
    UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO pages (uid, pid, title, doktype, sorting, perms_userid, perms_user, perms_group, perms_everybody, description, crdate, tstamp)
VALUES (9100, 1, 'E2E Without description', 1, 9100, 1, 31, 27, 0, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + 100000);

INSERT INTO tx_nrllm_skill_source
    (uid, pid, title, type, url, trust_level, sync_status, enabled, tstamp, crdate, deleted, hidden)
VALUES (9100, 0, 'E2E tours', 'backend', '', 'first_party', 'synced', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0);

INSERT INTO tx_nrllm_skill
    (uid, pid, source, identifier, name, description, body, body_checksum, process, page,
     support_status, allowed_tools, trust_level, enabled, orphaned, tstamp, crdate, deleted, hidden)
VALUES (9100, 0, 9100, '9100:seo-optimieren', 'seo-optimieren', 'E2E guided tour',
    'E2E guided tour: check the page and report.', 'cb02174d3294fcf1aa5fd239a562a500db2c085d317e4b58fd2d8dfa6d4c3faf', 1, 'required',
    'full', NULL, 'first_party', 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0);

UPDATE tx_nrllm_skill
   SET version_digest = CONCAT('1:', SHA2(CONCAT(
       'nr_llm.skill-version', CHAR(10),
       'format=1', CHAR(10),
       'name=', LENGTH(name), ':', name, CHAR(10),
       'description=', LENGTH(COALESCE(description, '')), ':', COALESCE(description, ''), CHAR(10),
       'body=', LENGTH(COALESCE(body, '')), ':', COALESCE(body, ''), CHAR(10),
       'support_status=', LENGTH(support_status), ':', support_status, CHAR(10),
       'allowed_tools=-', CHAR(10),
       'process=1', CHAR(10)), 256))
 WHERE uid = 9100;

INSERT INTO tx_nrllm_skill_approval
    (pid, crdate, skill_uid, source_uid, version_digest, name, description, body, support_status, allowed_tools, process, trust_level, approved_by, revoked)
SELECT 0, UNIX_TIMESTAMP(), uid, source, version_digest, name, description, body, support_status, NULL, 1, trust_level,
       (SELECT uid FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1), 0
  FROM tx_nrllm_skill WHERE uid = 9100;

INSERT INTO tx_nrllm_configuration_skill_mm (uid_local, uid_foreign, sorting, sorting_foreign, load_mode)
VALUES (1, 9100, 1, 1, 'on_demand');

UPDATE tx_nrllm_configuration SET skills_on_demand = skills_on_demand + 1 WHERE uid = 1;

-- "Create content" offers a kind only where its tool is enabled; nr-llm 0.41
-- turns no writing tool on by default. Pages exist everywhere, news only with
-- EXT:news, which this installation does not have.
INSERT INTO tx_nrllm_tool_state (pid, tool_name, enabled)
VALUES (0, 'create_page_draft', 1)
ON DUPLICATE KEY UPDATE enabled = 1;
