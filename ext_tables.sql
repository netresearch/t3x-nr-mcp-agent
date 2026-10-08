-- SPDX-License-Identifier: GPL-2.0-or-later
-- SPDX-FileCopyrightText: Netresearch DTT GmbH

CREATE TABLE tx_nrmcpagent_conversation (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    deleted smallint(5) unsigned DEFAULT 0 NOT NULL,
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    messages mediumtext,
    message_count int(11) unsigned DEFAULT 0 NOT NULL,
    status varchar(20) DEFAULT 'idle' NOT NULL,
    current_request_id varchar(64) DEFAULT '' NOT NULL,
    system_prompt text,
    view_context varchar(255) DEFAULT '' NOT NULL,
    activity text,
    guided_state text,
    archived tinyint(1) unsigned DEFAULT 0 NOT NULL,
    pinned tinyint(1) unsigned DEFAULT 0 NOT NULL,
    error_message text,
    error_code varchar(32) DEFAULT '' NOT NULL,
    approval_run_uuid varchar(64) DEFAULT '' NOT NULL,
    approval_decision varchar(8) DEFAULT '' NOT NULL,
    approval_turn_digest varchar(64) DEFAULT '' NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY be_user_archived (be_user, archived, tstamp),
    KEY status_deleted_tstamp (status, deleted, tstamp),
    KEY be_user_status (be_user, status, deleted),
    KEY current_request_id (current_request_id, status)
);

CREATE TABLE tx_nrmcpagent_message (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    conversation int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    role varchar(20) DEFAULT '' NOT NULL,
    payload mediumtext,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY conversation_sorting (conversation, sorting)
);

CREATE TABLE tx_nrmcpagent_run_state (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    run_uuid varchar(64) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    progress text,
    highlight text,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY run_uuid (run_uuid)
);

CREATE TABLE tx_nrmcpagent_open_point (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    page_uid int(11) unsigned DEFAULT 0 NOT NULL,
    language_uid int(11) DEFAULT 0 NOT NULL,
    skill_identifier varchar(100) DEFAULT '' NOT NULL,
    point_key varchar(100) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    details text,
    status varchar(16) DEFAULT 'open' NOT NULL,
    run_uuid varchar(64) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY point (page_uid, language_uid, skill_identifier, point_key)
);
