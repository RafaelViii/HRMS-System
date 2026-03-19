-- Biometric device ID to employee mapping
-- Allows linking biometric device user IDs to HRIS employee records
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'biometric_id_mapping') THEN
        CREATE TABLE biometric_id_mapping (
            id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            biometric_uid VARCHAR(50) NOT NULL,
            employee_id INT NOT NULL,
            device_name VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_biometric_employee FOREIGN KEY (employee_id)
                REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT uniq_biometric_uid_device UNIQUE (biometric_uid, device_name)
        );
        CREATE INDEX idx_biometric_uid ON biometric_id_mapping(biometric_uid);
        CREATE INDEX idx_biometric_employee ON biometric_id_mapping(employee_id);
    END IF;
END $$;
