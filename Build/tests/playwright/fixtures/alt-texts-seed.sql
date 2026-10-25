-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- The list "Fehlende Alt-Texte" and its two dashboard entries, for
-- missing-alt-texts.spec.ts. Loaded by ../seed-and-test.sh for the
-- `configured` E2E variant, after prompt-widget-seed.sql.
--
-- Three images without an alt text, on a page of their own ("E2E Alt
-- texts", below "E2E Home"): two in content elements, one of them hidden, and
-- one in a file collection. The files are not on disk and are marked missing,
-- so neither the list nor the page module requests them.
INSERT INTO pages (uid, pid, title, doktype, sorting, perms_userid, perms_user, perms_group, perms_everybody, crdate, tstamp)
VALUES (9001, 1, 'E2E Alt texts', 1, 4096, 1, 31, 27, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO sys_file (uid, pid, storage, type, identifier, identifier_hash, folder_hash, name, extension, mime_type, size, missing, tstamp, creation_date, modification_date)
VALUES
    (9001, 0, 1, 2, '/e2e/e2e-team.jpg', SHA1('/e2e/e2e-team.jpg'), SHA1('/e2e/'), 'e2e-team.jpg', 'jpg', 'image/jpeg', 100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (9002, 0, 1, 2, '/e2e/e2e-pattern.jpg', SHA1('/e2e/e2e-pattern.jpg'), SHA1('/e2e/'), 'e2e-pattern.jpg', 'jpg', 'image/jpeg', 100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO tt_content (uid, pid, CType, header, colPos, sorting, hidden, image, tstamp, crdate)
VALUES
    (9001, 9001, 'textpic', 'E2E Team', 0, 256, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (9002, 9001, 'image', 'E2E Hidden', 0, 512, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO sys_file_collection (uid, pid, title, type, files, tstamp, crdate)
VALUES (9001, 9001, 'E2E Collection', 'static', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO sys_file_reference (uid, pid, uid_local, uid_foreign, tablenames, fieldname, sorting_foreign, tstamp, crdate)
VALUES
    (9001, 9001, 9001, 9001, 'tt_content', 'image', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (9002, 9001, 9002, 9002, 'tt_content', 'image', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (9003, 9001, 9001, 9001, 'sys_file_collection', 'files', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- An administrator of their own with one dashboard holding "Website prüfen"
-- and "Für deine Website empfohlen". The admin's dashboard, which the other
-- dashboard specs drive, stays as it is. The password is the admin's, as
-- `typo3 setup` hashed it.
INSERT INTO be_users (username, password, admin, tstamp, crdate)
SELECT 'e2e-alttexts', password, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM be_users WHERE username = 'admin' AND deleted = 0 LIMIT 1;

SET @alttexts_uid = (SELECT uid FROM be_users WHERE username = 'e2e-alttexts' AND deleted = 0 LIMIT 1);

INSERT INTO be_dashboards (pid, identifier, cruser_id, title, widgets, crdate, tstamp)
VALUES (0, 'e2e-alt-texts', @alttexts_uid, 'E2E Alt texts', '{"e2erecommendations":{"identifier":"nrMcpAgentRecommendations"},"e2ewebsitecheck":{"identifier":"nrMcpAgentWebsiteCheck"}}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
