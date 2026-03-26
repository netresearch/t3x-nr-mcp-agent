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
    archived tinyint(1) unsigned DEFAULT 0 NOT NULL,
    pinned tinyint(1) unsigned DEFAULT 0 NOT NULL,
    error_message text,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY be_user_archived (be_user, archived, tstamp),
    KEY status_deleted_tstamp (status, deleted, tstamp),
    KEY be_user_status (be_user, status, deleted),
    KEY current_request_id (current_request_id, status)
);

CREATE TABLE tx_nrmcpagent_mcp_server (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    deleted smallint(5) unsigned DEFAULT 0 NOT NULL,
    hidden smallint(5) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) DEFAULT 0 NOT NULL,

    name varchar(255) DEFAULT '' NOT NULL,
    server_key varchar(64) DEFAULT '' NOT NULL,
    transport varchar(10) DEFAULT 'stdio' NOT NULL,

    -- stdio fields
    command varchar(1000) DEFAULT '' NOT NULL,
    arguments text,

    -- sse fields
    url varchar(2000) DEFAULT '' NOT NULL,
    auth_token text,

    -- connection health (written by McpToolProvider, read-only in TCA)
    connection_status varchar(20) DEFAULT 'unknown' NOT NULL,
    connection_checked int(11) unsigned DEFAULT 0 NOT NULL,
    connection_error text,

    PRIMARY KEY (uid),
    UNIQUE KEY server_key (server_key),
    KEY hidden_deleted (hidden, deleted)
);
