USE tkif_scholarship;

INSERT INTO tenants (name, code) VALUES ('TKIF Default Institution', 'TKIF001');

INSERT INTO users (tenant_id, full_name, email, password_hash, role) VALUES
(1, 'Student Demo', 'student@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'student'),
(1, 'Admin Demo', 'admin@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'admin'),
(1, 'Manager Demo', 'manager@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'manager'),
(1, 'IT Demo', 'it@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'it');

INSERT INTO scholarships (tenant_id, title, description, status, form_schema_json, created_by)
VALUES (
  1,
  'TKIF Merit Scholarship',
  'Prototype scholarship for MVP testing',
  'published',
  JSON_ARRAY(
    JSON_OBJECT('name','full_name','label','Full Name','type','text','required',true),
    JSON_OBJECT('name','gpa','label','GPA','type','number','required',true),
    JSON_OBJECT('name','statement','label','Motivation Statement','type','textarea','required',true)
  ),
  2
);
