USE tkif_scholarship;

ALTER TABLE blacklist_entries
  MODIFY COLUMN reason VARCHAR(255) NULL;
