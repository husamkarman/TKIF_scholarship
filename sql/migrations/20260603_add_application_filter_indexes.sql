USE tkif_scholarship;

SET @has_idx_app_tenant_student_id := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'applications'
    AND index_name = 'idx_app_tenant_student_id'
);
SET @sql_idx_app_tenant_student_id := IF(
  @has_idx_app_tenant_student_id = 0,
  'ALTER TABLE applications ADD INDEX idx_app_tenant_student_id (tenant_id, student_id, id)',
  'SELECT 1'
);
PREPARE stmt_idx_app_tenant_student_id FROM @sql_idx_app_tenant_student_id;
EXECUTE stmt_idx_app_tenant_student_id;
DEALLOCATE PREPARE stmt_idx_app_tenant_student_id;

SET @has_idx_app_tenant_student_status_created_sch := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'applications'
    AND index_name = 'idx_app_tenant_student_status_created_sch'
);
SET @sql_idx_app_tenant_student_status_created_sch := IF(
  @has_idx_app_tenant_student_status_created_sch = 0,
  'ALTER TABLE applications ADD INDEX idx_app_tenant_student_status_created_sch (tenant_id, student_id, status, created_at, scholarship_id, id)',
  'SELECT 1'
);
PREPARE stmt_idx_app_tenant_student_status_created_sch FROM @sql_idx_app_tenant_student_status_created_sch;
EXECUTE stmt_idx_app_tenant_student_status_created_sch;
DEALLOCATE PREPARE stmt_idx_app_tenant_student_status_created_sch;

SET @has_idx_app_tenant_student_sch_created := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'applications'
    AND index_name = 'idx_app_tenant_student_sch_created'
);
SET @sql_idx_app_tenant_student_sch_created := IF(
  @has_idx_app_tenant_student_sch_created = 0,
  'ALTER TABLE applications ADD INDEX idx_app_tenant_student_sch_created (tenant_id, student_id, scholarship_id, created_at, id)',
  'SELECT 1'
);
PREPARE stmt_idx_app_tenant_student_sch_created FROM @sql_idx_app_tenant_student_sch_created;
EXECUTE stmt_idx_app_tenant_student_sch_created;
DEALLOCATE PREPARE stmt_idx_app_tenant_student_sch_created;
