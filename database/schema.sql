CREATE TABLE settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    log_root TEXT NOT NULL DEFAULT '/var/logs-nginx',
    report_timezone TEXT NOT NULL DEFAULT 'Asia/Yekaterinburg',
    digest_directory TEXT NOT NULL DEFAULT '/var/www/checkai.analitica-soft.ru/digests',
    daily_digest INTEGER NOT NULL DEFAULT 0 CHECK (daily_digest IN (0, 1))
);

INSERT INTO settings (id) VALUES (1);

CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    domain TEXT NOT NULL COLLATE NOCASE UNIQUE,
    log_path TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1))
);

CREATE TABLE companies (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1))
);

CREATE TABLE bot_types (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE
);

CREATE TABLE bots (
    id INTEGER PRIMARY KEY,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    bot_type_id INTEGER NOT NULL REFERENCES bot_types(id),
    name TEXT NOT NULL COLLATE NOCASE,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1)),
    ip_updated_at TEXT,
    UNIQUE (company_id, name)
);

INSERT INTO bot_types (id, name) VALUES
    (1, 'Внутреннее обучение'),
    (2, 'Поисковые сканеры'),
    (3, 'Пользовательские запросы'),
    (4, 'Прочее');

CREATE TABLE bot_signatures (
    id INTEGER PRIMARY KEY,
    bot_id INTEGER NOT NULL REFERENCES bots(id),
    pattern TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(trim(pattern)) > 0),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1))
);

CREATE INDEX bots_type ON bots (bot_type_id);
CREATE INDEX bot_signatures_bot ON bot_signatures (bot_id);

CREATE TABLE requests (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL REFERENCES sites(id),
    bot_id INTEGER NOT NULL REFERENCES bots(id),
    requested_at_utc TEXT NOT NULL,
    method TEXT NOT NULL,
    request_path TEXT NOT NULL,
    http_status INTEGER NOT NULL CHECK (http_status BETWEEN 100 AND 599)
);

CREATE INDEX requests_time ON requests (requested_at_utc);
CREATE INDEX requests_site_time ON requests (site_id, requested_at_utc);
CREATE INDEX requests_bot_time ON requests (bot_id, requested_at_utc);

CREATE TABLE log_cursors (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL REFERENCES sites(id),
    device INTEGER NOT NULL,
    inode INTEGER NOT NULL,
    byte_offset INTEGER NOT NULL CHECK (byte_offset >= 0),
    updated_at_utc TEXT NOT NULL,
    UNIQUE (site_id, device, inode)
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    login TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    created_at_utc TEXT NOT NULL,
    needs_password INTEGER NOT NULL DEFAULT 0 CHECK (needs_password IN (0, 1)),
    disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1))
);
