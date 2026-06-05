USE tkif_scholarship;

ALTER TABLE users
  ADD COLUMN register_id BIGINT UNSIGNED NULL AFTER id,
  ADD UNIQUE KEY uq_users_register_id (register_id);

UPDATE users
SET register_id = id
WHERE register_id IS NULL;USE tkif_scholarship;

ALTER TABLE users
  ADD COLUMN register_id BIGINT UNSIGNED GENERATED ALWAYS AS (id) STORED AFTER id;