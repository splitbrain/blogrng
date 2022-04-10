CREATE TABLE suggestions
(
    feedid    TEXT    NOT NULL PRIMARY KEY,
    feedurl   TEXT    NOT NULL,
    feedtitle TEXT    NOT NULL,
    homepage  TEXT    NOT NULL DEFAULT '',
    added     INTEGER NOT NULL DEFAULT 0
);
