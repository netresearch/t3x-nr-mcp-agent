-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

-- Two pages for the page tree: a fresh installation has none, and
-- chat-column.spec.ts selects a page in the tree and checks that the chat
-- follows it. Loaded by ../seed-and-test.sh for the `configured` E2E variant.
INSERT INTO pages (uid, pid, title, doktype, is_siteroot, sorting, perms_userid, perms_user, perms_group, perms_everybody, crdate, tstamp)
VALUES
    (1, 0, 'E2E Home', 1, 1, 256, 1, 31, 27, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (2, 1, 'E2E Subpage', 1, 0, 256, 1, 31, 27, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
