USE tkif_scholarship;

ALTER TABLE applications
  ADD INDEX idx_app_tenant_student_id (tenant_id, student_id, id),
  ADD INDEX idx_app_tenant_student_status_created_sch (tenant_id, student_id, status, created_at, scholarship_id, id),
  ADD INDEX idx_app_tenant_student_sch_created (tenant_id, student_id, scholarship_id, created_at, id);
