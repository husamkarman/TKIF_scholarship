USE tkif_scholarship;

INSERT INTO tenants (name, code) VALUES ('TKIF Default Institution', 'TKIF001');

INSERT INTO users (tenant_id, full_name, email, password_hash, role) VALUES
(1, 'Student Demo', 'student@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'student'),
(1, 'Admin Demo', 'admin@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'admin'),
(1, 'Manager Demo', 'manager@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'manager'),
(1, 'IT Demo', 'it@tkif.local', '$2y$12$.ppKye16LWJ8B4We9HfmSu3Q4ET52kBasp09Msa0KTfHYnTg37djG', 'it');

UPDATE users SET register_id = id WHERE register_id IS NULL;

INSERT INTO phone_country_codes (iso2, country_name, dial_code, min_length, max_length, regex_pattern, is_default, is_active, sort_order) VALUES
('TR', 'Turkey', '+90', 10, 10, '/^[0-9]{10}$/', 1, 1, 1),
('AE', 'United Arab Emirates', '+971', 9, 9, '/^[0-9]{9}$/', 0, 1, 2),
('SA', 'Saudi Arabia', '+966', 9, 9, '/^[0-9]{9}$/', 0, 1, 3),
('EG', 'Egypt', '+20', 10, 10, '/^[0-9]{10}$/', 0, 1, 4),
('QA', 'Qatar', '+974', 8, 8, '/^[0-9]{8}$/', 0, 1, 5),
('KW', 'Kuwait', '+965', 8, 8, '/^[0-9]{8}$/', 0, 1, 6),
('BH', 'Bahrain', '+973', 8, 8, '/^[0-9]{8}$/', 0, 1, 7),
('OM', 'Oman', '+968', 8, 8, '/^[0-9]{8}$/', 0, 1, 8),
('JO', 'Jordan', '+962', 9, 9, '/^[0-9]{9}$/', 0, 1, 9),
('LB', 'Lebanon', '+961', 7, 8, '/^[0-9]{7,8}$/', 0, 1, 10),
('IQ', 'Iraq', '+964', 10, 10, '/^[0-9]{10}$/', 0, 1, 11),
('US', 'United States', '+1', 10, 10, '/^[0-9]{10}$/', 0, 1, 12),
('GB', 'United Kingdom', '+44', 10, 10, '/^[0-9]{10}$/', 0, 1, 13),
('DE', 'Germany', '+49', 10, 11, '/^[0-9]{10,11}$/', 0, 1, 14),
('FR', 'France', '+33', 9, 9, '/^[0-9]{9}$/', 0, 1, 15),
('ES', 'Spain', '+34', 9, 9, '/^[0-9]{9}$/', 0, 1, 16),
('IT', 'Italy', '+39', 9, 10, '/^[0-9]{9,10}$/', 0, 1, 17),
('NL', 'Netherlands', '+31', 9, 9, '/^[0-9]{9}$/', 0, 1, 18),
('BE', 'Belgium', '+32', 8, 9, '/^[0-9]{8,9}$/', 0, 1, 19),
('SE', 'Sweden', '+46', 7, 9, '/^[0-9]{7,9}$/', 0, 1, 20),
('NO', 'Norway', '+47', 8, 8, '/^[0-9]{8}$/', 0, 1, 21),
('DK', 'Denmark', '+45', 8, 8, '/^[0-9]{8}$/', 0, 1, 22),
('FI', 'Finland', '+358', 8, 10, '/^[0-9]{8,10}$/', 0, 1, 23),
('CH', 'Switzerland', '+41', 9, 9, '/^[0-9]{9}$/', 0, 1, 24),
('AT', 'Austria', '+43', 10, 11, '/^[0-9]{10,11}$/', 0, 1, 25),
('RU', 'Russia', '+7', 10, 10, '/^[0-9]{10}$/', 0, 1, 26),
('UA', 'Ukraine', '+380', 9, 9, '/^[0-9]{9}$/', 0, 1, 27),
('IN', 'India', '+91', 10, 10, '/^[0-9]{10}$/', 0, 1, 28),
('PK', 'Pakistan', '+92', 10, 10, '/^[0-9]{10}$/', 0, 1, 29),
('BD', 'Bangladesh', '+880', 10, 10, '/^[0-9]{10}$/', 0, 1, 30),
('CN', 'China', '+86', 11, 11, '/^[0-9]{11}$/', 0, 1, 31),
('JP', 'Japan', '+81', 10, 10, '/^[0-9]{10}$/', 0, 1, 32),
('KR', 'South Korea', '+82', 9, 10, '/^[0-9]{9,10}$/', 0, 1, 33),
('MY', 'Malaysia', '+60', 9, 10, '/^[0-9]{9,10}$/', 0, 1, 34),
('SG', 'Singapore', '+65', 8, 8, '/^[0-9]{8}$/', 0, 1, 35),
('ID', 'Indonesia', '+62', 9, 12, '/^[0-9]{9,12}$/', 0, 1, 36),
('TH', 'Thailand', '+66', 9, 9, '/^[0-9]{9}$/', 0, 1, 37),
('AU', 'Australia', '+61', 9, 9, '/^[0-9]{9}$/', 0, 1, 38),
('NZ', 'New Zealand', '+64', 8, 9, '/^[0-9]{8,9}$/', 0, 1, 39),
('BR', 'Brazil', '+55', 10, 11, '/^[0-9]{10,11}$/', 0, 1, 40),
('MX', 'Mexico', '+52', 10, 10, '/^[0-9]{10}$/', 0, 1, 41),
('AR', 'Argentina', '+54', 10, 10, '/^[0-9]{10}$/', 0, 1, 42),
('CL', 'Chile', '+56', 9, 9, '/^[0-9]{9}$/', 0, 1, 43),
('ZA', 'South Africa', '+27', 9, 9, '/^[0-9]{9}$/', 0, 1, 44),
('NG', 'Nigeria', '+234', 10, 10, '/^[0-9]{10}$/', 0, 1, 45),
('KE', 'Kenya', '+254', 9, 9, '/^[0-9]{9}$/', 0, 1, 46),
('MA', 'Morocco', '+212', 9, 9, '/^[0-9]{9}$/', 0, 1, 47),
('TN', 'Tunisia', '+216', 8, 8, '/^[0-9]{8}$/', 0, 1, 48),
('DZ', 'Algeria', '+213', 9, 9, '/^[0-9]{9}$/', 0, 1, 49);

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
