CREATE TABLE tx_nrmcpagent_conversation (
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    messages mediumtext,
    message_count int(11) unsigned DEFAULT 0 NOT NULL,
    status varchar(20) DEFAULT 'idle' NOT NULL,
    current_request_id varchar(64) DEFAULT '' NOT NULL,
    system_prompt text,
    archived tinyint(1) unsigned DEFAULT 0 NOT NULL,
    pinned tinyint(1) unsigned DEFAULT 0 NOT NULL,
    error_message text,

    KEY be_user_archived (be_user, archived, tstamp)
);
