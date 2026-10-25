CREATE TABLE tx_alttexttest_item (
    title varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT 0 NOT NULL,
    teaser_image int(11) unsigned DEFAULT 0 NOT NULL,
    content_elements int(11) unsigned DEFAULT 0 NOT NULL
);

CREATE TABLE tx_alttexttest_child (
    title varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT 0 NOT NULL,
    parent_content int(11) unsigned DEFAULT 0 NOT NULL
);

CREATE TABLE tt_content (
    tx_alttexttest_children int(11) unsigned DEFAULT 0 NOT NULL,
    tx_alttexttest_item int(11) unsigned DEFAULT 0 NOT NULL
);
