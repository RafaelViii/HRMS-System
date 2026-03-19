-- ============================================================
-- RA 10173 Compliance Tables
-- Data correction requests, privacy consents, BIR report logs
-- ============================================================

-- Data Correction Requests (Right to Rectification)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'data_correction_requests') THEN
        CREATE TABLE data_correction_requests (
            id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            employee_id     BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
            requested_by    BIGINT NOT NULL REFERENCES users(id),
            category        VARCHAR(50) NOT NULL DEFAULT 'personal_info',
            field_name      VARCHAR(100) NOT NULL,
            current_value   TEXT,
            requested_value TEXT NOT NULL,
            reason          TEXT,
            status          VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewed_by     BIGINT REFERENCES users(id),
            reviewed_at     TIMESTAMP,
            review_notes    TEXT,
            created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
        );

        CREATE INDEX idx_dcr_employee ON data_correction_requests(employee_id);
        CREATE INDEX idx_dcr_status   ON data_correction_requests(status);
        CREATE INDEX idx_dcr_created  ON data_correction_requests(created_at DESC);

        COMMENT ON TABLE data_correction_requests IS 'RA 10173 Right to Rectification — employee data correction requests';
    END IF;
END $$;

-- Privacy Consents (Consent Management)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'privacy_consents') THEN
        CREATE TABLE privacy_consents (
            id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            consent_type  VARCHAR(50) NOT NULL,
            consented     BOOLEAN NOT NULL DEFAULT FALSE,
            consented_at  TIMESTAMP,
            withdrawn_at  TIMESTAMP,
            ip_address    VARCHAR(45),
            user_agent    TEXT,
            version       VARCHAR(20) NOT NULL DEFAULT '1.0',
            created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(user_id, consent_type)
        );

        CREATE INDEX idx_pc_user    ON privacy_consents(user_id);
        CREATE INDEX idx_pc_type    ON privacy_consents(consent_type);
        CREATE INDEX idx_pc_consent ON privacy_consents(consented);

        COMMENT ON TABLE privacy_consents IS 'RA 10173 consent records — tracks user privacy consent history';
    END IF;
END $$;

-- Data Erasure Requests (Right to Erasure / Right to be Forgotten)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'data_erasure_requests') THEN
        CREATE TABLE data_erasure_requests (
            id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            employee_id     BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
            requested_by    BIGINT NOT NULL REFERENCES users(id),
            scope           VARCHAR(50) NOT NULL DEFAULT 'full',
            reason          TEXT NOT NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewed_by     BIGINT REFERENCES users(id),
            reviewed_at     TIMESTAMP,
            review_notes    TEXT,
            executed_at     TIMESTAMP,
            executed_by     BIGINT REFERENCES users(id),
            anonymized_fields JSONB DEFAULT '[]'::jsonb,
            created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
        );

        CREATE INDEX idx_der_employee ON data_erasure_requests(employee_id);
        CREATE INDEX idx_der_status   ON data_erasure_requests(status);
        CREATE INDEX idx_der_created  ON data_erasure_requests(created_at DESC);

        COMMENT ON TABLE data_erasure_requests IS 'RA 10173 Right to Erasure — employee data deletion/anonymization requests';
    END IF;
END $$;

-- BIR Report Generation Log
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'bir_report_logs') THEN
        CREATE TABLE bir_report_logs (
            id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            report_type   VARCHAR(50) NOT NULL,
            period_start  DATE NOT NULL,
            period_end    DATE NOT NULL,
            parameters    JSONB DEFAULT '{}'::jsonb,
            generated_by  BIGINT NOT NULL REFERENCES users(id),
            row_count     INT DEFAULT 0,
            file_path     TEXT,
            created_at    TIMESTAMP NOT NULL DEFAULT NOW()
        );

        CREATE INDEX idx_brl_type    ON bir_report_logs(report_type);
        CREATE INDEX idx_brl_period  ON bir_report_logs(period_start, period_end);
        CREATE INDEX idx_brl_created ON bir_report_logs(created_at DESC);

        COMMENT ON TABLE bir_report_logs IS 'BIR report generation audit trail';
    END IF;
END $$;
