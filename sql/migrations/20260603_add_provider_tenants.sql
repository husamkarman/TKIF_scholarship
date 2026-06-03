USE tkif_scholarship;

INSERT INTO tenants (name, code, is_active)
SELECT 'TKIF Microsoft Institution', 'TKIFMS001', 1
WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE code = 'TKIFMS001');

INSERT INTO tenants (name, code, is_active)
SELECT 'TKIF Google Institution', 'TKIFGO001', 1
WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE code = 'TKIFGO001');
