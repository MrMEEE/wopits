--
-- Create wopits tables for PostgreSQL.
--

DROP TYPE IF EXISTS enum_type CASCADE;
CREATE TYPE enum_type AS ENUM ('col', 'row');

DROP TYPE IF EXISTS enum_item CASCADE;
CREATE TYPE enum_item AS ENUM ('wall', 'wall-delete', 'cell', 'header', 'postit', 'group');

DROP TABLE IF EXISTS users CASCADE;
CREATE TABLE users
(
  id SERIAL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  username VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  fullname VARCHAR(255) NOT NULL,
  searchdata VARCHAR(1024) NOT NULL,
  creationdate INTEGER NOT NULL,
  updatedate INTEGER NOT NULL,
  lastconnectiondate INTEGER NOT NULL,
  about VARCHAR(2000),
  picture VARCHAR(2000),
  filetype VARCHAR(50),
  filesize INTEGER,
  settings VARCHAR(2000) NOT NULL DEFAULT '{}'
);
CREATE UNIQUE INDEX "users-email-uidx" ON users (email);
CREATE UNIQUE INDEX "users-username-uidx" ON users (username);
CREATE INDEX "users-password-idx" ON users (password);
CREATE INDEX "users-searchdata-idx" ON users (searchdata);

DROP TABLE IF EXISTS users_tokens CASCADE;
CREATE TABLE users_tokens
(
  token CHAR(80) NOT NULL PRIMARY KEY,
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  creationdate INTEGER NOT NULL,
  expiredate INTEGER
);
CREATE INDEX "users_tokens-expiredate-idx" ON users_tokens (expiredate);

DROP TABLE IF EXISTS walls CASCADE;
CREATE TABLE walls
(
  id SERIAL PRIMARY KEY,
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  width SMALLINT NOT NULL,
  creationdate INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL,
  description VARCHAR(2000)
);
CREATE INDEX "walls-name-idx" ON walls (name);
CREATE INDEX "walls-creationdate-idx" ON walls (creationdate);

DROP TABLE IF EXISTS groups CASCADE;
CREATE TABLE groups
(
  id SERIAL PRIMARY KEY,
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  walls_id INTEGER REFERENCES walls(id) ON DELETE CASCADE,
  type SMALLINT NOT NULL, -- dedicated(1), generic(2)
  name VARCHAR(255) NOT NULL,
  description VARCHAR(2000),
  userscount SMALLINT NOT NULL DEFAULT 0
);
CREATE INDEX "groups-name:users_id-uidx" ON groups (name, users_id);
CREATE INDEX "groups-type-idx" ON groups (type);

DROP TABLE IF EXISTS users_groups CASCADE;
CREATE TABLE users_groups
(
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  groups_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX "users_groups-users_id:groups_id-uidx"
  ON users_groups (users_id, groups_id);

DROP TABLE IF EXISTS walls_groups CASCADE;
CREATE TABLE walls_groups
(
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  groups_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  -- ADMIN(1), RW(2), RO(3)
  access SMALLINT NOT NULL
);
CREATE INDEX "walls_groups-access-idx" ON walls_groups (access);

DROP TABLE IF EXISTS _perf_walls_users CASCADE;
CREATE TABLE _perf_walls_users
(
  groups_id INTEGER REFERENCES groups(id) ON DELETE CASCADE,
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  -- ADMIN(1), RW(2), RO(3)
  access SMALLINT NOT NULL
);
CREATE INDEX "_perf_walls_users-access-idx" ON _perf_walls_users (access);

DROP TABLE IF EXISTS headers CASCADE;
CREATE TABLE headers
(
  id SERIAL PRIMARY KEY,
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  type enum_type NOT NULL,
  "order" SMALLINT NOT NULL,
  height SMALLINT NOT NULL,
  width SMALLINT,
  title VARCHAR(255),
  picture VARCHAR(2000),
  filetype VARCHAR(50),
  filesize INTEGER
);
CREATE INDEX "headers-type:order-idx" ON headers (type, "order");

DROP TABLE IF EXISTS cells CASCADE;
CREATE TABLE cells
(
  id SERIAL PRIMARY KEY,
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  width SMALLINT NOT NULL,
  height SMALLINT NOT NULL,
  row SMALLINT NOT NULL,
  col SMALLINT NOT NULL
);
CREATE INDEX "cells-row-idx" ON cells (row);
CREATE INDEX "cells-col-idx" ON cells (col);

DROP TABLE IF EXISTS postits CASCADE;
CREATE TABLE postits
(
  id SERIAL PRIMARY KEY,
  cells_id INTEGER NOT NULL REFERENCES cells(id) ON DELETE CASCADE,
  width SMALLINT NOT NULL,
  height SMALLINT NOT NULL,
  top SMALLINT NOT NULL,
  "left" SMALLINT NOT NULL,
  creationdate INTEGER NOT NULL,
  attachmentscount SMALLINT NOT NULL DEFAULT 0,
  classcolor VARCHAR(25),
  title VARCHAR(255),
  content TEXT,
  tags VARCHAR(255),
  deadline INTEGER,
  timezone VARCHAR (30),
  obsolete SMALLINT NOT NULL DEFAULT 0
);

DROP TABLE IF EXISTS postits_plugs CASCADE;
CREATE TABLE postits_plugs
(
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  start INTEGER NOT NULL REFERENCES postits(id) ON DELETE CASCADE,
  "end" INTEGER NOT NULL REFERENCES postits(id) ON DELETE CASCADE,
  label VARCHAR(255),
  PRIMARY KEY (walls_id, start, "end")
);

DROP TABLE IF EXISTS postits_attachments CASCADE;
CREATE TABLE postits_attachments
(
  id SERIAL PRIMARY KEY,
  postits_id INTEGER NOT NULL REFERENCES postits(id) ON DELETE CASCADE,
  -- Not a foreign key, just a helper
  walls_id INTEGER NOT NULL,
  -- Not a foreign key, just a helper
  users_id INTEGER,
  type VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  link VARCHAR(2000) NOT NULL,
  size INTEGER NOT NULL,
  creationdate INTEGER NOT NULL
);
CREATE INDEX "postits_attachments-creationdate:name-idx"
  ON postits_attachments (creationdate, name);

DROP TABLE IF EXISTS postits_pictures CASCADE;
CREATE TABLE postits_pictures
(
  id SERIAL PRIMARY KEY,
  postits_id INTEGER NOT NULL REFERENCES postits(id) ON DELETE CASCADE,
  -- Not a foreign key, just a helper
  walls_id INTEGER NOT NULL,
  -- Not a foreign key, just a helper
  users_id INTEGER,
  type VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  link VARCHAR(2000) NOT NULL,
  size INTEGER NOT NULL,
  creationdate INTEGER NOT NULL
);
CREATE INDEX "postits_pictures-link-idx" ON postits_pictures (link);

DROP TABLE IF EXISTS edit_queue CASCADE;
CREATE TABLE edit_queue
(
  item_id INTEGER NOT NULL,
  users_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  walls_id INTEGER NOT NULL REFERENCES walls(id) ON DELETE CASCADE,
  session_id INTEGER NOT NULL,
  item enum_item NOT NULL
);
CREATE INDEX "edit_queue-session_id-idx" ON edit_queue (session_id);
CREATE INDEX "edit_queue-item:item_id-idx" ON edit_queue (item, item_id);